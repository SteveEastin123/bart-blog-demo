param([switch] $Disable)

$ErrorActionPreference = 'Stop'
$root = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '../../..')).Path

function Invoke-LocalCompose {
    param([string[]] $Arguments)
    & docker compose -f wordpress-production/compose.local.yaml @Arguments
    if ($LASTEXITCODE -ne 0) { throw "Local Docker command failed with exit code $LASTEXITCODE." }
}

Push-Location $root
try {
    $homeUrl = (Invoke-LocalCompose @('exec', '-T', 'wordpress', 'wp', 'option', 'get', 'home', '--allow-root', '--path=/var/www/html')).Trim()
    $environment = (Invoke-LocalCompose @('exec', '-T', 'wordpress', 'wp', 'eval', 'echo wp_get_environment_type();', '--allow-root', '--path=/var/www/html')).Trim()
    if (([Uri]$homeUrl).Host -ne 'localhost' -or $environment -ne 'local') {
        throw 'Guest section search may only be installed in the localhost local environment.'
    }
    Invoke-LocalCompose @('exec', '-T', 'wordpress', 'wp', 'plugin', 'is-active', 'ehrman-section-pilot', '--allow-root', '--path=/var/www/html')
    if (-not $Disable) {
        foreach ($name in @('index.json', 'sections-300.jsonl.gz')) {
            if (-not (Test-Path -LiteralPath "experiments/section-search/private/guest-access-runtime/$name")) {
                throw "Missing guest runtime file: $name. Run guest-access/package.py first."
            }
        }
        Invoke-LocalCompose @('exec', '-T', 'wordpress', 'install', '-d', '-m', '0750', '-o', 'www-data', '-g', 'www-data', '/opt/ehrman-section-public')
        Invoke-LocalCompose @('cp', 'experiments/section-search/private/guest-access-runtime/.', 'wordpress:/opt/ehrman-section-public/')
    }
    Invoke-LocalCompose @('cp', 'experiments/section-search/guest-access/plugin/.', 'wordpress:/var/www/html/wp-content/plugins/ehrman-section-public/')
    Invoke-LocalCompose @('exec', '-T', 'wordpress', 'wp', 'plugin', 'activate', 'ehrman-section-public', '--allow-root', '--path=/var/www/html')
    if (-not $Disable) {
        Invoke-LocalCompose @('cp', 'wordpress-theme/ehrman-discovery-demo/header.php', 'wordpress:/var/www/html/wp-content/themes/ehrman-discovery-demo/header.php')
    }
    $operation = if ($Disable) { 'disable' } else { 'enable' }
    Invoke-LocalCompose @('exec', '-T', 'wordpress', 'wp', 'eval-file', '/var/www/html/wp-content/plugins/ehrman-section-public/cli.php', $operation, '--allow-root', '--path=/var/www/html')
    Write-Host "Local page: $homeUrl/ask-ai-3/"
} finally {
    Pop-Location
}
