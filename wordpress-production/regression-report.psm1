# Small reporting helpers shared with runner tests; no Docker or environment mutations.
function New-RegressionReport {
    param([string]$Directory, [string[]]$StepNames, [System.Collections.IDictionary]$Context)
    $null = New-Item -ItemType Directory -Path $Directory -ErrorAction Stop
    $report = [ordered]@{
        status = 'running'
        started_utc = [DateTimeOffset]::UtcNow.ToString('o')
        finished_utc = $null
        directory = $Directory
        context = $Context
        steps = @($StepNames | ForEach-Object { [ordered]@{ name = $_; status = 'not-run'; seconds = 0; error = $null; log = "$_.log" } })
    }
    Write-RegressionReport $report
    return $report
}

function Write-RegressionReport {
    param([System.Collections.IDictionary]$Report)
    $Report | ConvertTo-Json -Depth 15 | Set-Content -LiteralPath (Join-Path $Report.directory 'report.json') -Encoding utf8
    $lines = @('Full local regression', "Status: $($Report.status)", "Started (UTC): $($Report.started_utc)", "Finished (UTC): $($Report.finished_utc)", '')
    foreach ($key in $Report.context.Keys) { $lines += "${key}: $($Report.context[$key])" }
    $lines += ''
    foreach ($step in $Report.steps) {
        $lines += "$($step.status.ToUpper()) $($step.name) ($($step.seconds)s) - $($step.log)"
        if ($step.error) { $lines += "  $($step.error)" }
    }
    $lines += @('', 'No live OpenAI or Render testing. AI responses are mocked or blocked.',
        'The normal demo is not modified. Test volumes are retained; see cleanup status for service shutdown.',
        'Logs, screenshots and database backups are private; do not publish this directory.')
    $lines | Set-Content -LiteralPath (Join-Path $Report.directory 'report.txt') -Encoding utf8
}

function Invoke-RegressionCommand {
    param([string]$Executable, [string[]]$Arguments = @())
    & $Executable @Arguments
    if ($LASTEXITCODE -ne 0) { throw "$([IO.Path]::GetFileName($Executable)) exited with code $LASTEXITCODE." }
}

function Invoke-RegressionStep {
    param([System.Collections.IDictionary]$Report, [string]$Name, [scriptblock]$Action)
    $step = @($Report.steps | Where-Object { $_.name -eq $Name })
    if ($step.Count -ne 1 -or $step[0].status -ne 'not-run') { throw "Unknown or repeated regression stage: $Name" }
    $step = $step[0]
    $step.status = 'running'
    Write-RegressionReport $Report
    Write-Host "Running $Name"
    $timer = [Diagnostics.Stopwatch]::StartNew()
    try {
        & $Action *>&1 | Tee-Object -FilePath (Join-Path $Report.directory $step.log) | Out-Host
        $step.status = 'passed'
    } catch {
        $step.status = 'failed'
        $step.error = $_.Exception.Message
        $step.error | Add-Content -LiteralPath (Join-Path $Report.directory $step.log) -Encoding utf8
        throw
    } finally {
        $step.seconds = [Math]::Round($timer.Elapsed.TotalSeconds, 2)
        Write-RegressionReport $Report
    }
}

function Complete-RegressionReport {
    param([System.Collections.IDictionary]$Report)
    foreach ($step in $Report.steps) {
        if ($step.status -in @('not-run', 'running')) { $step.status = 'skipped' }
    }
    $Report.status = if (@($Report.steps | Where-Object { $_.status -ne 'passed' }).Count) { 'failed' } else { 'passed' }
    $Report.finished_utc = [DateTimeOffset]::UtcNow.ToString('o')
    Write-RegressionReport $Report
}

Export-ModuleMember -Function New-RegressionReport, Write-RegressionReport, Invoke-RegressionCommand, Invoke-RegressionStep, Complete-RegressionReport
