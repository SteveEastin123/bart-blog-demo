param(
    [int]$Port = 8085,
    [int]$Iterations = 30,
    [int]$Warmups = 3
)

$ErrorActionPreference = 'Stop'
$baseUrl = "http://localhost:$Port"
$apiUrl = "$baseUrl/wp-json/ehrman-discovery/v1"
$cases = [ordered]@{
    'Single-term search' = "$apiUrl/search?term%5B%5D=Luke&sort=ranked"
    'Two-term search' = "$apiUrl/search?term%5B%5D=Luke&term%5B%5D=Atonement&sort=ranked"
    'Three-term search' = "$apiUrl/search?term%5B%5D=Luke&term%5B%5D=Atonement&term%5B%5D=Last%20Supper&sort=ranked"
    'Four-term search' = "$apiUrl/search?term%5B%5D=Acts&term%5B%5D=Luke-Acts%20Authorship&term%5B%5D=Mission&term%5B%5D=Paul&sort=ranked"
    'Global autocomplete' = "$apiUrl/suggestions?q=lu"
    'Narrowed autocomplete' = "$apiUrl/suggestions?q=at&selected%5B%5D=Luke"
    'Category page' = "$baseUrl/browse-topics-1/?ebd_subject=jesus-the-gospels-and-acts&ebd_category=canonical-gospels-and-acts"
    'Topic page' = "$baseUrl/browse-topics-1/?ebd_subject=jesus-the-gospels-and-acts&ebd_category=canonical-gospels-and-acts&ebd_topic=gospel-authorship"
}

function Get-Percentile([double[]]$Values, [double]$Percentile) {
    $sorted = @($Values | Sort-Object)
    if ($sorted.Count -eq 0) {
        return 0
    }
    $index = [Math]::Ceiling(($Percentile / 100) * $sorted.Count) - 1
    return $sorted[[Math]::Max(0, [Math]::Min($index, $sorted.Count - 1))]
}

$results = @()
foreach ($case in $cases.GetEnumerator()) {
    for ($index = 0; $index -lt $Warmups; $index++) {
        Invoke-WebRequest -UseBasicParsing -Uri $case.Value | Out-Null
    }

    $timings = @()
    for ($index = 0; $index -lt $Iterations; $index++) {
        $stopwatch = [System.Diagnostics.Stopwatch]::StartNew()
        $response = Invoke-WebRequest -UseBasicParsing -Uri $case.Value
        $stopwatch.Stop()
        if ($response.StatusCode -ne 200) {
            throw "$($case.Key) returned HTTP $($response.StatusCode)."
        }
        $timings += $stopwatch.Elapsed.TotalMilliseconds
    }

    $results += [pscustomobject]@{
        Case = $case.Key
        Iterations = $Iterations
        MedianMs = [Math]::Round((Get-Percentile $timings 50), 1)
        P95Ms = [Math]::Round((Get-Percentile $timings 95), 1)
        MaxMs = [Math]::Round(($timings | Measure-Object -Maximum).Maximum, 1)
    }
}

$results | Format-Table -AutoSize
