<#
.SYNOPSIS
    AtGlance Community Edition installer for Windows (Docker Desktop).

.DESCRIPTION
    PowerShell port of install.sh. Run from an elevated PowerShell:

      irm https://raw.githubusercontent.com/niketchandra/atGlance-managementGUI/main/install.ps1 | iex

    or download it and run:

      .\install.ps1 -Port 8000 -Version latest

    Options can also be set as environment variables (used when the script is
    piped to iex, where parameters cannot be passed):
      -Dir DIR          install directory        (ATGLANCE_DIR, default %ProgramData%\AtGlance)
      -Version TAG      image tag for BOTH images (ATGLANCE_VERSION, default latest)
                        atglance/ce-atglance-app:TAG and atglance/ce-atglance-gateway:TAG
                        e.g. -Version 1.2.1 deploys that release instead of latest
      -Registry PREFIX  image registry/namespace (ATGLANCE_REGISTRY, default atglance = Docker Hub)
      -Port PORT        web console port         (APP_PORT, default 8000)

    The Kong API gateway always runs on port 8002 (GATEWAY_PORT). The atglance
    CLI talks to the gateway, so keep 8000 and 8002 unless you know you need
    other ports.

    Re-running the installer upgrades an existing install and keeps its data
    and passwords.

    Requires Docker Desktop (Linux containers) with the Compose plugin.
#>
[CmdletBinding()]
param(
    [string]$Dir,
    [string]$Version,
    [string]$Registry,
    [int]$Port = 0
)

$ErrorActionPreference = 'Stop'

function Get-Setting($Value, $EnvName, $Default) {
    if ($Value) { return $Value }
    $fromEnv = [Environment]::GetEnvironmentVariable($EnvName)
    if ($fromEnv) { return $fromEnv }
    return $Default
}

$AtglanceDir      = Get-Setting $Dir      'ATGLANCE_DIR'      (Join-Path $env:ProgramData 'AtGlance')
$AtglanceVersion  = Get-Setting $Version  'ATGLANCE_VERSION'  'latest'
$AtglanceRegistry = Get-Setting $Registry 'ATGLANCE_REGISTRY' 'atglance'
$AtglanceRef      = Get-Setting $null     'ATGLANCE_REF'      'main'
$AppPort          = [int](Get-Setting $(if ($Port) { $Port }) 'APP_PORT' 8000)
$GatewayPort      = [int](Get-Setting $null 'GATEWAY_PORT' 8002)
$RepoRaw          = "https://raw.githubusercontent.com/niketchandra/atGlance-managementGUI/$AtglanceRef"
# Local compose file instead of downloading one (testing a branch).
$ComposeFile      = Get-Setting $null 'ATGLANCE_COMPOSE_FILE' ''

$MinDiskGb = 5
$MinMemMb  = 1024

function Step($m) { Write-Host ""; Write-Host "==> $m" -ForegroundColor White }
function Ok($m)   { Write-Host "  " -NoNewline; Write-Host ([char]0x2713) -ForegroundColor Green -NoNewline; Write-Host " $m" }
function Warn($m) { Write-Host "  " -NoNewline; Write-Host "!" -ForegroundColor Yellow -NoNewline; Write-Host " $m" }
function Die($m)  { Write-Host "  $([char]0x2717) $m" -ForegroundColor Red; exit 1 }

function Test-Command($Name) { [bool](Get-Command $Name -ErrorAction SilentlyContinue) }

function Test-PortInUse([int]$P) {
    [bool](Get-NetTCPConnection -State Listen -LocalPort $P -ErrorAction SilentlyContinue)
}

# Our own containers hold the ports on an upgrade; that is fine.
function Test-PortOwnedByAtglance([int]$P) {
    if (-not (Test-Command docker)) { return $false }
    $lines = docker ps --format '{{.Names}} {{.Ports}}' 2>$null
    return [bool]($lines | Where-Object { $_ -like 'ce-atglance-*' -and $_ -match ":$P->" })
}

function New-RandomSecret {
    $chars = [char[]]'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789'
    $bytes = New-Object byte[] 32
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    -join ($bytes | ForEach-Object { $chars[$_ % $chars.Length] })
}

function Write-Utf8NoBom($Path, $Text) {
    [IO.File]::WriteAllText($Path, $Text, (New-Object Text.UTF8Encoding($false)))
}

