#Requires -Version 7.2
$ErrorActionPreference = 'Stop'
Import-Module (Join-Path $PSScriptRoot '../regression-report.psm1') -Force
$root = Join-Path ([IO.Path]::GetTempPath()) ('ehrman-runner-tests-' + [guid]::NewGuid().ToString('N'))
$pwsh = (Get-Process -Id $PID).Path
function Assert-Runner($Condition, [string]$Message) {
    if (-not $Condition) { throw $Message }
}

$success = New-RegressionReport (Join-Path $root 'success') @('native', 'cleanup') ([ordered]@{ scope = 'unit' })
Invoke-RegressionStep $success 'native' { Invoke-RegressionCommand $pwsh @('-NoProfile', '-Command', 'Write-Output "native output"; exit 0') }
Invoke-RegressionStep $success 'cleanup' { Write-Output 'cleanup ran' }
Complete-RegressionReport $success
Assert-Runner ($success.status -eq 'passed') 'Successful commands were not reported as passed.'
Assert-Runner ((Get-Content (Join-Path $success.directory 'native.log') -Raw) -match 'native output') 'Native output was not logged.'

foreach ($failure in @('native-failure', 'exception')) {
    $report = New-RegressionReport (Join-Path $root $failure) @('first', 'later', 'cleanup') ([ordered]@{ scope = 'unit' })
    $caught = $false
    try {
        Invoke-RegressionStep $report 'first' {
            if ($failure -eq 'exception') { throw 'Expected script error' }
            Invoke-RegressionCommand $pwsh @('-NoProfile', '-Command', 'Write-Output "before failure"; exit 7')
        }
    } catch { $caught = $true }
    finally {
        Invoke-RegressionStep $report 'cleanup' { Write-Output 'cleanup ran' }
        Complete-RegressionReport $report
    }
    Assert-Runner $caught 'A failing stage was swallowed.'
    $disk = Get-Content (Join-Path $report.directory 'report.json') -Raw | ConvertFrom-Json
    Assert-Runner ($disk.status -eq 'failed') 'Failure was reported as success.'
    Assert-Runner ($disk.steps[0].status -eq 'failed' -and $disk.steps[1].status -eq 'skipped' -and $disk.steps[2].status -eq 'passed') 'Failure/skip/cleanup states were incorrect.'
    Assert-Runner ([bool]$disk.finished_utc -and [bool]$disk.steps[0].error) 'Failure details were not saved.'
}
$cleanup = New-RegressionReport (Join-Path $root 'cleanup-failure') @('test', 'cleanup') ([ordered]@{ scope = 'unit' })
Invoke-RegressionStep $cleanup 'test' { Write-Output 'passed test' }
try { Invoke-RegressionStep $cleanup 'cleanup' { throw 'Expected cleanup failure' } } catch { }
Complete-RegressionReport $cleanup
Assert-Runner ($cleanup.status -eq 'failed') 'Cleanup failure must fail the run.'
Write-Output 'Runner reporting: native failure, exception, skipped stages, cleanup and success: OK'

$compare = Join-Path $PSScriptRoot '../compare-database-snapshots.ps1'
$before = Join-Path $root 'before.json'
$after = Join-Path $root 'after.json'
$fixture = @{
    tables = @{
        wp_options = @{ rows = 5; sha256 = 'core-before' }
        wp_ehrman_posts = @{ rows = 10; sha256 = 'discovery-before'; auto_increment = 11 }
    }
}
$fixture | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath $before
$fixture.tables.wp_options.sha256 = 'core-after'
$fixture.tables.wp_ehrman_posts.auto_increment = 99
$fixture | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath $after
& $compare -Before $before -After $after -DiscoveryOnly -IgnoreAutoIncrement
foreach ($case in @('all-tables', 'auto-increment', 'changed-data', 'missing-table', 'empty-snapshot')) {
    $arguments = @{ Before = $before; After = $after; DiscoveryOnly = $true; IgnoreAutoIncrement = $true }
    switch ($case) {
        'all-tables' { $arguments.DiscoveryOnly = $false }
        'auto-increment' { $arguments.IgnoreAutoIncrement = $false }
        'changed-data' { $fixture.tables.wp_ehrman_posts.sha256 = 'changed' }
        'missing-table' { $fixture.tables.Remove('wp_ehrman_posts') }
        'empty-snapshot' { $fixture | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath $before }
    }
    $fixture | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath $after
    $caught = $false
    try { & $compare @arguments } catch { $caught = $true }
    Assert-Runner $caught "Database comparison did not reject $case."
}
Write-Output 'Database comparison: scoped core changes, sequence changes, data changes and missing tables: OK'
