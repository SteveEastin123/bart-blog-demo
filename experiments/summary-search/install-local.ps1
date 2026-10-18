param()
$ErrorActionPreference = 'Stop'
$root = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '../..')).Path
function Invoke-Compose {
    param([string[]] $Arguments)
    & docker compose -f wordpress-production/compose.local.yaml @Arguments
    if ($LASTEXITCODE -ne 0) { throw "Local Docker command failed: $LASTEXITCODE" }
}
Push-Location $root
try {
    $homeUrl = (Invoke-Compose @('exec', '-T', 'wordpress', 'wp', 'option', 'get', 'home', '--allow-root', '--path=/var/www/html')).Trim()
    $environment = (Invoke-Compose @('exec', '-T', 'wordpress', 'wp', 'eval', 'echo wp_get_environment_type();', '--allow-root', '--path=/var/www/html')).Trim()
    if (([Uri]$homeUrl).Host -ne 'localhost' -or $environment -ne 'local') { throw 'Local experiment only.' }
    $directory = '/opt/ehrman-section-pilot/agent-summaries-2026-10-17'
    Invoke-Compose @('exec', '-T', 'wordpress', 'install', '-d', '-m', '0750', '-o', 'www-data', '-g', 'www-data', $directory)
    Invoke-Compose @('cp', 'experiments/summary-search/private/agent-summaries-2026-10-17/fixtures/.', "wordpress:$directory/")
    Invoke-Compose @('cp', 'experiments/summary-search/plugin/.', 'wordpress:/var/www/html/wp-content/plugins/ehrman-summary-pilot/')
    Invoke-Compose @('exec', '-T', 'wordpress', 'wp', 'plugin', 'activate', 'ehrman-summary-pilot', '--allow-root', '--path=/var/www/html')
    Invoke-Compose @('exec', '-T', '--user', 'www-data', 'wordpress', 'wp', 'eval-file', '/var/www/html/wp-content/plugins/ehrman-summary-pilot/tests.php', '--path=/var/www/html')
} finally { Pop-Location }
