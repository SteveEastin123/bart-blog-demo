#Requires -Version 7.2
param([string]$PythonExecutable = 'python')

$ErrorActionPreference = 'Stop'
$PSNativeCommandUseErrorActionPreference = $false
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$compose = Join-Path $PSScriptRoot 'compose.render-test.yaml'
$runId = [guid]::NewGuid().ToString('N')
$project = 'ehrman-render-check-' + $runId.Substring(0, 12)
$work = Join-Path $repo ".tmp/render-rebuild/$runId"
$null = New-Item -ItemType Directory -Path $work
$listener = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback, 0)
try { $listener.Start(); $port = $listener.LocalEndpoint.Port } finally { $listener.Stop() }
$environment = @{
    COMPOSE_PROJECT_NAME = $project
    WORDPRESS_PRODUCTION_TEST_PORT = [string]$port
    WORDPRESS_SITE_URL = "http://localhost:$port"
    MYSQL_DATABASE = 'ehrman_render_rehearsal'
    MYSQL_USER = 'ehrman'
    MYSQL_PASSWORD = 'disposable-render-rehearsal-only'
    MYSQL_ROOT_PASSWORD = 'disposable-render-root-only'
}
$saved = @{}
foreach ($name in $environment.Keys) {
    $saved[$name] = [Environment]::GetEnvironmentVariable($name)
    [Environment]::SetEnvironmentVariable($name, $environment[$name])
}

function Invoke-RehearsalDocker {
    param([string[]]$Arguments)
    & docker compose -p $project -f $compose @Arguments
    if ($LASTEXITCODE -ne 0) { throw "Render rehearsal failed: $($Arguments[0])" }
}

function Wait-RehearsalWeb {
    for ($attempt = 0; $attempt -lt 60; $attempt++) {
        try {
            $null = Invoke-WebRequest "http://127.0.0.1:$port/healthz" -TimeoutSec 5
            return
        } catch { Start-Sleep -Seconds 2 }
    }
    throw 'The Render-port WordPress service did not become healthy.'
}

$attempted = $false
try {
    $attempted = $true
    Invoke-RehearsalDocker @('up', '-d', '--no-build')
    Wait-RehearsalWeb
    Invoke-RehearsalDocker @('exec', '-T', 'wordpress', 'php', '-r',
        '$db = new mysqli("database", getenv("WORDPRESS_DB_USER"), getenv("WORDPRESS_DB_PASSWORD"), getenv("WORDPRESS_DB_NAME"), 10000); if (stripos($db->server_info, "MariaDB") === false || $db->query("SHOW TABLES")->num_rows !== 0) { throw new RuntimeException("Expected a fresh MariaDB database."); } echo "Fresh MariaDB on port 10000 confirmed.";')
    Invoke-RehearsalDocker @('exec', '-T', 'wordpress', 'initialize-ehrman-wordpress')
    Invoke-RehearsalDocker @('exec', '-T', 'wordpress', 'wp', 'eval',
        'if (!EhrmanBlogDiscovery\Post_Ingestion_Settings::database_is_authoritative()) { throw new RuntimeException("Expected database-managed ingestion."); } echo "Database-managed ingestion enabled in isolated rehearsal.";', '--allow-root')
    Invoke-RehearsalDocker @('exec', '-T', 'wordpress', 'wp', 'eval',
        'if (!WP_HTTP_BLOCK_EXTERNAL || !DISABLE_WP_CRON || EhrmanBlogDiscovery\OpenAI_Credentials::api_key() !== "regression-placeholder-not-a-real-api-key") { throw new RuntimeException("API isolation failed."); } $s = (new EhrmanBlogDiscovery\Semantic_Index_Service())->status(); if (!$s["ready"] || $s["missing"] || $s["stale"] || $s["obsolete"]) { throw new RuntimeException("Bundled vectors are incomplete."); } echo wp_json_encode($s);', '--allow-root')
    & (Join-Path $PSScriptRoot 'verify-production-test.ps1') -Port $port -ComposeFile $compose -PythonExecutable $PythonExecutable
    & $PythonExecutable -B (Join-Path $repo 'scripts/check_wordpress_landing.py') --base-url "http://localhost:$port"
    if ($LASTEXITCODE -ne 0) { throw 'Landing-page verification failed.' }

    & (Join-Path $PSScriptRoot 'export-vector-package.ps1') -ComposeFile $compose -OutputPath (Join-Path $work 'approved-vectors.jsonl.gz')
    $approvedHash = (Get-FileHash -LiteralPath (Join-Path $work 'approved-vectors.jsonl.gz')).Hash
    Invoke-RehearsalDocker @('cp', (Join-Path $PSScriptRoot 'tests/render-managed-data-fixture.php'), 'wordpress:/tmp/render-managed-data-fixture.php')
    Invoke-RehearsalDocker @('exec', '-T', 'wordpress', 'wp', 'eval-file', '/tmp/render-managed-data-fixture.php', '--allow-root')
    $rejected = $false
    try {
        & (Join-Path $PSScriptRoot 'export-vector-package.ps1') -ComposeFile $compose -OutputPath (Join-Path $work 'approved-vectors.jsonl.gz')
    } catch {
        if ($_.Exception.Message -notlike 'Handoff validation failed.*') { throw }
        $rejected = $true
    }
    if (-not $rejected -or (Get-FileHash -LiteralPath (Join-Path $work 'approved-vectors.jsonl.gz')).Hash -ne $approvedHash) {
        throw 'A database-only test post was accepted or changed the approved package.'
    }
    & (Join-Path $PSScriptRoot 'snapshot-database.ps1') -ComposeFile $compose -OutputPath (Join-Path $work 'before.json')

    # Recreate containers without deleting volumes or importing data again.
    Invoke-RehearsalDocker @('up', '-d', '--no-build', '--force-recreate')
    Wait-RehearsalWeb
    # Re-running initialization must also preserve the database, not just restarting containers.
    Invoke-RehearsalDocker @('exec', '-T', 'wordpress', 'initialize-ehrman-wordpress')
    $status = Invoke-RestMethod "http://localhost:$port/wp-json/ehrman-discovery/v1/status"
    if (-not $status.database_connected -or $status.import_state -ne 'complete') { throw 'Data did not survive container recreation.' }
    Invoke-RehearsalDocker @('exec', '-T', 'wordpress', 'wp', 'eval',
        'if (home_url() !== getenv("WORDPRESS_SITE_URL")) { throw new RuntimeException("The demo URL changed."); } echo "URL preserved after recreation.";', '--allow-root')
    & (Join-Path $PSScriptRoot 'snapshot-database.ps1') -ComposeFile $compose -OutputPath (Join-Path $work 'after.json')
    & (Join-Path $PSScriptRoot 'compare-database-snapshots.ps1') -Before (Join-Path $work 'before.json') -After (Join-Path $work 'after.json') -DiscoveryOnly -IgnoreAutoIncrement
    Write-Output "Render rebuild rehearsal passed: $project; evidence: $work. No Render resources or paid APIs were used."
} finally {
    try {
        if ($attempted) { Invoke-RehearsalDocker @('stop') }
    } finally {
        foreach ($name in $saved.Keys) { [Environment]::SetEnvironmentVariable($name, $saved[$name]) }
    }
}
