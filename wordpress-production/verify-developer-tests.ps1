param(
    [string]$ComposeFile = (Join-Path $PSScriptRoot 'compose.local.yaml'),
    [string]$PythonExecutable = 'python',
    [string]$BaselinePath = ''
)

$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$compose = (Resolve-Path -LiteralPath $ComposeFile).Path
$run = [guid]::NewGuid().ToString('N')
$work = Join-Path $repo ".tmp/developer-test-verification/$run"
$containerRoot = "/tmp/ehrman-developer-tests-$run"
$null = New-Item -ItemType Directory -Path $work

function Invoke-CheckedDocker {
    param([string[]]$Arguments)
    & docker compose -f $compose @Arguments
    if ($LASTEXITCODE -ne 0) { throw "Developer-test command failed: $($Arguments[0])" }
}

Invoke-CheckedDocker @('cp', (Join-Path $repo 'developer-tests'), "wordpress:$containerRoot")
Invoke-CheckedDocker @('exec', '-T', 'wordpress', 'php', "$containerRoot/tests/environment-guards.php")
Invoke-CheckedDocker @('exec', '-T', 'wordpress', 'wp', 'eval-file', "$containerRoot/tests/wordpress-tests.php", '--allow-root')
$reference = if ($BaselinePath) { (Resolve-Path -LiteralPath $BaselinePath).Path } else { Join-Path $work 'roundtrip-reference.json' }
if (-not $BaselinePath) {
    # CI checks archive self-containment; this temporary reference is not a relevance/upgrade approval.
    Invoke-CheckedDocker @('exec', '-T', 'wordpress', 'wp', 'eval-file', "$containerRoot/capture.php", "$containerRoot/cases/search.json", "$containerRoot/reference.json", '--allow-root')
    Invoke-CheckedDocker @('cp', "wordpress:$containerRoot/reference.json", $reference)
}
$archive = Join-Path $work 'ehrman-discovery-developer-tests.zip'
& $PythonExecutable -B (Join-Path $repo 'scripts/package_wordpress_tests.py') --baseline $reference --output $archive
if ($LASTEXITCODE -ne 0) { throw 'Developer-test packaging failed.' }
Expand-Archive -LiteralPath $archive -DestinationPath (Join-Path $work 'extracted')
$bundle = Join-Path $work 'extracted/ehrman-discovery-developer-tests'
$deployed = "$containerRoot/delivered"
Invoke-CheckedDocker @('cp', $bundle, "wordpress:$deployed")
Invoke-CheckedDocker @('exec', '-T', 'wordpress', 'wp', 'eval-file', "$deployed/capture.php", "$deployed/cases/search.json", "$containerRoot/actual.json", '--allow-root')
$actual = Join-Path $work 'actual.json'
Invoke-CheckedDocker @('cp', "wordpress:$containerRoot/actual.json", $actual)
& $PythonExecutable -B (Join-Path $bundle 'compare.py') (Join-Path $bundle 'baseline.json') $actual
if ($LASTEXITCODE -ne 0) { throw 'Extracted test-bundle comparison failed.' }
Invoke-CheckedDocker @('exec', '-T', 'wordpress', 'wp', 'eval-file', "$deployed/run-regressions.php", '--allow-root')

$overwrite = & docker compose -f $compose exec -T wordpress wp eval-file "$deployed/capture.php" "$deployed/cases/search.json" "$containerRoot/actual.json" --allow-root 2>&1
if ($LASTEXITCODE -eq 0 -or ($overwrite -join "`n") -notmatch 'Output must be a new file') {
    throw 'Existing capture overwrite was not rejected.'
}
$checkMissing = "require '$deployed/test-tools/ehrman-discovery-test-tools.php'; EhrmanDiscoveryTests\Plugin::register_routes(); echo isset(rest_get_server()->get_routes()['/ehrman-discovery/v1/parity/batch']) ? 'present' : 'absent';"
$missing = & docker compose -f $compose exec -T wordpress wp eval $checkMissing --skip-plugins=ehrman-blog-discovery,ehrman-reviewer-tools --allow-root
if ($LASTEXITCODE -ne 0 -or $missing.Trim() -ne 'absent') { throw 'Missing core plugin did not fail safely.' }
Write-Output "Extracted developer-test ZIP passed comparison, regression, isolation and overwrite checks: $archive"