# ---------------------------------------------------------------------------
Step "Checking prerequisites"

$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$isAdmin = (New-Object Security.Principal.WindowsPrincipal($identity)).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $isAdmin) { Die "Run from an elevated PowerShell (Run as administrator)." }
Ok "Running as administrator on Windows"

$arch = $env:PROCESSOR_ARCHITECTURE
switch -Regex ($arch) {
    '^(AMD64|x86)$' { Ok "Architecture amd64"; break }
    '^ARM64$'       { Ok "Architecture arm64"; break }
    default         { Die "Unsupported CPU architecture: $arch. Use amd64 or arm64." }
}

$os = Get-CimInstance Win32_OperatingSystem
Ok "OS: $($os.Caption)"

New-Item -ItemType Directory -Force -Path $AtglanceDir | Out-Null
$driveName = (Resolve-Path $AtglanceDir).Drive.Name
$diskGb = [int][math]::Floor((Get-PSDrive -Name $driveName).Free / 1GB)
if ($diskGb -lt $MinDiskGb) { Die "Need $MinDiskGb GB free in $AtglanceDir, found $diskGb GB." }
Ok "Disk: $diskGb GB free"

$memMb = [int][math]::Floor($os.TotalVisibleMemorySize / 1024)
if ($memMb -lt $MinMemMb) { Warn "Memory: $memMb MB. $MinMemMb MB or more is recommended." }
else { Ok "Memory: $memMb MB" }

$ports = @($AppPort, $GatewayPort)
foreach ($p in $ports) {
    if ((Test-PortInUse $p) -and -not (Test-PortOwnedByAtglance $p)) {
        Die "Port $p is in use. Free it or pick another with -Port."
    }
}
Ok "Ports free: $($ports -join ' ')"
if ($AppPort -ne 8000 -or $GatewayPort -ne 8002) {
    Warn "The atglance CLI expects ports 8000 and 8002. Other ports need a proxy in front."
}

# ---------------------------------------------------------------------------
Step "Checking Docker"

if (-not (Test-Command docker)) {
    if (Test-Command winget) {
        Warn "Docker not found. Installing Docker Desktop with winget"
        winget install -e --id Docker.DockerDesktop --accept-package-agreements --accept-source-agreements
        Die "Docker Desktop was installed. Start it, wait until it is running, then re-run this installer."
    }
    Die "Docker not found. Install Docker Desktop from https://www.docker.com/products/docker-desktop/ and re-run."
}

docker info *> $null
if ($LASTEXITCODE -ne 0) { Die "Docker is installed but the daemon is not running. Start Docker Desktop and re-run." }
$serverOs = (docker version --format '{{.Server.Os}}' 2>$null)
if ($serverOs -ne 'linux') { Die "Docker is in Windows-containers mode. Switch Docker Desktop to Linux containers and re-run." }
Ok "Docker $(docker version --format '{{.Server.Version}}')"

docker compose version *> $null
if ($LASTEXITCODE -ne 0) { Die "Docker Compose plugin not found. Update Docker Desktop, then re-run." }
Ok "Docker Compose $(docker compose version --short)"

# ---------------------------------------------------------------------------
Step "Preparing $AtglanceDir"

Set-Location $AtglanceDir

if ($ComposeFile) {
    Copy-Item -Force $ComposeFile docker-compose.yml
} else {
    Invoke-WebRequest -UseBasicParsing "$RepoRaw/docker-compose.yml" -OutFile docker-compose.yml.new
    Move-Item -Force docker-compose.yml.new docker-compose.yml
}
Ok "docker-compose.yml ready"

# Opt-in override for custom domains (not used by the install).
try {
    Invoke-WebRequest -UseBasicParsing "$RepoRaw/docker-compose.domain.yml" -OutFile docker-compose.domain.yml.new
    Move-Item -Force docker-compose.domain.yml.new docker-compose.domain.yml
} catch {
    Remove-Item -Force -ErrorAction SilentlyContinue docker-compose.domain.yml.new
    Warn "Could not download docker-compose.domain.yml (only needed for a custom domain later)."
}

function Set-EnvValue($Name, $Value) {
    $lines = @(Get-Content .env)
    $found = $false
    $lines = $lines | ForEach-Object {
        if ($_ -match "^$([regex]::Escape($Name))=") { $found = $true; "$Name=$Value" } else { $_ }
    }
    if (-not $found) { $lines += "$Name=$Value" }
    Write-Utf8NoBom (Join-Path (Get-Location) '.env') (($lines -join "`n") + "`n")
}

