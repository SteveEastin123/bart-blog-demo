param(
    [string]$OutputPath = '',
    [string]$ComposeFile = (Join-Path $PSScriptRoot 'compose.local.yaml')
)

$ErrorActionPreference = 'Stop'
$composeFile = (Resolve-Path -LiteralPath $ComposeFile).Path
$services = @(& docker compose -f $composeFile config --services)
if ($LASTEXITCODE -ne 0) { throw 'Could not read Compose services.' }
$databaseService = if ($services -contains 'database') { 'database' } elseif ($services -contains 'mysql') { 'mysql' } else { throw 'No supported database service found.' }
if (-not $OutputPath) {
    $OutputPath = Join-Path $PSScriptRoot ('backups/ehrman-wordpress-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.sql')
}
$OutputPath = [System.IO.Path]::GetFullPath($OutputPath)
if (Test-Path -LiteralPath $OutputPath) { throw "Refusing to overwrite an existing backup: $OutputPath" }
New-Item -ItemType Directory -Force -Path (Split-Path -Parent $OutputPath) | Out-Null
$temporary = "$OutputPath.partial"
$containerPath = '/tmp/ehrman-backup-' + [guid]::NewGuid().ToString('N') + '.sql'

# Export only the application database. Hex encoding preserves binary vectors.
$dump = @'
set -eu
if command -v mariadb-dump >/dev/null 2>&1; then
    export MYSQL_PWD="$MARIADB_ROOT_PASSWORD"
    mariadb-dump --single-transaction --quick --hex-blob --routines --triggers --events -u root "$MARIADB_DATABASE" > "$1"
else
    export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
    mysqldump --single-transaction --quick --hex-blob --routines --triggers --events --set-gtid-purged=OFF --no-tablespaces --column-statistics=0 -u root "$MYSQL_DATABASE" > "$1"
fi
'@
try {
    & docker compose -f $composeFile exec -T $databaseService sh -c $dump sh $containerPath
    if ($LASTEXITCODE -ne 0) { throw 'Database export failed.' }
    & docker compose -f $composeFile cp "${databaseService}:$containerPath" $temporary | Out-Host
    if ($LASTEXITCODE -ne 0) { throw 'Could not copy the database backup.' }
    if ((Get-Item -LiteralPath $temporary).Length -le 0) { throw 'The database backup is empty.' }
    Move-Item -LiteralPath $temporary -Destination $OutputPath
    Write-Output $OutputPath
}
finally {
    & docker compose -f $composeFile exec -T $databaseService rm -f $containerPath
    if (Test-Path -LiteralPath $temporary) { Remove-Item -LiteralPath $temporary -Force }
}
