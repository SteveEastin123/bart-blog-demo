param([switch] $Disable)
$ErrorActionPreference = 'Stop'
$root = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '../../..')).Path
function Invoke-Compose {
    param([string[]] $Arguments)
    & docker compose -f wordpress-production/compose.local.yaml @Arguments
    if ($LASTEXITCODE -ne 0) { throw "Docker failed: $LASTEXITCODE" }
}
Push-Location $root
try {
    $url = (Invoke-Compose @('exec','-T','wordpress','wp','option','get','home','--allow-root','--path=/var/www/html')).Trim()
    $envType = (Invoke-Compose @('exec','-T','wordpress','wp','eval','echo wp_get_environment_type();','--allow-root','--path=/var/www/html')).Trim()
    if (([Uri]$url).Host -ne 'localhost' -or $envType -ne 'local') { throw 'Local benchmark only.' }
    if ($Disable) {
        Invoke-Compose @('exec','-T','wordpress','wp','option','update','ehrman_full_comparison_enabled','0','--allow-root','--path=/var/www/html')
        Invoke-Compose @('exec','-T','wordpress','wp','plugin','deactivate','ehrman-full-comparison','--allow-root','--path=/var/www/html')
        return
    }
    Invoke-Compose @('exec','-T','wordpress','wp','plugin','is-active','ehrman-section-pilot','--allow-root','--path=/var/www/html')
    Invoke-Compose @('exec','-T','wordpress','install','-d','-m','0750','-o','www-data','-g','www-data','/opt/ehrman-full-comparison/results')
    foreach ($file in @('index.json','sections-300.jsonl.gz')) {
        Invoke-Compose @('cp',"experiments/section-search/private/guest-access-runtime/$file","wordpress:/opt/ehrman-full-comparison/$file")
    }
    foreach ($file in @('questions.json','manifest.json')) {
        Invoke-Compose @('cp',"experiments/section-search/runs/full-corpus-250-2026-10-18/$file","wordpress:/opt/ehrman-full-comparison/$file")
    }
    Invoke-Compose @('cp','experiments/section-search/full-comparison/plugin/.','wordpress:/var/www/html/wp-content/plugins/ehrman-full-comparison/')
    Invoke-Compose @('exec','-T','wordpress','chown','-R','www-data:www-data','/opt/ehrman-full-comparison')
    Invoke-Compose @('exec','-T','wordpress','wp','plugin','activate','ehrman-full-comparison','--allow-root','--path=/var/www/html')
    Invoke-Compose @('exec','-T','wordpress','wp','option','update','ehrman_full_comparison_enabled','1','--allow-root','--path=/var/www/html')
} finally { Pop-Location }
