param(
    [int]$Port = 8085,
    [string]$AdminUser = "admin",
    [string]$AdminPassword = "",
    [string]$AdminEmail = "admin@example.test"
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

Push-Location $PSScriptRoot

try {
    $env:WORDPRESS_PORT = [string]$Port
    $siteUrl = "http://localhost:$Port"
    $installedNow = $false

    & docker compose up -d mysql wordpress
    if ($LASTEXITCODE -ne 0) {
        throw "Docker Compose could not start WordPress and MySQL."
    }

    $ready = $false
    for ($attempt = 1; $attempt -le 45; $attempt++) {
        & docker compose exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqladmin ping -h 127.0.0.1 -u root --silent 2>/dev/null' *> $null
        if ($LASTEXITCODE -eq 0) {
            $ready = $true
            break
        }

        Start-Sleep -Seconds 2
    }

    if (-not $ready) {
        throw "MySQL did not become ready within 90 seconds."
    }

    & docker compose run --rm wpcli core is-installed --path=/var/www/html *> $null
    if ($LASTEXITCODE -ne 0) {
        if (-not $AdminPassword) {
            $AdminPassword = "local-" + [Guid]::NewGuid().ToString("N").Substring(0, 16)
        }

        & docker compose run --rm wpcli core install `
            --path=/var/www/html `
            "--url=$siteUrl" `
            "--title=Ehrman Blog Discovery Demo" `
            "--admin_user=$AdminUser" `
            "--admin_password=$AdminPassword" `
            "--admin_email=$AdminEmail" `
            --skip-email

        if ($LASTEXITCODE -ne 0) {
            throw "WordPress installation failed."
        }

        $installedNow = $true
    }

    & docker compose run --rm wpcli plugin activate ehrman-blog-discovery --path=/var/www/html
    if ($LASTEXITCODE -ne 0) {
        throw "The Ehrman Blog Discovery plugin could not be activated."
    }

    & docker compose run --rm wpcli theme activate ehrman-discovery-demo --path=/var/www/html
    if ($LASTEXITCODE -ne 0) {
        throw "The Ehrman Discovery Demo theme could not be activated."
    }

    $statusPageOutput = & docker compose run --rm wpcli post list `
        --path=/var/www/html `
        --post_type=page `
        --name=ehrman-discovery-status `
        --field=ID `
        --format=ids
    $statusPageId = if ($null -eq $statusPageOutput) { '' } else { ([string] $statusPageOutput).Trim() }

    if (-not $statusPageId) {
        & docker compose run --rm wpcli post create `
            --path=/var/www/html `
            --post_type=page `
            --post_status=publish `
            "--post_title=Discovery Status" `
            "--post_name=ehrman-discovery-status" `
            "--post_content=[ehrman_discovery_status]" *> $null

        if ($LASTEXITCODE -ne 0) {
            throw "The Discovery Status page could not be created."
        }
    }

    & docker compose run --rm wpcli rewrite structure '/%postname%/' --path=/var/www/html --hard *> $null
    if ($LASTEXITCODE -ne 0) {
        throw "WordPress permalink configuration failed."
    }

    Write-Host "WordPress: $siteUrl"
    Write-Host "Admin: $siteUrl/wp-admin/"
    Write-Host "Plugin status: $siteUrl/ehrman-discovery-status/"
    Write-Host "Keyword Search: $siteUrl/keyword-search/"
    Write-Host "Browse Topics 1: $siteUrl/browse-topics-1/"
    Write-Host "Browse Topics 2: $siteUrl/browse-topics-2/"
    Write-Host "REST status: $siteUrl/wp-json/ehrman-discovery/v1/status"

    if ($installedNow) {
        Write-Host "Local administrator: $AdminUser"
        Write-Host "Local administrator password: $AdminPassword"
    }
}
finally {
    Pop-Location
}
