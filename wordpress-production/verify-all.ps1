#Requires -Version 7.2
param(
    [string]$PythonExecutable = 'python',
    [string]$NodeExecutable = 'node',
    [string]$PhpExecutable = '',
    [string]$BaselinePath = '.tmp/test-tools-approved-baseline.json'
)

$ErrorActionPreference = 'Stop'
$PSNativeCommandUseErrorActionPreference = $false
Import-Module (Join-Path $PSScriptRoot 'regression-report.psm1') -Force
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$compose = Join-Path $PSScriptRoot 'compose.local.yaml'
$runId = [guid]::NewGuid().ToString('N')
$project = 'ehrman-regression-' + $runId.Substring(0, 12)
$work = Join-Path $repo ".tmp/full-regression/$runId"
$steps = @('preflight', 'runner-tests', 'canonical-data', 'landing-assets', 'frontend', 'developer-unit-tests',
    'phpcs-core', 'phpstan-core', 'phpcs-reviewer', 'phpstan-reviewer', 'phpcs-developer', 'phpstan-developer',
    'isolated-demo', 'database-before', 'production-regressions', 'developer-delivery', 'initial-installation',
    'browser-frontend-assets-ui', 'browser-search-page-ui', 'browser-analytics-dashboard-ui',
    'browser-post-ingestion-page-ui', 'browser-ask-ai-retirement-ui', 'production-only-browser',
    'served-landing', 'data-integrity', 'backup-restore', 'diff-check', 'cleanup')
