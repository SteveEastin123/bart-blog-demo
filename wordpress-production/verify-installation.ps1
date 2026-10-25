param(
    [string]$ComposeFile = (Join-Path $PSScriptRoot 'compose.local.yaml'),
    [string]$PythonExecutable = 'python',
    [string]$BaselinePath = ''
)

$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$compose = (Resolve-Path -LiteralPath $ComposeFile).Path
$project = 'ehrman-install-check-' + [guid]::NewGuid().ToString('N').Substring(0, 12)
$listener = [System.Net.Sockets.TcpListener]::new([System.Net.IPAddress]::Loopback, 0)
$listener.Start()
$port = $listener.LocalEndpoint.Port
$listener.Stop()
$names = @('WORDPRESS_PRODUCTION_TEST_PORT', 'WORDPRESS_SITE_URL', 'EHRMAN_MIGRATION_ISOLATED', 'OPENAI_API_KEY', 'EHRMAN_INGESTION_OPENAI_API_KEY', 'EHRMAN_DISCOVERY_POST_SOURCE', 'COMPOSE_PROJECT_NAME')
$saved = @{}
foreach ($name in $names) { $saved[$name] = [Environment]::GetEnvironmentVariable($name) }
$env:WORDPRESS_PRODUCTION_TEST_PORT = [string]$port
$env:WORDPRESS_SITE_URL = "http://localhost:$port"
$env:EHRMAN_MIGRATION_ISOLATED = '1'
$env:OPENAI_API_KEY = ''
$env:EHRMAN_INGESTION_OPENAI_API_KEY = ''
$env:EHRMAN_DISCOVERY_POST_SOURCE = 'json'
$env:COMPOSE_PROJECT_NAME = $project

function Invoke-TestDocker {
    param([string[]]$Arguments)
    & docker compose -p $project -f $compose @Arguments
    if ($LASTEXITCODE -ne 0) { throw "Isolated installation test failed: $($Arguments[0])" }
}

try {
    Invoke-TestDocker @('up', '-d', '--no-build')
    $ready = $false
    for ($attempt = 0; $attempt -lt 60; $attempt++) {
        try {
            $null = Invoke-WebRequest -Uri "http://localhost:$port/healthz" -TimeoutSec 2
            $ready = $true
            break
        } catch { Start-Sleep -Seconds 2 }
    }
    if (-not $ready) { throw 'Isolated WordPress did not start.' }
    Invoke-TestDocker @('exec', '-T', 'wordpress', 'wp', 'core', 'install', "--url=http://localhost:$port", '--title=Ehrman installation verification', '--admin_user=installation-test', '--admin_password=disposable-installation-test-only', '--admin_email=installation@example.test', '--skip-email', '--allow-root')
    Invoke-TestDocker @('exec', '-T', 'wordpress', 'wp', 'rewrite', 'structure', '/%postname%/', '--allow-root')
    Invoke-TestDocker @('exec', '-T', 'wordpress', 'wp', 'plugin', 'activate', 'ehrman-blog-discovery', '--allow-root')
    Invoke-TestDocker @('cp', (Join-Path $repo 'wordpress-production/tests/install-data-tests.php'), 'wordpress:/tmp/install-data-tests.php')
    Invoke-TestDocker @('exec', '-T', '-e', 'EHRMAN_INSTALLATION_TEST=1', 'wordpress', 'wp', 'eval-file', '/tmp/install-data-tests.php', '--allow-root')
    Invoke-TestDocker @('exec', '-T', 'wordpress', 'wp', 'ehrman-discovery', 'install-data', '--data-dir=/opt/ehrman-import', '--validate-only', '--allow-root')
    Invoke-TestDocker @('exec', '-T', 'wordpress', 'wp', 'ehrman-discovery', 'install-data', '--data-dir=/opt/ehrman-import', '--replace-existing', '--allow-root')
    $second = & docker compose -p $project -f $compose exec -T wordpress wp ehrman-discovery install-data --data-dir=/opt/ehrman-import --allow-root 2>&1
    if ($LASTEXITCODE -ne 0 -or ($second -join "`n") -notmatch 'Already installed; data unchanged') { throw 'CLI repeat install was not a no-op.' }
    Write-Output $second
    Invoke-TestDocker @('exec', '-T', 'wordpress', 'wp', 'eval-file', '/opt/ehrman-tests/plugin-regression-tests.php', '--allow-root')
    & (Join-Path $PSScriptRoot 'verify-developer-tests.ps1') -ComposeFile $compose -PythonExecutable $PythonExecutable -BaselinePath $BaselinePath
    Write-Output "Isolated installation verified: $project; full package imported, repeated safely and regression-tested without API calls."
} finally {
    # Keep the isolated volumes as test evidence; never touch the demo's project or volumes.
    & docker compose -p $project -f $compose stop
    foreach ($name in $names) { [Environment]::SetEnvironmentVariable($name, $saved[$name]) }
}
