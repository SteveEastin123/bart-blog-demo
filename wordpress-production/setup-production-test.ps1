param(
    [int]$Port = 8086
)

$ErrorActionPreference = 'Stop'
$composeFile = Join-Path $PSScriptRoot 'compose.local.yaml'
$env:WORDPRESS_PRODUCTION_TEST_PORT = [string]$Port
$env:WORDPRESS_SITE_URL = "http://localhost:$Port"

& docker compose -f $composeFile up -d --build
if ($LASTEXITCODE -ne 0) {
    throw 'The production-equivalent WordPress stack could not be built and started.'
}

$ready = $false
for ($attempt = 1; $attempt -le 60; $attempt++) {
    try {
        $response = Invoke-WebRequest -UseBasicParsing -Uri "http://localhost:$Port/healthz" -TimeoutSec 5
        if ($response.StatusCode -eq 200) {
            $ready = $true
            break
        }
    }
    catch {
        Start-Sleep -Seconds 2
    }
}

if (-not $ready) {
    throw 'The production-equivalent WordPress service did not become healthy within 120 seconds.'
}

& docker compose -f $composeFile exec -T wordpress initialize-ehrman-wordpress
if ($LASTEXITCODE -ne 0) {
    throw 'WordPress initialization or data import failed.'
}

Write-Output "Production-equivalent WordPress: http://localhost:$Port"
Write-Output "Keyword Search: http://localhost:$Port/keyword-search/"
Write-Output "Browse Topics 1: http://localhost:$Port/browse-topics-1/"
Write-Output "Browse Topics 2: http://localhost:$Port/browse-topics-2/"
