param(
    [string]$OutputPath = ''
)

$ErrorActionPreference = 'Stop'
$composeFile = Join-Path $PSScriptRoot 'compose.local.yaml'
if (-not $OutputPath) {
    $stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
    $OutputPath = Join-Path $PSScriptRoot "backups\ehrman-wordpress-$stamp.sql"
}
$OutputPath = [System.IO.Path]::GetFullPath($OutputPath)
$parent = Split-Path -Parent $OutputPath
New-Item -ItemType Directory -Force -Path $parent | Out-Null

$containerId = (& docker compose -f $composeFile ps -q mysql).Trim()
if (-not $containerId) {
    throw 'The production-test MySQL container is not running.'
}

& docker compose -f $composeFile exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump --single-transaction --routines --triggers -u root "$MYSQL_DATABASE" > /tmp/ehrman-wordpress.sql'
if ($LASTEXITCODE -ne 0) {
    throw 'mysqldump failed.'
}
& docker cp "${containerId}:/tmp/ehrman-wordpress.sql" $OutputPath
if ($LASTEXITCODE -ne 0) {
    throw 'The database backup could not be copied from the MySQL container.'
}
& docker compose -f $composeFile exec -T mysql rm -f /tmp/ehrman-wordpress.sql

if ((Get-Item -LiteralPath $OutputPath).Length -le 0) {
    throw 'The database backup is empty.'
}

Write-Output $OutputPath
