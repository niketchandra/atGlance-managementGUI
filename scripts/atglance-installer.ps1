<#
.SYNOPSIS
    AtGlance Community Edition installer for Windows (Docker Desktop).

.DESCRIPTION
    PowerShell port of atglance-installer.sh. Run from an elevated PowerShell:

      irm https://raw.githubusercontent.com/niketchandra/atGlance-managementGUI/main/scripts/atglance-installer.ps1 | iex

    or download it and run:

      .\atglance-installer.ps1 -Port 8000

    Prepares the host (administrator, CPU, memory, Docker Desktop, Docker Compose), then runs
    atglance_update.ps1, which deploys the newest release from docker-compose.yml: a new
    console, or an update of an existing one (data and passwords are kept). Neither script
    changes between releases.

    Options can also be set as environment variables (used when the script is
    piped to iex, where parameters cannot be passed):
      -Dir DIR          install directory        (ATGLANCE_DIR, default %ProgramData%\AtGlance)
      -Registry PREFIX  image registry/namespace (ATGLANCE_REGISTRY, default atglance = Docker Hub)
      -Port PORT        web console port         (APP_PORT, default 8000)
      -KeepClone        keep the git clone this script runs from (ATGLANCE_KEEP_CLONE=1);
                        by default a clean clone is removed after a successful run

    The Kong API gateway runs on port 8002 (GATEWAY_PORT). The atglance CLI talks to the
    gateway, so keep 8000 and 8002 unless you know you need other ports.

    Requires Docker Desktop (Linux containers) with the Compose plugin.
#>
[CmdletBinding()]
param(
    [string]$Dir,
    # Ignored: the installer always deploys "latest". Kept so older commands still run.
    [string]$Version,
    [string]$Registry,
    [int]$Port = 0,
    [switch]$KeepClone
)

# Native commands report failures through $LASTEXITCODE, which is checked explicitly
# (with 'Stop', Windows PowerShell 5.1 fails on any line a native command writes to stderr).
$ErrorActionPreference = 'Continue'

function Get-Setting($Value, $EnvName, $Default) {
    if ($Value) { return $Value }
    $fromEnv = [Environment]::GetEnvironmentVariable($EnvName)
    if ($fromEnv) { return $fromEnv }
    return $Default
}

# Folder of this script when run from a file (empty when piped to iex).
$ScriptDir = $PSScriptRoot
$KeepClone = $KeepClone -or ([Environment]::GetEnvironmentVariable('ATGLANCE_KEEP_CLONE') -eq '1')

# Empty values are left to the updater: the install's current value, else the compose default.
$AtglanceDir      = Get-Setting $Dir      'ATGLANCE_DIR'      ''
$requestedVersion = Get-Setting $Version  'ATGLANCE_VERSION'  'latest'
if ($requestedVersion -ne 'latest') {
    Write-Host "  ! -Version / ATGLANCE_VERSION is no longer supported; installing latest." -ForegroundColor Yellow
}
$AtglanceRegistry = Get-Setting $Registry 'ATGLANCE_REGISTRY' ''
$AtglanceRef      = Get-Setting $null     'ATGLANCE_REF'      'main'
$AppPort          = Get-Setting $(if ($Port) { "$Port" }) 'APP_PORT' ''
$RepoRaw          = "https://raw.githubusercontent.com/niketchandra/atGlance-managementGUI/$AtglanceRef"

$MinMemMb  = 1024
# The controller service uses "configs: content:", added in Docker Compose 2.23.
$MinCompose = [version]'2.23.0'

function Step($m) { Write-Host ""; Write-Host "==> $m" -ForegroundColor White }
function Ok($m)   { Write-Host "  " -NoNewline; Write-Host ([char]0x2713) -ForegroundColor Green -NoNewline; Write-Host " $m" }
function Warn($m) { Write-Host "  " -NoNewline; Write-Host "!" -ForegroundColor Yellow -NoNewline; Write-Host " $m" }
function Die($m)  { Write-Host "  $([char]0x2717) $m" -ForegroundColor Red; exit 1 }

function Test-Command($Name) { [bool](Get-Command $Name -ErrorAction SilentlyContinue) }

# ---------------------------------------------------------------------------
Step "Checking prerequisites"

$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$isAdmin = (New-Object Security.Principal.WindowsPrincipal($identity)).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $isAdmin) { Die "Run from an elevated PowerShell (Run as administrator)." }
Ok "Running as administrator on Windows"

$arch = $env:PROCESSOR_ARCHITECTURE
switch -Regex ($arch) {
    '^(AMD64|x86)$' { $Platform = 'amd64'; break }
    '^ARM64$'       { $Platform = 'arm64'; break }
    default         { Die "Unsupported CPU architecture: $arch. Use amd64 or arm64." }
}
Ok "Architecture $Platform"

$os = Get-CimInstance Win32_OperatingSystem
Ok "OS: $($os.Caption)"

$memMb = [int][math]::Floor($os.TotalVisibleMemorySize / 1024)
if ($memMb -lt $MinMemMb) { Warn "Memory: $memMb MB. $MinMemMb MB or more is recommended." }
else { Ok "Memory: $memMb MB" }

if ($AppPort -and $AppPort -ne '8000') {
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
$composeText = (docker compose version --short) -replace '^v', ''
$composeVersion = [version]((($composeText -split '[-+]')[0]))
if ($composeVersion -lt $MinCompose) {
    Die "Docker Compose $composeText is too old (need $MinCompose or newer). Update Docker Desktop, then re-run."
}
Ok "Docker Compose $composeText"

# ---------------------------------------------------------------------------
# Deploying is the updater's job: it reads everything from docker-compose.yml, so neither
# script changes between releases.
Step "Deploying AtGlance CE (latest) with atglance_update.ps1"

$updater = ''
if ($ScriptDir -and (Test-Path (Join-Path $ScriptDir 'atglance_update.ps1'))) {
    $updater = Join-Path $ScriptDir 'atglance_update.ps1'
} else {
    $updater = Join-Path ([IO.Path]::GetTempPath()) ("atglance_update-" + [guid]::NewGuid().ToString('N') + ".ps1")
    try { Invoke-WebRequest -UseBasicParsing "$RepoRaw/scripts/atglance_update.ps1" -OutFile $updater -ErrorAction Stop }
    catch { Die "Could not download $RepoRaw/scripts/atglance_update.ps1. Check the internet connection." }
}
Ok "Updater: $updater"

# Installer options become the settings the updater reads.
if ($AtglanceDir)      { $env:ATGLANCE_DIR = $AtglanceDir }
if ($AtglanceRegistry) { $env:ATGLANCE_REGISTRY = $AtglanceRegistry }
if ($AppPort)          { $env:APP_PORT = $AppPort }
$env:ATGLANCE_REF = $AtglanceRef
$env:ATGLANCE_YES = '1'
if ($KeepClone) { $env:ATGLANCE_KEEP_CLONE = '1' }

# The updater removes a clean git clone this script runs from (it is the updater's own clone).
& $updater
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
