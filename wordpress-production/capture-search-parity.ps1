param(
    [Parameter(Mandatory = $true)][string]$OutputPath,
    [string]$ComposeFile = (Join-Path $PSScriptRoot 'compose.local.yaml')
)

$ErrorActionPreference = 'Stop'
$id = (& docker compose -f $ComposeFile ps -a -q wordpress).Trim()
if ($LASTEXITCODE -ne 0 -or -not $id) { throw 'A WordPress container is required, but it can be stopped.' }
$container = (& docker inspect $id | ConvertFrom-Json)[0]
if ($LASTEXITCODE -ne 0) { throw 'Could not inspect the WordPress container.' }
$networks = @($container.NetworkSettings.Networks.PSObject.Properties.Name)
if ($networks.Count -ne 1) { throw 'Expected one Compose network.' }
$script = (Resolve-Path (Join-Path $PSScriptRoot 'tests/search-database-parity.php')).Path
$arguments = @('run', '--rm', '--network', $networks[0], '--volumes-from', "${id}:ro", '--entrypoint', 'php', '-v', "${script}:/tmp/search-database-parity.php:ro")
$previous = @{}
try {
    foreach ($entry in $container.Config.Env) {
        $pair = $entry -split '=', 2
        if ($pair[0] -match '^(WORDPRESS_DB_|EHRMAN_|OPENAI_API_KEY)' -and $pair[0] -ne 'EHRMAN_MIGRATION_ISOLATED') {
            $previous[$pair[0]] = [Environment]::GetEnvironmentVariable($pair[0], 'Process')
            [Environment]::SetEnvironmentVariable($pair[0], $pair[1], 'Process')
            $arguments += @('--env', $pair[0])
        }
    }
    $json = & docker @arguments $container.Image /tmp/search-database-parity.php
    if ($LASTEXITCODE -ne 0) { throw 'Search parity capture failed.' }
    $data = ($json -join "`n") | ConvertFrom-Json
    $json | Set-Content -LiteralPath $OutputPath -Encoding utf8
    Write-Output "Captured $($data.case_count) deterministic cases: $OutputPath"
}
finally {
    foreach ($key in $previous.Keys) { [Environment]::SetEnvironmentVariable($key, $previous[$key], 'Process') }
}
