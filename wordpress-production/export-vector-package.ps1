param(
    [string]$OutputPath = 'data/index/ehrman_post_embeddings.jsonl.gz'
)

$ErrorActionPreference = 'Stop'
$composeFile = Join-Path $PSScriptRoot 'compose.local.yaml'
$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$containerPath = '/tmp/ehrman_post_embeddings.jsonl.gz'

if ([System.IO.Path]::IsPathRooted($OutputPath)) {
    $destination = [System.IO.Path]::GetFullPath($OutputPath)
}
else {
    $destination = [System.IO.Path]::GetFullPath((Join-Path $repoRoot $OutputPath))
}

$destinationDirectory = Split-Path -Parent $destination
if (-not (Test-Path -LiteralPath $destinationDirectory -PathType Container)) {
    throw "Vector-package destination directory does not exist: $destinationDirectory"
}

$temporary = "$destination.tmp"
try {
    & docker compose -f $composeFile exec -T wordpress wp ehrman-discovery embeddings export `
        --file=$containerPath `
        --allow-root `
        --path=/var/www/html
    if ($LASTEXITCODE -ne 0) {
        throw 'The WordPress vector export failed.'
    }

    & docker compose -f $composeFile cp "wordpress:$containerPath" $temporary
    if ($LASTEXITCODE -ne 0) {
        throw 'The vector package could not be copied from WordPress.'
    }

    $package = Get-Item -LiteralPath $temporary
    if ($package.Length -le 0) {
        throw 'The exported vector package is empty.'
    }

    Move-Item -LiteralPath $temporary -Destination $destination -Force
    $completed = Get-Item -LiteralPath $destination
    Write-Output "Portable vector package: $($completed.FullName)"
    Write-Output "Compressed size: $($completed.Length) bytes"
}
finally {
    if (Test-Path -LiteralPath $temporary) {
        Remove-Item -LiteralPath $temporary -Force
    }
}
