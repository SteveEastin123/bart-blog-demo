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

$pages = @('/', '/keyword-search/', '/ask-ai/', '/ask-ai-2/', '/browse-topics-1/', '/browse-topics-2/', '/structure-review/')
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

$allColossiansSuggestions = Invoke-RestMethod -Method Get -Uri "http://localhost:$Port/wp-json/ehrman-discovery/v1/suggestions?q=colossians"
$colossiansSuggestions = @($allColossiansSuggestions.Where({ $_.label -eq 'Colossians' }))
$colossiansTopicSuggestion = $colossiansSuggestions.Where({ $_.mode -eq 'topic' }, 'First')
$colossiansCombinedSuggestion = $colossiansSuggestions.Where({ $_.mode -eq 'topic-keyword' }, 'First')
$colossiansTopic = Invoke-RestMethod -Method Get -Uri "http://localhost:$Port/wp-json/ehrman-discovery/v1/search?term%5B%5D=Colossians&mode%5B%5D=topic&sort=ranked"
$colossiansCombined = Invoke-RestMethod -Method Get -Uri "http://localhost:$Port/wp-json/ehrman-discovery/v1/search?term%5B%5D=Colossians&mode%5B%5D=topic-keyword&sort=ranked"
$topicIds = @($colossiansTopic.posts | ForEach-Object { [int]$_.id })
$combinedIds = @($colossiansCombined.posts | ForEach-Object { [int]$_.id })
$outsideCombined = @($topicIds | Where-Object { $combinedIds -notcontains $_ })
if (
    ($null -eq $colossiansTopicSuggestion) -or
    ($null -eq $colossiansCombinedSuggestion) -or
    ([int]$colossiansTopicSuggestion.postCount -ne [int]$colossiansTopic.count) -or
    ([int]$colossiansCombinedSuggestion.postCount -ne [int]$colossiansCombined.count) -or
    ([int]$colossiansTopic.count -ge [int]$colossiansCombined.count) -or
    ($outsideCombined.Count -ne 0)
) {
    throw 'The topic-only and combined topic-plus-keyword search modes are inconsistent.'
}

$pageOne = Invoke-RestMethod -Method Get -Uri "http://localhost:$Port/wp-json/ehrman-discovery/v1/search?term%5B%5D=Textual%20Variants&sort=ranked&page=1"
$pageTwo = Invoke-RestMethod -Method Get -Uri "http://localhost:$Port/wp-json/ehrman-discovery/v1/search?term%5B%5D=Textual%20Variants&sort=ranked&page=2"
if (
    [int]$pageOne.count -le 25 -or
    [int]$pageOne.page -ne 1 -or
    [int]$pageTwo.page -ne 2 -or
    [int]$pageOne.per_page -ne 25 -or
    [int]$pageOne.total_pages -le 1 -or
    @($pageOne.posts).Count -ne 25 -or
    @($pageTwo.posts).Count -eq 0 -or
    @($pageTwo.posts).Count -gt 25
) {
    throw 'The paginated REST search returned invalid paging metadata or page sizes.'
}
$pageOneIds = @($pageOne.posts | ForEach-Object { [int]$_.id })
$pageTwoIds = @($pageTwo.posts | ForEach-Object { [int]$_.id })
$overlap = @($pageOneIds | Where-Object { $pageTwoIds -contains $_ })
if ($overlap.Count -ne 0 -or [int]$pageOne.count -ne [int]$pageTwo.count) {
    throw 'Paginated REST search pages overlap or report inconsistent totals.'
}

$paginatedPage = Invoke-WebRequest -UseBasicParsing -Uri "http://localhost:$Port/keyword-search/?ebd_keyword%5B%5D=Textual%20Variants&ebd_page=2"
if ($paginatedPage.Content -notmatch 'Showing 26-50 of' -or $paginatedPage.Content -notmatch 'ebd-pagination') {
    throw 'The server-rendered search page does not include the expected pagination controls.'
}

Write-Output 'Production Docker image: OK'
Write-Output "Plugin PHP syntax: OK ($($phpFiles.Count) files)"
Write-Output 'WordPress and MySQL connection: OK'
Write-Output 'Authoritative imported counts: OK'
Write-Output 'Landing, search, browse, and structure-review pages: OK'
Write-Output 'Private import and packaged runtime files: OK'
Write-Output 'Parity route disabled: OK'
Write-Output 'Representative AND search: OK'
Write-Output 'Topic-only and topic-plus-keyword modes: OK'
Write-Output 'REST and server-rendered pagination: OK'
