param([ValidateSet('export', 'parse')][string] $Operation = 'export')
$ErrorActionPreference = 'Stop'
$root = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '../../..')).Path
$relative = 'experiments/summary-search/private/metadata-review-2026-10-17'
$containerDirectory = '/tmp/ehrman-metadata-review-2026-10-17'
function Invoke-Compose {
    param([string[]] $Arguments)
    & docker compose -f wordpress-production/compose.local.yaml @Arguments
    if ($LASTEXITCODE -ne 0) { throw "Local Docker command failed: $LASTEXITCODE" }
}
Push-Location $root
try {
    Invoke-Compose @('exec', '-T', 'wordpress', 'mkdir', '-p', $containerDirectory)
    Invoke-Compose @('cp', "$relative/input.json", "wordpress:$containerDirectory/input.json")
    Invoke-Compose @('cp', 'experiments/summary-search/metadata-review/bridge.php', "wordpress:$containerDirectory/bridge.php")
    if ($Operation -eq 'parse') {
        Invoke-Compose @('cp', "$relative/receipts.json", "wordpress:$containerDirectory/receipts.json")
    }
    Invoke-Compose @('exec', '-T', 'wordpress', 'wp', 'eval-file', "$containerDirectory/bridge.php", $Operation, $containerDirectory, '--allow-root', '--path=/var/www/html')
    $output = if ($Operation -eq 'export') { 'requests.json' } else { 'parsed.json' }
    Invoke-Compose @('cp', "wordpress:$containerDirectory/$output", "$relative/$output")
} finally { Pop-Location }
