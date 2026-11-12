param(
    [string]$OutputPath = 'data/index/ehrman_post_embeddings.jsonl.gz',
    [string]$ComposeFile = (Join-Path $PSScriptRoot 'compose.local.yaml')
)

$ErrorActionPreference = 'Stop'
$composeFile = (Resolve-Path -LiteralPath $ComposeFile).Path
$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$containerPath = '/tmp/ehrman_post_embeddings.jsonl.gz'
$validationPath = '/tmp/ehrman-handoff-' + [guid]::NewGuid().ToString('N')

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

    # Validate against approved LOCAL JSON, never a Render database export.
    & docker compose -f $composeFile exec -T wordpress mkdir -p $validationPath
    if ($LASTEXITCODE -ne 0) { throw 'Could not prepare handoff validation.' }
    foreach ($name in @('ehrman_post_search_index.json', 'ehrman_post_topics.json', 'ehrman_post_categories.json', 'ehrman_post_subject_areas.json')) {
        & docker compose -f $composeFile cp (Join-Path $repoRoot "data/index/$name") "wordpress:$validationPath/$name"
        if ($LASTEXITCODE -ne 0) { throw "Could not copy approved JSON for validation: $name" }
    }
    & docker compose -f $composeFile cp $temporary "wordpress:$validationPath/ehrman_post_embeddings.jsonl.gz"
    if ($LASTEXITCODE -ne 0) { throw 'Could not prepare vector validation.' }
    & docker compose -f $composeFile exec -T -e EHRMAN_DISCOVERY_POST_SOURCE=json wordpress wp ehrman-discovery install-data `
        --data-dir=$validationPath --validate-only --allow-root --path=/var/www/html
    if ($LASTEXITCODE -ne 0) {
        throw 'Handoff validation failed. Unknown/test posts, missing vectors, or stale metadata must be resolved; the existing local vector package was not replaced.'
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
