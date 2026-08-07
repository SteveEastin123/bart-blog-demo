param(
    [int]$Port = 8086,
    [string]$PythonExecutable = 'python'
)

$ErrorActionPreference = 'Stop'
$composeFile = Join-Path $PSScriptRoot 'compose.local.yaml'
$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path

& docker compose -f $composeFile config --quiet
if ($LASTEXITCODE -ne 0) {
    throw 'Production Compose validation failed.'
}

$phpFiles = & docker compose -f $composeFile exec -T wordpress sh -c "find /opt/ehrman-code/plugins/ehrman-blog-discovery /opt/ehrman-code/themes/ehrman-discovery-demo -name '*.php' -type f -print"
if ($LASTEXITCODE -ne 0 -or @($phpFiles).Count -eq 0) {
    throw 'No packaged plugin or theme PHP files were found for syntax validation.'
}
foreach ($file in $phpFiles) {
    & docker compose -f $composeFile exec -T wordpress php -l $file
    if ($LASTEXITCODE -ne 0) {
        throw "PHP syntax validation failed for $file."
    }
}

$status = Invoke-RestMethod -Method Get -Uri "http://localhost:$Port/wp-json/ehrman-discovery/v1/status"
if (-not $status.database_connected -or $status.import_state -ne 'complete') {
    throw 'The production-equivalent plugin is not connected to a completed import.'
}

$expectedJson = & $PythonExecutable -B (Join-Path $repoRoot 'scripts\wordpress_expected_counts.py')
if ($LASTEXITCODE -ne 0) {
    throw 'Could not calculate authoritative import counts.'
}
$expected = $expectedJson | ConvertFrom-Json

foreach ($property in $expected.PSObject.Properties) {
    $actual = [int]$status.counts.($property.Name)
    if ($actual -ne [int]$property.Value) {
        throw "Unexpected $($property.Name) count: expected $($property.Value), found $actual."
    }
}

$pages = @('/', '/keyword-search/', '/browse-topics-1/', '/browse-topics-2/')
foreach ($path in $pages) {
    $response = Invoke-WebRequest -UseBasicParsing -Uri "http://localhost:$Port$path"
    if ($response.StatusCode -ne 200) {
        throw "$path returned HTTP $($response.StatusCode)."
    }
}

& docker compose -f $composeFile exec -T wordpress sh -c "test -f /opt/ehrman-import/ehrman_post_search_index.json && test ! -e /var/www/html/wp-content/ehrman-import && test -f /var/www/html/wp-content/plugins/ehrman-blog-discovery/ehrman-blog-discovery.php"
if ($LASTEXITCODE -ne 0) {
    throw 'Packaged runtime files are missing or legacy import JSON is present under the public document root.'
}

$legacyImportUrl = "http://localhost:$Port/wp-content/ehrman-import/ehrman_post_search_index.json"
$legacyImportHidden = $false
try {
    $legacyResponse = Invoke-WebRequest -UseBasicParsing -Uri $legacyImportUrl -ErrorAction Stop
    if ($legacyResponse.Content -notmatch '"wpId"') {
        $legacyImportHidden = $true
    }
}
catch {
    if ($_.Exception.Response -and [int]$_.Exception.Response.StatusCode -eq 404) {
        $legacyImportHidden = $true
    }
    else {
        throw
    }
}
if (-not $legacyImportHidden) {
    throw 'Legacy import JSON is publicly accessible.'
}

$parityRouteDisabled = $false
try {
    Invoke-WebRequest -UseBasicParsing `
        -Uri "http://localhost:$Port/wp-json/ehrman-discovery/v1/parity/batch" `
        -Method Post `
        -ContentType 'application/json' `
        -Body '{"cases":[]}' `
        -ErrorAction Stop | Out-Null
}
catch {
    if ($_.Exception.Response -and [int]$_.Exception.Response.StatusCode -eq 404) {
        $parityRouteDisabled = $true
    }
    else {
        throw
    }
}
if (-not $parityRouteDisabled) {
    throw 'The test-only parity route is enabled.'
}

$search = Invoke-RestMethod -Method Get -Uri "http://localhost:$Port/wp-json/ehrman-discovery/v1/search?term%5B%5D=Luke&term%5B%5D=Atonement&sort=ranked"
if ([int]$search.count -le 0 -or @($search.terms).Count -ne 2) {
    throw 'The representative AND search failed.'
}

Write-Host 'Production Docker image: OK'
Write-Host "Plugin PHP syntax: OK ($($phpFiles.Count) files)"
Write-Host 'WordPress and MySQL connection: OK'
Write-Host 'Authoritative imported counts: OK'
Write-Host 'Landing, search, and browse pages: OK'
Write-Host 'Private import and packaged runtime files: OK'
Write-Host 'Parity route disabled: OK'
Write-Host 'Representative AND search: OK'
