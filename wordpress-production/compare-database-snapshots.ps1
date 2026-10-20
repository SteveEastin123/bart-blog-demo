param(
    [Parameter(Mandatory = $true)][string]$Before,
    [Parameter(Mandatory = $true)][string]$After,
    [switch]$IgnoreAutoIncrement
)

$ErrorActionPreference = 'Stop'
$source = Get-Content -LiteralPath $Before -Raw | ConvertFrom-Json
$target = Get-Content -LiteralPath $After -Raw | ConvertFrom-Json
$sourceNames = @($source.tables.PSObject.Properties.Name | Sort-Object)
$targetNames = @($target.tables.PSObject.Properties.Name | Sort-Object)
if (Compare-Object $sourceNames $targetNames) { throw 'The table lists differ.' }
$errors = @()
foreach ($name in $sourceNames) {
    $left = $source.tables.$name
    $right = $target.tables.$name
    foreach ($field in @('engine', 'collation', 'auto_increment', 'columns', 'indexes', 'rows', 'sha256', 'embedding_bytes')) {
        if ($IgnoreAutoIncrement -and $field -eq 'auto_increment') { continue }
        $a = ConvertTo-Json -InputObject $left.$field -Depth 12 -Compress
        $b = ConvertTo-Json -InputObject $right.$field -Depth 12 -Compress
        if ($a -cne $b) { $errors += "$name : $field differs" }
    }
}
if ($errors.Count) { throw ($errors -join "`n") }
Write-Output "Database parity: OK ($($sourceNames.Count) tables; schema, indexes, row counts, and binary-safe content hashes)."
