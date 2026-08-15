param(
    [int]$Port = 8085,
    [switch]$RequireImport
)

# Docker Compose writes routine lifecycle messages to stderr. Use explicit
# process exit-code checks so Windows PowerShell 5.1 does not treat them as
# terminating errors.
$ErrorActionPreference = "Continue"

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    $perUserDocker = Join-Path $env:LOCALAPPDATA 'Programs\DockerDesktop\resources\bin\docker.exe'
    if (Test-Path -LiteralPath $perUserDocker) {
        $env:Path = "$(Split-Path -Parent $perUserDocker);$env:Path"
    }
    else {
        throw "Docker Desktop is required. Install it, start it, and run this script again."
    }
}

$pluginRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\wordpress-plugin\ehrman-blog-discovery') -ErrorAction Stop).Path
$pluginPrefix = $pluginRoot.TrimEnd('\') + '\'
$pluginBootstrap = Get-Content -LiteralPath (Join-Path $pluginRoot 'ehrman-blog-discovery.php') -Raw
$versionMatch = [regex]::Match($pluginBootstrap, "define\(\s*'EHRMAN_DISCOVERY_VERSION',\s*'([^']+)'\s*\);")
if (-not $versionMatch.Success) {
    throw "Could not determine the expected plugin version."
}
$expectedPluginVersion = $versionMatch.Groups[1].Value

Push-Location $PSScriptRoot

try {
    $env:WORDPRESS_PORT = [string]$Port

    & docker compose config --quiet
    if ($LASTEXITCODE -ne 0) {
        throw "Docker Compose configuration validation failed."
    }

    $vendorPrefix = (Join-Path $pluginRoot 'vendor').TrimEnd('\') + '\'
    $phpFiles = Get-ChildItem -LiteralPath $pluginRoot -Recurse -File -Filter '*.php' -ErrorAction Stop |
        Where-Object { -not $_.FullName.StartsWith($vendorPrefix, [System.StringComparison]::OrdinalIgnoreCase) }
    foreach ($file in $phpFiles) {
        $relativePath = $file.FullName.Substring($pluginPrefix.Length).Replace('\', '/')
        $containerPath = "/var/www/html/wp-content/plugins/ehrman-blog-discovery/$relativePath"

        & docker compose exec -T wordpress php -l $containerPath
        if ($LASTEXITCODE -ne 0) {
            throw "PHP syntax validation failed for $relativePath."
        }
    }

    $statusUrl = "http://localhost:$Port/wp-json/ehrman-discovery/v1/status"
    try {
        $status = Invoke-RestMethod -Method Get -Uri $statusUrl -ErrorAction Stop
    }
    catch {
        throw "The WordPress REST status endpoint failed: $($_.Exception.Message)"
    }

    if (-not $status.database_connected) {
        throw "The plugin status endpoint reports that MySQL is unavailable."
    }

    if ($status.plugin_version -ne $expectedPluginVersion) {
        throw "Unexpected plugin version: $($status.plugin_version)"
    }

    if ($RequireImport) {
        if ($status.import_state -ne 'complete') {
            throw "Expected a completed import, found: $($status.import_state)"
        }

        $expectedCounts = [ordered]@{
            browse_paths = 2
            subject_areas = 19
            categories = 41
            topics = 272
            external_posts = 4395
            keywords = 1037
            subject_area_categories = 83
            topic_categories = 313
            post_topics = 8585
            post_keywords = 21616
            post_search_terms = 30201
        }

        foreach ($entry in $expectedCounts.GetEnumerator()) {
            $actual = [int]$status.counts.($entry.Key)
            if ($actual -ne $entry.Value) {
                throw "Unexpected $($entry.Key) count: expected $($entry.Value), found $actual"
            }
        }

        $phase4Pages = [ordered]@{
            'Keyword Search' = '/keyword-search/'
            'Browse Topics 1' = '/browse-topics-1/'
            'Browse Topics 2' = '/browse-topics-2/'
        }
        foreach ($page in $phase4Pages.GetEnumerator()) {
            try {
                $response = Invoke-WebRequest -UseBasicParsing -Uri "http://localhost:$Port$($page.Value)" -ErrorAction Stop
            }
            catch {
                throw "$($page.Key) page failed: $($_.Exception.Message)"
            }
            if ($response.StatusCode -ne 200 -or $response.Content -notmatch 'ebd-discovery') {
                throw "$($page.Key) page did not render the discovery interface."
            }
        }

        $apiBase = "http://localhost:$Port/wp-json/ehrman-discovery/v1"
        $hellSuggestions = Invoke-RestMethod -Method Get -Uri "$apiBase/suggestions?q=hell" -ErrorAction Stop
        if ('Hell' -notin @($hellSuggestions.label) -or 'Heaven and Hell' -notin @($hellSuggestions.label)) {
            throw "The suggestion endpoint did not return the expected topic/keyword mix for hell."
        }

        $andSearch = Invoke-RestMethod -Method Get `
            -Uri "$apiBase/search?term%5B%5D=Luke&term%5B%5D=Atonement&sort=ranked" `
            -ErrorAction Stop
        if ([int]$andSearch.count -le 0 -or @($andSearch.terms).Count -ne 2) {
            throw "The representative two-term AND search failed."
        }

        $rankedSearch = Invoke-RestMethod -Method Get `
            -Uri "$apiBase/search?term%5B%5D=Historical%20Jesus%20%28General%29&sort=ranked" `
            -ErrorAction Stop
        if ([int]$rankedSearch.count -le 0 -or $rankedSearch.posts[0].title -notmatch 'Historical Jesus') {
            throw "The representative ranked search did not prioritize a strong title match."
        }
    }

    Write-Host "Compose configuration: OK"
    Write-Host "Plugin PHP syntax: OK ($($phpFiles.Count) files)"
    Write-Host "WordPress REST status: OK"
    Write-Host "MySQL connection: OK"
    Write-Host "Import state: $($status.import_state)"
    if ($RequireImport) {
        Write-Host "Imported dataset counts: OK"
        Write-Host "Phase 4 pages and search behavior: OK"
    }
}
finally {
    Pop-Location
}
