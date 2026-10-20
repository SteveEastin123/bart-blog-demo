param(
    [Parameter(Mandatory = $true)][string]$OutputPath,
    [string]$ComposeFile = (Join-Path $PSScriptRoot 'compose.local.yaml'),
    [string]$Database = ''
)

$ErrorActionPreference = 'Stop'
$composeFile = (Resolve-Path -LiteralPath $ComposeFile).Path
$script = Join-Path $PSScriptRoot 'tests/database-fingerprint.php'
# A one-off PHP process also works while the web service is stopped for backup.
$json = & docker compose -f $composeFile run --rm --no-deps -T --entrypoint php `
    -e "EHRMAN_SNAPSHOT_DATABASE=$Database" -v "${script}:/tmp/database-fingerprint.php:ro" `
    wordpress /tmp/database-fingerprint.php
if ($LASTEXITCODE -ne 0) { throw 'The database fingerprint failed.' }
$null = ($json -join "`n") | ConvertFrom-Json
$json | Set-Content -LiteralPath $OutputPath -Encoding utf8
Write-Output $OutputPath
