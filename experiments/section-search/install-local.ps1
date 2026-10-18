param()

$ErrorActionPreference = 'Stop'
$root = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '../..')).Path
$runtime = Join-Path $PSScriptRoot 'private/runtime'
foreach ($name in @('index.json', 'sections-300.jsonl.gz', 'sections-400.jsonl.gz', 'sections-500.jsonl.gz')) {
    if (-not (Test-Path -LiteralPath (Join-Path $runtime $name))) {
        throw "Missing private runtime file: $name. Run api.py package first."
    }
}

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
        throw 'The section pilot may only be installed in the localhost local environment.'
    }
    Invoke-LocalCompose @('exec', '-T', 'wordpress', 'install', '-d', '-m', '0750', '-o', 'www-data', '-g', 'www-data', '/opt/ehrman-section-pilot')
    Invoke-LocalCompose @('cp', 'experiments/section-search/private/runtime/.', 'wordpress:/opt/ehrman-section-pilot/')
    Invoke-LocalCompose @('cp', 'experiments/section-search/plugin/.', 'wordpress:/var/www/html/wp-content/plugins/ehrman-section-pilot/')
    Invoke-LocalCompose @('exec', '-T', 'wordpress', 'wp', 'plugin', 'activate', 'ehrman-section-pilot', '--allow-root', '--path=/var/www/html')
    Invoke-LocalCompose @('exec', '-T', 'wordpress', 'wp', 'eval-file', '/var/www/html/wp-content/plugins/ehrman-section-pilot/cli.php', 'enable', '--allow-root', '--path=/var/www/html')
    Invoke-LocalCompose @('exec', '-T', '--user', 'www-data', 'wordpress', 'wp', 'eval-file', '/var/www/html/wp-content/plugins/ehrman-section-pilot/tests.php', '--path=/var/www/html')
    $evidenceFixtures = 'experiments/section-search/private/evidence-300-2026-10-17/fixtures'
    if (Test-Path -LiteralPath (Join-Path $root "$evidenceFixtures/manifest.json")) {
        Invoke-LocalCompose @('exec', '-T', 'wordpress', 'install', '-d', '-m', '0750', '-o', 'www-data', '-g', 'www-data', '/opt/ehrman-section-pilot/evidence-300-2026-10-17')
        Invoke-LocalCompose @('cp', "$evidenceFixtures/.", 'wordpress:/opt/ehrman-section-pilot/evidence-300-2026-10-17/')
        Invoke-LocalCompose @('exec', '-T', '--user', 'www-data', 'wordpress', 'wp', 'eval-file', '/var/www/html/wp-content/plugins/ehrman-section-pilot/evidence-tests.php', '--path=/var/www/html')
    }
    $comparisonFixtures = 'experiments/section-search/private/end-to-end-300-2026-10-17/fixtures'
    if (Test-Path -LiteralPath (Join-Path $root "$comparisonFixtures/manifest.json")) {
        Invoke-LocalCompose @('exec', '-T', 'wordpress', 'install', '-d', '-m', '0750', '-o', 'www-data', '-g', 'www-data', '/opt/ehrman-section-pilot/end-to-end-300-2026-10-17')
        Invoke-LocalCompose @('cp', "$comparisonFixtures/.", 'wordpress:/opt/ehrman-section-pilot/end-to-end-300-2026-10-17/')
        Invoke-LocalCompose @('exec', '-T', '--user', 'www-data', 'wordpress', 'wp', 'eval-file', '/var/www/html/wp-content/plugins/ehrman-section-pilot/comparison-tests.php', '--path=/var/www/html')
    }
    Write-Host "`nPilot installed: $homeUrl/ask-ai-3/ (administrator sign-in required)."
} finally {
    Pop-Location
}