$report = New-RegressionReport $work $steps ([ordered]@{ project = $project; database = 'MariaDB (compose.local.yaml)'; baseline = $BaselinePath })
$environment = @{
    COMPOSE_PROJECT_NAME = $project
    WORDPRESS_PRODUCTION_TEST_BIND = '127.0.0.1'
    WORDPRESS_PRODUCTION_TEST_PORT = ''
    WORDPRESS_SITE_URL = ''
    EHRMAN_MIGRATION_ISOLATED = '1'
    # Enable the UI for mocked browser requests; outbound WordPress HTTP stays blocked.
    OPENAI_API_KEY = 'regression-placeholder-not-a-real-api-key'
    EHRMAN_DISCOVERY_OPENAI_API_KEY = ''
    EHRMAN_INGESTION_OPENAI_API_KEY = ''
    EHRMAN_DISCOVERY_POST_SOURCE = 'json'
    EHRMAN_DISCOVERY_AI_RESULT_GROUPING = 'tiers'
    WORDPRESS_ADMIN_USER = 'regression-admin'
    WORDPRESS_ADMIN_PASSWORD = 'disposable-regression-only'
    WORDPRESS_ADMIN_EMAIL = 'regression@example.test'
    MYSQL_DATABASE = 'ehrman_regression'
    MYSQL_USER = 'ehrman'
    MYSQL_PASSWORD = 'disposable-regression-only'
    MYSQL_ROOT_PASSWORD = 'disposable-root-regression-only'
    EHRMAN_TEST_BASE_URL = ''
    EHRMAN_TEST_OUTPUT_DIR = (Join-Path $work 'browser')
}
$saved = @{}
$stackAttempted = $false
Push-Location $repo
try {
    Invoke-RegressionStep $report 'preflight' {
        # Require an approved reference. Never approve the current output automatically.
        $script:baseline = (Resolve-Path -LiteralPath $BaselinePath).Path
        $report.context.baseline = $baseline
        $report.context.baseline_sha256 = (Get-FileHash -LiteralPath $baseline -Algorithm SHA256).Hash
        if (-not $PhpExecutable) {
            $localPhp = Join-Path $repo '.tools/php-8.4/php.exe'
            $script:PhpExecutable = if (Test-Path -LiteralPath $localPhp) { $localPhp } else { 'php' }
        }
        foreach ($command in @($PythonExecutable, $NodeExecutable, $PhpExecutable, 'docker', 'git')) {
            $null = Get-Command $command -ErrorAction Stop
        }
        Invoke-RegressionCommand $PythonExecutable @('--version')
        Invoke-RegressionCommand $NodeExecutable @('--version')
        Invoke-RegressionCommand $PhpExecutable @('--version')
        Invoke-RegressionCommand docker @('info', '--format', '{{.ServerVersion}}')
        foreach ($dependency in @('node_modules/playwright-core/package.json', 'node_modules/esbuild/package.json',
            'wordpress-plugin/ehrman-blog-discovery/vendor/bin/phpcs', 'wordpress-plugin/ehrman-blog-discovery/vendor/bin/phpstan')) {
            if (-not (Test-Path -LiteralPath $dependency)) { throw "Missing dependency: $dependency. See the full-regression prerequisites." }
        }
        $report.context.commit = (& git rev-parse HEAD).Trim()
        if ($LASTEXITCODE -ne 0) { throw 'Could not identify the tested commit.' }
        $report.context.worktree_changes = @(& git status --short) -join '; '
        if ($LASTEXITCODE -ne 0) { throw 'Could not identify uncommitted changes.' }
        $listener = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback, 0)
        try { $listener.Start(); $script:port = $listener.LocalEndpoint.Port } finally { $listener.Stop() }
        $report.context.test_url = "http://localhost:$port"
    }
    Invoke-RegressionStep $report 'runner-tests' {
        & (Join-Path $PSScriptRoot 'tests/regression-runner-tests.ps1')
        Invoke-RegressionCommand $PythonExecutable @('-B', '-m', 'unittest', 'discover', '-s', 'wordpress-production/tests', '-p', 'test_*.py', '-v')
        Invoke-RegressionCommand $NodeExecutable @('--test', 'wordpress-production/tests/browser-config.test.cjs')
    }
    Invoke-RegressionStep $report 'canonical-data' {
        Invoke-RegressionCommand $PythonExecutable @('-B', 'scripts/validate_ehrman_search_demo.py', '--data-only')
    }
    Invoke-RegressionStep $report 'landing-assets' {
        Invoke-RegressionCommand $PythonExecutable @('-B', 'scripts/check_wordpress_landing.py')
    }
    Invoke-RegressionStep $report 'frontend' {
        Invoke-RegressionCommand $NodeExecutable @('scripts/build_wordpress_assets.cjs', '--check')
        Invoke-RegressionCommand $NodeExecutable @('--test', 'wordpress-production/tests/frontend-assets.test.cjs')
    }
    Invoke-RegressionStep $report 'developer-unit-tests' {
        Invoke-RegressionCommand $PythonExecutable @('-B', 'scripts/package_wordpress_tests.py', '--check')
        Invoke-RegressionCommand $PythonExecutable @('-B', '-m', 'unittest', 'discover', '-s', 'developer-tests/tests', '-v')
    }
    $packages = [ordered]@{ core = '.'; reviewer = '../../wordpress-demo-plugins/ehrman-reviewer-tools'; developer = '../../developer-tests' }
    Push-Location (Join-Path $repo 'wordpress-plugin/ehrman-blog-discovery')
    try {
        foreach ($name in $packages.Keys) {
            $config = $packages[$name]
            Invoke-RegressionStep $report "phpcs-$name" {
                Invoke-RegressionCommand $PhpExecutable @('vendor/bin/phpcs', "--standard=$config/phpcs.xml.dist")
            }
            Invoke-RegressionStep $report "phpstan-$name" {
                Invoke-RegressionCommand $PhpExecutable @('vendor/bin/phpstan', 'analyse', "--configuration=$config/phpstan.neon.dist", '--no-progress', '--memory-limit=1G')
            }
        }
    } finally { Pop-Location }

    $environment.WORDPRESS_PRODUCTION_TEST_PORT = [string]$port
    $environment.WORDPRESS_SITE_URL = "http://localhost:$port"
    $environment.EHRMAN_TEST_BASE_URL = "http://localhost:$port"
    foreach ($name in $environment.Keys) {
        $saved[$name] = [Environment]::GetEnvironmentVariable($name)
        [Environment]::SetEnvironmentVariable($name, $environment[$name])
    }
    Invoke-RegressionStep $report 'isolated-demo' {
        $script:stackAttempted = $true
        & (Join-Path $PSScriptRoot 'setup-production-test.ps1') -Port $port -ComposeFile $compose
        Invoke-RegressionCommand docker @('compose', '-p', $project, '-f', $compose, 'exec', '-T', 'wordpress', 'wp', 'eval',
            'if (!defined("DISABLE_WP_CRON") || !DISABLE_WP_CRON || !defined("WP_HTTP_BLOCK_EXTERNAL") || !WP_HTTP_BLOCK_EXTERNAL || getenv("OPENAI_API_KEY") !== "regression-placeholder-not-a-real-api-key" || getenv("EHRMAN_INGESTION_OPENAI_API_KEY")) { throw new RuntimeException("Regression isolation is not active."); } echo "Cron and external API calls disabled; only a nonfunctional test key is configured.";', '--allow-root')
        $status = Invoke-RestMethod "$($report.context.test_url)/wp-json/ehrman-discovery/v1/status"
        $report.context.plugin_version = $status.plugin_version
    }
    Invoke-RegressionStep $report 'database-before' {
        & (Join-Path $PSScriptRoot 'snapshot-database.ps1') -ComposeFile $compose -OutputPath (Join-Path $work 'database-before.json')
    }
    Invoke-RegressionStep $report 'production-regressions' {
        & (Join-Path $PSScriptRoot 'verify-production-test.ps1') -Port $port -ComposeFile $compose -PythonExecutable $PythonExecutable
    }
    Invoke-RegressionStep $report 'developer-delivery' {
        & (Join-Path $PSScriptRoot 'verify-developer-tests.ps1') -ComposeFile $compose -PythonExecutable $PythonExecutable -BaselinePath $baseline
    }
    Invoke-RegressionStep $report 'initial-installation' {
        & (Join-Path $PSScriptRoot 'verify-installation.ps1') -ComposeFile $compose -PythonExecutable $PythonExecutable -BaselinePath $baseline
    }
    foreach ($browser in @('frontend-assets-ui', 'search-page-ui', 'analytics-dashboard-ui', 'post-ingestion-page-ui', 'ask-ai-retirement-ui')) {
        Invoke-RegressionStep $report "browser-$browser" {
            Invoke-RegressionCommand $NodeExecutable @("wordpress-production/tests/$browser.cjs")
        }
    }
    Invoke-RegressionStep $report 'production-only-browser' {
        $env:EHRMAN_TEST_OUTPUT_DIR = Join-Path $work 'browser-production-only'
        & (Join-Path $PSScriptRoot 'verify-reviewer-isolation.ps1') -Port $port -ComposeFile $compose -BrowserChecks -NodeExecutable $NodeExecutable
    }
    Invoke-RegressionStep $report 'served-landing' {
        Invoke-RegressionCommand $PythonExecutable @('-B', 'scripts/check_wordpress_landing.py', '--base-url', $report.context.test_url)
    }
    Invoke-RegressionStep $report 'data-integrity' {
        & (Join-Path $PSScriptRoot 'snapshot-database.ps1') -ComposeFile $compose -OutputPath (Join-Path $work 'database-after.json')
        & (Join-Path $PSScriptRoot 'compare-database-snapshots.ps1') -Before (Join-Path $work 'database-before.json') -After (Join-Path $work 'database-after.json') -IgnoreAutoIncrement -DiscoveryOnly
        Invoke-RegressionCommand docker @('compose', '-p', $project, '-f', $compose, 'exec', '-T', 'wordpress', 'wp', 'eval',
            '$s = (new EhrmanBlogDiscovery\Semantic_Index_Service())->status(); if (!$s["ready"] || $s["missing"] || $s["stale"] || $s["obsolete"]) { throw new RuntimeException("Semantic index is incomplete."); } echo wp_json_encode($s);', '--allow-root')
    }
    Invoke-RegressionStep $report 'backup-restore' {
        Invoke-RegressionCommand docker @('compose', '-p', $project, '-f', $compose, 'stop', 'wordpress')
        $backup = & (Join-Path $PSScriptRoot 'backup-database.ps1') -ComposeFile $compose -OutputPath (Join-Path $work 'database-backup.sql')
        & (Join-Path $PSScriptRoot 'test-backup-restore.ps1') -ComposeFile $compose -BackupPath $backup
    }
    Invoke-RegressionStep $report 'diff-check' { Invoke-RegressionCommand git @('diff', '--check') }
} catch {
    Write-Warning "Regression stopped: $($_.Exception.Message)"
} finally {
    try {
        Invoke-RegressionStep $report 'cleanup' {
            if ($stackAttempted) { Invoke-RegressionCommand docker @('compose', '-p', $project, '-f', $compose, 'stop') }
            else { Write-Output 'No test containers were started.' }
        }
    } catch { Write-Warning "Test-stack cleanup failed: $($_.Exception.Message)" }
    foreach ($name in $saved.Keys) { [Environment]::SetEnvironmentVariable($name, $saved[$name]) }
    Pop-Location
    Complete-RegressionReport $report
    Write-Host "Regression $($report.status). Report: $(Join-Path $work 'report.txt')"
}
if ($report.status -ne 'passed') { throw 'Full regression failed. See the consolidated report.' }
