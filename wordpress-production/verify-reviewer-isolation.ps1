param(
    [int]$Port = 8086,
    [string]$ComposeFile = (Join-Path $PSScriptRoot 'compose.local.yaml'),
    [switch]$BrowserChecks,
    [string]$NodeExecutable = 'node'
)

$ErrorActionPreference = 'Stop'
$composeFile = (Resolve-Path -LiteralPath $ComposeFile).Path
$base = "http://localhost:$Port"
& docker compose -f $composeFile exec -T wordpress wp plugin is-active ehrman-reviewer-tools --allow-root
if ($LASTEXITCODE -ne 0) { throw 'Start the demo with Reviewer Tools active before testing isolation.' }
$before = Invoke-RestMethod "$base/wp-json/ehrman-discovery/v1/status"
$missingDependency = & docker compose -f $composeFile exec -T wordpress wp eval 'echo shortcode_exists("ehrman_structure_review") ? "present" : "absent";' --skip-plugins=ehrman-blog-discovery --allow-root
if ($LASTEXITCODE -ne 0 -or $missingDependency.Trim() -ne 'absent') { throw 'Reviewer Tools did not fail safely with its dependency unavailable.' }
try {
    & docker compose -f $composeFile exec -T wordpress wp plugin deactivate ehrman-reviewer-tools --allow-root
    if ($LASTEXITCODE -ne 0) { throw 'Could not deactivate Reviewer Tools for isolation testing.' }
    foreach ($route in @('/', '/keyword-search/', '/browse-topics-1/', '/browse-topics-2/', '/ask-ai/')) {
        $response = Invoke-WebRequest -Uri "$base$route" -UseBasicParsing
        if ($response.StatusCode -ne 200 -or $response.Content -match 'Reviewer Tools|ehrman-reviewer-tools|ehrman_structure_review') {
            throw "Production-only page failed or contains Reviewer Tools: $route"
        }
        if ($route -ne '/' -and $response.Content -notmatch 'ebd-discovery') {
            throw "Search interface is missing: $route"
        }
    }
    $hidden = Invoke-WebRequest "$base/structure-review/" -SkipHttpErrorCheck
    if ($hidden.StatusCode -ne 404) { throw 'Disabled review page is still public.' }
    $isolated = & docker compose -f $composeFile exec -T wordpress wp eval 'echo shortcode_exists("ehrman_structure_review") ? "present" : "absent";' --allow-root
    if ($LASTEXITCODE -ne 0 -or $isolated.Trim() -ne 'absent') { throw 'Production registered the review shortcode.' }
    $after = Invoke-RestMethod "$base/wp-json/ehrman-discovery/v1/status"
    if (($before.counts | ConvertTo-Json -Compress) -ne ($after.counts | ConvertTo-Json -Compress)) {
        throw 'Disabling the add-on changed discovery counts.'
    }
    if ($BrowserChecks) {
        $savedBase = $env:EHRMAN_TEST_BASE_URL
        try {
            $env:EHRMAN_TEST_BASE_URL = $base
            foreach ($script in @('frontend-assets-ui.cjs', 'ask-ai-retirement-ui.cjs')) {
                & $NodeExecutable (Join-Path $PSScriptRoot "tests/$script") --production-only
                if ($LASTEXITCODE -ne 0) { throw "Production-only browser regression failed: $script" }
            }
        } finally {
            $env:EHRMAN_TEST_BASE_URL = $savedBase
        }
    }
}
finally {
    & docker compose -f $composeFile exec -T wordpress wp plugin activate ehrman-reviewer-tools --allow-root
    if ($LASTEXITCODE -ne 0) { throw 'Could not restore Reviewer Tools after testing.' }
}
$restored = Invoke-WebRequest "$base/structure-review/" -UseBasicParsing
if ($restored.Content -notmatch 'data-ebd-review-tree') { throw 'The restored review page is missing.' }
Write-Output 'Production-only search pages work; Reviewer Tools absent when disabled and restored afterward: OK'