if (Test-Path .env) {
    Ok "Keeping existing .env (passwords unchanged)"
    Set-EnvValue ATGLANCE_VERSION  $AtglanceVersion
    Set-EnvValue ATGLANCE_REGISTRY $AtglanceRegistry
    Set-EnvValue APP_PORT          $AppPort
    Set-EnvValue GATEWAY_PORT      $GatewayPort
} else {
    $stamp = (Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ')
    $envText = @"
# AtGlance CE settings, created by install.ps1 on $stamp.
ATGLANCE_VERSION=$AtglanceVersion
ATGLANCE_REGISTRY=$AtglanceRegistry
APP_PORT=$AppPort
GATEWAY_PORT=$GatewayPort
DB_PASSWORD=$(New-RandomSecret)
DB_ROOT_PASSWORD=$(New-RandomSecret)
"@
    Write-Utf8NoBom (Join-Path (Get-Location) '.env') (($envText -replace "`r`n", "`n") + "`n")
    # Restrict .env to Administrators and SYSTEM (the equivalent of umask 077).
    icacls .env /inheritance:r /grant:r "*S-1-5-32-544:F" "*S-1-5-18:F" | Out-Null
    Ok "Created .env with random database passwords"
}

# ---------------------------------------------------------------------------
Step "Deploying AtGlance CE ($AtglanceVersion)"

Ok "Images: $AtglanceRegistry/ce-atglance-app:$AtglanceVersion, $AtglanceRegistry/ce-atglance-gateway:$AtglanceVersion"
foreach ($img in "$AtglanceRegistry/ce-atglance-app:$AtglanceVersion", "$AtglanceRegistry/ce-atglance-gateway:$AtglanceVersion") {
    docker pull $img
    if ($LASTEXITCODE -ne 0) { Die "Could not pull $img. Check the version tag exists." }
}
docker compose pull
if ($LASTEXITCODE -ne 0) { Die "docker compose pull failed." }
docker compose up -d --remove-orphans
if ($LASTEXITCODE -ne 0) { Die "docker compose up failed." }

function Wait-Healthy($Name) {
    $status = 'starting'
    Write-Host "  Waiting for $Name" -NoNewline
    for ($i = 0; $i -lt 90; $i++) {
        $status = (docker inspect -f '{{.State.Health.Status}}' $Name 2>$null)
        if ($LASTEXITCODE -ne 0 -or -not $status) { $status = 'starting' }
        if ($status -eq 'healthy') { break }
        Write-Host "." -NoNewline
        Start-Sleep -Seconds 2
    }
    Write-Host ""
    if ($status -ne 'healthy') {
        Die "$Name did not become healthy. Check: docker compose -f $AtglanceDir\docker-compose.yml logs"
    }
}
Wait-Healthy ce-atglance-app
Wait-Healthy ce-atglance-gateway
Ok "All containers running"

# ---------------------------------------------------------------------------
$hostIp = Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue |
    Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' -and $_.InterfaceAlias -notlike 'vEthernet*' } |
    Select-Object -First 1 -ExpandProperty IPAddress
if (-not $hostIp) { $hostIp = 'localhost' }

Write-Host ""
Write-Host "AtGlance CE is running." -ForegroundColor White
Write-Host ""
Write-Host "  Setup wizard:  http://${hostIp}:$AppPort"
Write-Host "  API gateway:   http://${hostIp}:$GatewayPort"
Write-Host "  CLI setup:     atglance --configure   (management URL: http://${hostIp}:$GatewayPort)"
Write-Host "  Install dir:   $AtglanceDir"
Write-Host ""
Write-Host "  Open ports $AppPort and $GatewayPort in Windows Firewall for users and servers."
Write-Host ""
Write-Host "  Manage:  cd $AtglanceDir; docker compose ps | logs -f | restart | down"
Write-Host "  Upgrade: re-run this installer"
Write-Host ""
Write-Host "  Back up the app key. It encrypts stored secrets and lives in the"
Write-Host "  atglance_app-storage volume, file .env:"
Write-Host "    docker exec ce-atglance-app grep APP_KEY /app/storage/.env"
