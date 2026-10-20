param(
    [string]$BackupPath = '',
    [string]$ComposeFile = (Join-Path $PSScriptRoot 'compose.local.yaml')
)

$ErrorActionPreference = 'Stop'
$composeFile = (Resolve-Path -LiteralPath $ComposeFile).Path
$services = @(& docker compose -f $composeFile config --services)
if ($LASTEXITCODE -ne 0) { throw 'Could not read Compose services.' }
$service = if ($services -contains 'database') { 'database' } elseif ($services -contains 'mysql') { 'mysql' } else { throw 'No supported database service found.' }
if (-not $BackupPath) {
    $BackupPath = & (Join-Path $PSScriptRoot 'backup-database.ps1') -ComposeFile $composeFile
}
$BackupPath = (Resolve-Path -LiteralPath $BackupPath).Path
$scratch = 'ehrman_restore_' + [guid]::NewGuid().ToString('N')
$containerPath = "/tmp/$scratch.sql"
& docker compose -f $composeFile cp $BackupPath "${service}:$containerPath" | Out-Host
if ($LASTEXITCODE -ne 0) { throw 'Could not copy the restore-test backup.' }

# Passwords stay inside the database container, never in command-line arguments.
$sql = @'
set -eu
if command -v mariadb >/dev/null 2>&1; then
    client=mariadb
    export MYSQL_PWD="$MARIADB_ROOT_PASSWORD"
else
    client=mysql
    export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
fi
exec "$client" -N -B -u root -e "$1"
'@
function Invoke-DatabaseSql([string]$Query) {
    $result = & docker compose -f $composeFile exec -T $service sh -c $sql sh $Query
    if ($LASTEXITCODE -ne 0) { throw 'Restore-test SQL failed.' }
    return $result
}
$source = (& docker compose -f $composeFile exec -T $service sh -c 'printf "%s" "${MARIADB_DATABASE:-$MYSQL_DATABASE}"').Trim()
if ($LASTEXITCODE -ne 0 -or $source -notmatch '^[A-Za-z0-9_]+$') { throw 'Unexpected source database identifier.' }
$created = $false
try {
    $null = Invoke-DatabaseSql "CREATE DATABASE $scratch CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    $created = $true
    $restore = @'
set -eu
if command -v mariadb >/dev/null 2>&1; then
    export MYSQL_PWD="$MARIADB_ROOT_PASSWORD"
    mariadb -u root "$1" < "$2"
else
    export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
    mysql -u root "$1" < "$2"
fi
'@
    & docker compose -f $composeFile exec -T $service sh -c $restore sh $scratch $containerPath
    if ($LASTEXITCODE -ne 0) { throw 'Backup restoration failed.' }
    $tables = @(Invoke-DatabaseSql "SHOW TABLES FROM $source")
    $restored = @(Invoke-DatabaseSql "SHOW TABLES FROM $scratch")
    if (-not $tables.Count -or (Compare-Object $tables $restored)) { throw 'Restored table list differs.' }
    foreach ($table in $tables) {
        if ($table -notmatch '^[A-Za-z0-9_]+$') { throw 'Unexpected table identifier.' }
        $counts = [string](Invoke-DatabaseSql "SELECT (SELECT COUNT(*) FROM $source.$table), (SELECT COUNT(*) FROM $scratch.$table)")
        $parts = $counts.Trim() -split '\s+'
        $checksums = @(Invoke-DatabaseSql "CHECKSUM TABLE $source.$table, $scratch.$table EXTENDED")
        $originalHash = ([string]$checksums[0] -split '\s+')[-1]
        $restoredHash = ([string]$checksums[1] -split '\s+')[-1]
        if ($parts.Count -ne 2 -or $parts[0] -ne $parts[1] -or $checksums.Count -ne 2 -or $originalHash -eq 'NULL' -or $originalHash -ne $restoredHash) {
            throw "Restore verification failed for $table. Stop web/cron writes and retry if the source changed after backup."
        }
    }
    Write-Output "Backup restore: OK ($($tables.Count) tables; all row counts and extended content checksums match). Backup retained: $BackupPath"
}
finally {
    if ($created) { $null = Invoke-DatabaseSql "DROP DATABASE $scratch" }
    & docker compose -f $composeFile exec -T $service rm -f $containerPath
}
