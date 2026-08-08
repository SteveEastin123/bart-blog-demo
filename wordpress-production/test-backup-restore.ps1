param(
    [string]$BackupPath = ''
)

$ErrorActionPreference = 'Stop'
$composeFile = Join-Path $PSScriptRoot 'compose.local.yaml'
$createdBackup = $false
if (-not $BackupPath) {
    $BackupPath = Join-Path $PSScriptRoot 'backups\restore-test.sql'
    $BackupPath = & (Join-Path $PSScriptRoot 'backup-database.ps1') -OutputPath $BackupPath
    $createdBackup = $true
}
$BackupPath = (Resolve-Path -LiteralPath $BackupPath).Path

$containerId = (& docker compose -f $composeFile ps -q mysql).Trim()
if (-not $containerId) {
    throw 'The production-test MySQL container is not running.'
}
$rootPassword = (& docker compose -f $composeFile exec -T mysql printenv MYSQL_ROOT_PASSWORD).Trim()
$sourceDatabase = (& docker compose -f $composeFile exec -T mysql printenv MYSQL_DATABASE).Trim()
if (-not $rootPassword -or -not $sourceDatabase) {
    throw 'Could not read the local MySQL test configuration.'
}

& docker cp $BackupPath "${containerId}:/tmp/ehrman-restore-test.sql"
if ($LASTEXITCODE -ne 0) {
    throw 'The test backup could not be copied into the MySQL container.'
}

try {
    'DROP DATABASE IF EXISTS ehrman_restore_test; CREATE DATABASE ehrman_restore_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;' |
        & docker exec -i -e "MYSQL_PWD=$rootPassword" $containerId mysql -u root
    if ($LASTEXITCODE -ne 0) {
        throw 'Could not create the restore-test database.'
    }
    & docker exec -e "MYSQL_PWD=$rootPassword" $containerId sh -c 'mysql -u root ehrman_restore_test < /tmp/ehrman-restore-test.sql'
    if ($LASTEXITCODE -ne 0) {
        throw 'Could not restore the test backup.'
    }

    $countSql = "SELECT (SELECT COUNT(*) FROM $sourceDatabase.wp_ehrman_external_posts), (SELECT COUNT(*) FROM ehrman_restore_test.wp_ehrman_external_posts);"
    $counts = $countSql | & docker exec -i -e "MYSQL_PWD=$rootPassword" $containerId mysql -N -u root
    if ($LASTEXITCODE -ne 0) {
        throw 'Could not compare restored discovery records.'
    }
    $parts = ([string]$counts).Trim() -split '\s+'
    if ($parts.Count -ne 2 -or $parts[0] -ne $parts[1]) {
        throw "Restore verification failed: source=$($parts[0]), restored=$($parts[1])."
    }
    Write-Output "Backup restore: OK ($($parts[1]) external posts)"
}
finally {
    'DROP DATABASE IF EXISTS ehrman_restore_test;' |
        & docker exec -i -e "MYSQL_PWD=$rootPassword" $containerId mysql -u root *> $null
    & docker compose -f $composeFile exec -T mysql rm -f /tmp/ehrman-restore-test.sql *> $null
    if ($createdBackup -and (Test-Path -LiteralPath $BackupPath)) {
        Remove-Item -LiteralPath $BackupPath -Force
    }
}
