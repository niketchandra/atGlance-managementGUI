<#
.SYNOPSIS
    AtGlance Community Edition updater for Windows (Docker Desktop): deploys the newest
    release ("latest"). Updates an existing install, or deploys a new console when there is none.

.DESCRIPTION
    Download it and run it from an elevated PowerShell (Run as administrator):

      irm https://raw.githubusercontent.com/niketchandra/atGlance-managementGUI/main/scripts/atglance_update.ps1 -OutFile atglance_update.ps1
      powershell -ExecutionPolicy Bypass -File .\atglance_update.ps1

    or

      irm https://raw.githubusercontent.com/niketchandra/atGlance-managementGUI/main/scripts/atglance_update.ps1 | iex

    No .env file is needed, and this script names no service, image or setting of a release:
    everything comes from docker-compose.yml (see "Release contract" in INSTALLATION-UPDATE.md).
    Each compose variable is read from, in order: the environment, the install's .env, the
    containers of the install. Secrets (variables without a default, and *PASSWORD, *SECRET,
    *TOKEN, *_KEY variables) get a new random value on a new install, or when a release adds
    them. The values are written to .env, so plain "docker compose" commands keep working.

    Existing install: pre-checks -> database backup -> current image checksums -> new image
        checksums -> update all containers -> health checks -> database check against the backup.
    New install (no Docker volume of the compose project yet): pre-checks -> deploy -> health checks.

    Options (or environment variables when piped to iex):
      -Dir DIR         install directory (ATGLANCE_DIR; default: the folder the running
                       containers were deployed from, else %ProgramData%\AtGlance)
      -Force           redeploy even when the images are already the newest
      -Yes             do not ask for confirmation (ATGLANCE_YES=1)
      -SkipDbVerify    skip restoring the backup into a scratch database for the comparison
      -Keep N          backups to keep (default 5)
      -KeepClone       keep the git clone this script runs from (ATGLANCE_KEEP_CLONE=1);
                       by default a clean clone is removed after a successful run

    Exit codes: 0 updated, installed or already up to date, 1 stopped before changing anything,
                2 updated or installed but a check failed (rollback steps are printed on an update).
#>
[CmdletBinding()]
param(
    [string]$Dir,
    [switch]$Force,
    [switch]$Yes,
    [switch]$SkipDbVerify,
    [int]$Keep = 5,
    [switch]$KeepClone
)

# Native commands report failures through $LASTEXITCODE, which is checked explicitly.
$ErrorActionPreference = 'Continue'

function Get-Setting($Value, $EnvName, $Default) {
    if ($Value) { return $Value }
    $fromEnv = [Environment]::GetEnvironmentVariable($EnvName)
    if ($fromEnv) { return $fromEnv }
    return $Default
}

# Folder of this script when run from a file (empty when piped to iex); see Remove-Clone.
$ScriptDir   = $PSScriptRoot
$KeepClone   = $KeepClone -or ([Environment]::GetEnvironmentVariable('ATGLANCE_KEEP_CLONE') -eq '1')

$AtglanceDir = Get-Setting $Dir 'ATGLANCE_DIR' ''
$AtglanceRef = Get-Setting $null 'ATGLANCE_REF' 'main'
$RepoRaw     = "https://raw.githubusercontent.com/niketchandra/atGlance-managementGUI/$AtglanceRef"
# Local compose file instead of downloading one (testing a branch).
$ComposeFile = Get-Setting $null 'ATGLANCE_COMPOSE_FILE' ''
$AssumeYes   = $Yes -or ([Environment]::GetEnvironmentVariable('ATGLANCE_YES') -eq '1')
$MinCompose  = [version]'2.23.0'
$MinDiskGb   = 5
$HealthTimeoutS = 300
$ScratchDb   = 'atglance_update_verify'
# Compose files published in the repository: the main file, then the opt-in overlays.
$ComposeNames = @('docker-compose.yml', 'docker-compose.domain.yml')
# Image tag variable of the AtGlance images (rollback re-tags them).
$VersionVar  = 'ATGLANCE_VERSION'
$SecretRe    = '(PASSWORD|SECRET|TOKEN|_KEY)$'
# Containers with this label may be stopped (the console starts and stops them).
$OptionalLabel = 'com.atglance.optional'
$DbImageRe   = '(^|/)(mysql|mariadb|percona[^:/]*)(:|$)'
# Tables that change on their own (cache, queues, sessions, logs): fewer rows is not data loss.
$VolatileRe  = '^(cache|cache_locks|jobs|job_batches|failed_jobs|sessions|session_tokens)$|_(cache|locks|jobs|sessions|logs)$'
$Probe       = '__atglance_probe_'

$script:Warnings = 0
$script:Failures = 0
function Step($m) { Write-Host ""; Write-Host "==> $m" -ForegroundColor White }
function Ok($m)   { Write-Host "  " -NoNewline; Write-Host ([char]0x2713) -ForegroundColor Green -NoNewline; Write-Host " $m" }
function Warn($m) { Write-Host "  " -NoNewline; Write-Host "!" -ForegroundColor Yellow -NoNewline; Write-Host " $m"; $script:Warnings++ }
function Fail($m) { Write-Host "  $([char]0x2717) $m" -ForegroundColor Red; $script:Failures++ }
function Die($m)  { Write-Host "  $([char]0x2717) $m" -ForegroundColor Red; exit 1 }
function Short($v) { $v = "$v" -replace '^sha256:', ''; if ($v.Length -gt 12) { $v.Substring(0, 12) } else { $v } }

function New-RandomSecret {
    $chars = [char[]]'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789'
    $bytes = New-Object byte[] 32
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    -join ($bytes | ForEach-Object { $chars[$_ % $chars.Length] })
}
function Test-PortInUse([int]$P) { [bool](Get-NetTCPConnection -State Listen -LocalPort $P -ErrorAction SilentlyContinue) }

# ---------------------------------------------------------------------------
# Compose model. Everything about a release is read from its compose files.

# NAME -> has a default, for every ${NAME...} in the files ($${...} is an escaped literal).
function Get-ComposeVars([string[]]$Files) {
    $seen = @{}
    foreach ($f in $Files) {
        foreach ($line in (Get-Content -LiteralPath $f)) {
            $line = $line -replace '(^|\s)#.*$', ''
            foreach ($m in [regex]::Matches($line, '(?<!\$)\$\{([A-Za-z_][A-Za-z0-9_]*)([^}]*)\}')) {
                $n = $m.Groups[1].Value
                $d = $m.Groups[2].Value -match '^:?-'
                if (-not $seen.ContainsKey($n) -or -not $d) { $seen[$n] = $d }
            }
        }
    }
    return $seen
}
function Test-Secret($Name, $HasDefault) { return ((-not $HasDefault) -or ($Name -match $SecretRe)) }
# NAME -> DEFAULT for every ${NAME:-DEFAULT} / ${NAME-DEFAULT} in the files.
function Get-ComposeDefaults([string[]]$Files) {
    $defaults = @{}
    foreach ($f in $Files) {
        foreach ($line in (Get-Content -LiteralPath $f)) {
            $line = $line -replace '(^|\s)#.*$', ''
            foreach ($m in [regex]::Matches($line, '(?<!\$)\$\{([A-Za-z_][A-Za-z0-9_]*):?-([^}]*)\}')) { $defaults[$m.Groups[1].Value] = $m.Groups[2].Value }
        }
    }
    return $defaults
}

# The rendered compose files: project name and, per service, image, environment, published
# ports and the optional label. $Overrides are set as environment variables for the render only.
function Get-ComposeConfig([string[]]$ComposeArgs, [hashtable]$Overrides) {
    $saved = @{}
    foreach ($k in $Overrides.Keys) {
        $saved[$k] = [Environment]::GetEnvironmentVariable($k)
        [Environment]::SetEnvironmentVariable($k, $Overrides[$k])
    }
    try { $json = docker compose @ComposeArgs config --format json 2>$null; $code = $LASTEXITCODE }
    finally { foreach ($k in $saved.Keys) { [Environment]::SetEnvironmentVariable($k, $saved[$k]) } }
    if ($code -ne 0 -or -not $json) { return $null }
    try { return (($json -join "`n") | ConvertFrom-Json) } catch { return $null }
}
function Get-ComposeModel([string[]]$ComposeArgs, [hashtable]$Overrides) {
    $cfg = Get-ComposeConfig $ComposeArgs $Overrides
    if (-not $cfg) { return $null }
    $services = [ordered]@{}
    foreach ($p in $cfg.services.PSObject.Properties) {
        $s = $p.Value
        $envMap = @{}
        if ($s.environment) { foreach ($e in $s.environment.PSObject.Properties) { $envMap[$e.Name] = "$($e.Value)" } }
        $ports = @()
        foreach ($pt in @($s.ports)) { if ($pt -and $pt.published) { $ports += "$($pt.published)" } }
        $optional = $false
        if ($s.labels -and $s.labels.PSObject.Properties[$OptionalLabel]) { $optional = ("$($s.labels.$OptionalLabel)" -eq 'true') }
        $services[$p.Name] = [pscustomobject]@{ Name = $p.Name; Image = "$($s.image)"; Env = $envMap; Ports = $ports; Optional = $optional }
    }
    return [pscustomobject]@{ Name = "$($cfg.name)"; Services = $services }
}

function Get-ProjectContainers { @(docker ps -a --filter "label=com.docker.compose.project=$Project" --format '{{.Names}}' 2>$null | Where-Object { $_ }) }
function Get-SvcContainer($Svc) {
    $n = @(docker ps -a --filter "label=com.docker.compose.project=$Project" --filter "label=com.docker.compose.service=$Svc" --format '{{.Names}}' 2>$null | Where-Object { $_ })
    if ($n.Count -gt 0) { return "$($n[0])".Trim() } else { return '' }
}
# Labels as an object ({{json}}: Windows PowerShell 5.1 drops inner double quotes of {{index ...}}).
function Get-ContainerLabels($Name) {
    $j = docker inspect -f '{{json .Config.Labels}}' $Name 2>$null
    if ($LASTEXITCODE -eq 0 -and $j) { try { return ("$j" | ConvertFrom-Json) } catch { } }
    return $null
}
# One variable from a container's environment (running or stopped); '' when absent.
function Get-ContainerEnv($Name, $Var) {
    $lines = docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' $Name 2>$null
    if ($LASTEXITCODE -ne 0) { return '' }
    $line = @($lines | Where-Object { "$_".StartsWith("$Var=") }) | Select-Object -First 1
    if ($line) { return "$line".Substring($Var.Length + 1) } else { return '' }
}
function Get-ContainerImageId($Name) { $id = docker inspect -f '{{.Image}}' $Name 2>$null; if ($LASTEXITCODE -eq 0) { "$id".Trim() } else { '' } }
function Get-ImageDigest($Ref) {
    $d = @(docker image inspect -f '{{range .RepoDigests}}{{println .}}{{end}}' $Ref 2>$null | Where-Object { $_ })
    if ($d.Count -gt 0) { ("$($d[0])".Trim() -split '@')[-1] } else { '' }
}
function Test-Running($Name) { (docker inspect -f '{{.State.Running}}' $Name 2>$null) -eq 'true' }
function Get-Health($Name) {
    $h = docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' $Name 2>$null
    if ($LASTEXITCODE -ne 0 -or -not $h) { 'missing' } else { "$h".Trim() }
}
function Wait-Healthy($Name) {
    Write-Host "  Waiting for $Name" -NoNewline
    $status = 'starting'
    for ($i = 0; $i -lt ($HealthTimeoutS / 3); $i++) {
        $status = Get-Health $Name
        if ($status -eq 'healthy') { break }
        Write-Host "." -NoNewline
        Start-Sleep -Seconds 3
    }
    Write-Host ""
    return ($status -eq 'healthy')
}
function Get-HttpCode([string]$Url) {
    $code = curl.exe -s -o NUL -w '%{http_code}' --max-time 10 $Url 2>$null
    if (-not $code) { '000' } else { "$code".Trim() }
}
# The app container: the one started with ATGLANCE_ROLE=app (holds APP_KEY and runs migrations).
function Find-AppContainer {
    foreach ($svc in $Model.Services.Keys) {
        $c = Get-SvcContainer $svc
        if ($c -and (Get-ContainerEnv $c 'ATGLANCE_ROLE') -eq 'app') { return $c }
    }
    return ''
}

# SQL as root inside the database container; the password stays in the container (MYSQL_PWD).
# Windows PowerShell 5.1 drops inner double quotes when passing arguments to programs, so the SQL is
# sent base64-encoded (one argument, no quotes or spaces) and no command below uses inner double quotes.
function Invoke-DbSql([string]$Sql) {
    $b64 = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($Sql))
    $out = docker exec $script:DbContainer sh -c 'echo $0 | base64 -d | MYSQL_PWD=${MYSQL_ROOT_PASSWORD:-$MARIADB_ROOT_PASSWORD} mysql -uroot -N -B' $b64 2>$null
    return @($out | Where-Object { $_ -ne $null -and "$_".Trim() -ne '' })
}
# "table count" lines for every base table of a database, sorted by table name.
function Get-TableCounts([string]$Db) {
    $tables = Invoke-DbSql "SELECT table_name FROM information_schema.tables WHERE table_schema = '$Db' AND table_type = 'BASE TABLE' ORDER BY table_name"
    foreach ($t in $tables) {
        $n = (Invoke-DbSql "SELECT COUNT(*) FROM ``$Db``.``$t``") | Select-Object -First 1
        "$t $n"
    }
}

# .env is optional: a missing file or a missing line gives the default.
function Read-EnvValue($Name, $Default) {
    if (-not (Test-Path .env)) { return $Default }
    $line = Get-Content .env | Where-Object { $_ -match "^$([regex]::Escape($Name))=" } | Select-Object -Last 1
    if (-not $line) { return $Default }
    $v = $line.Substring($Name.Length + 1)
    if ($v -match '^"(.*)"$' -or $v -match "^'(.*)'$") { $v = $Matches[1] }
    return $v
}
function Set-EnvValue($Name, $Value) {
    $lines = @()
    if (Test-Path .env) { $lines = @(Get-Content .env) }
    else { $lines = @("# AtGlance CE settings, written by atglance_update.ps1 on $((Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ')).") }
    $found = $false
    $lines = @($lines | ForEach-Object { if ($_ -match "^$([regex]::Escape($Name))=") { $found = $true; "$Name=$Value" } else { $_ } })
    if (-not $found) { $lines += "$Name=$Value" }
    [IO.File]::WriteAllText((Join-Path (Get-Location) '.env'), (($lines -join "`n") + "`n"), (New-Object Text.UTF8Encoding($false)))
}

function Get-RollbackText {
    $compose = ($script:DirArgs -join ' ')
    $text = @"
Rollback (the previous images are tagged :$RollbackTag, the backup is in $BackupDir):
  1. Previous containers (elevated PowerShell):
       cd "$AtglanceDir"
       Copy-Item -Force "$BackupDir\docker-compose*.yml" .
       (keep the current .env: it holds the passwords the database uses now)
       `$e = @(Get-Content .env | Where-Object { `$_ -notmatch '^$VersionVar=' }) + '$VersionVar=$RollbackTag'; Set-Content .env `$e
       docker compose $compose up -d --remove-orphans
"@
    if ($script:DbContainer) {
        $text += @"

  2. Only if data is wrong, restore the database (replaces ALL current data):
       docker cp "$BackupDir\$DbName.sql" ${script:DbContainer}:/tmp/restore.sql
       docker exec $script:DbContainer sh -c 'MYSQL_PWD=`$MYSQL_ROOT_PASSWORD mysql -uroot $DbName < /tmp/restore.sql && rm /tmp/restore.sql'
     ($DbName.sql is inside $DbName.sql.gz: Expand it with 7-Zip, or run
       docker run --rm -v "${BackupDir}:/b" alpine gunzip -k /b/$DbName.sql.gz )
"@
    }
    $text += @"

  3. Later, return to normal updates: set $VersionVar=latest in $AtglanceDir\.env
"@
    return $text
}

# ---------------------------------------------------------------------------
# Clone clean-up. When this script runs from a git clone of the AtGlance repository
# (git clone ...; .\scripts\atglance_update.ps1), the clone is not needed once AtGlance is running:
# everything lives in the install directory and the Docker volumes. It is removed only
# after a successful run, and only when it holds nothing of the user's own:
# no uncommitted or untracked files, no stash, no commits that are not on the remote.
# -KeepClone (ATGLANCE_KEEP_CLONE=1) keeps it.
function Remove-Clone {
    $ErrorActionPreference = 'Continue'
    if ($KeepClone) { return }
    if (-not $ScriptDir) { return }                        # piped to iex: no clone
    if (-not (Get-Command git -ErrorAction SilentlyContinue)) { return }
    $git = { git -c safe.directory=* -C $ScriptDir @args 2>$null }
    $top = & $git rev-parse --show-toplevel
    if ($LASTEXITCODE -ne 0 -or -not $top) { return }
    $top = [IO.Path]::GetFullPath("$top".Trim()).TrimEnd('\', '/')
    if ((& $git remote get-url origin) -notmatch 'niketchandra/atGlance-managementGUI') { return }
    if (-not (Test-Path -LiteralPath $AtglanceDir)) { return }
    $install = [IO.Path]::GetFullPath($AtglanceDir).TrimEnd('\', '/')
    # Never a drive root or a profile folder.
    if ($top -eq [IO.Path]::GetPathRoot($top).TrimEnd('\', '/')) { return }
    if (@($env:USERPROFILE, $env:PUBLIC, $env:ProgramData, $env:SystemRoot) -contains $top) { return }
    if (("$install\").StartsWith("$top\", [StringComparison]::OrdinalIgnoreCase)) { Warn "Kept the clone ${top}: the install directory is inside it."; return }
    if (("$top\").StartsWith("$install\", [StringComparison]::OrdinalIgnoreCase)) { return }
    if ((& $git status --porcelain) -or (& $git stash list) -or (& $git log --branches --not --remotes --oneline)) {
        Warn "Kept the clone ${top}: it has local changes or commits. Remove it yourself when done: Remove-Item -Recurse -Force '$top'"
        return
    }
    Set-Location -LiteralPath $env:SystemDrive\
    try {
        Remove-Item -LiteralPath $top -Recurse -Force -ErrorAction Stop
        Ok "Removed the clone $top (not needed any more; -KeepClone keeps it)"
    } catch {
        Warn "Could not remove the clone ${top}: $($_.Exception.Message)"
    }
}

# ---------------------------------------------------------------------------
Step "Checking Docker"

$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$IsAdmin = (New-Object Security.Principal.WindowsPrincipal($identity)).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not (Get-Command docker -ErrorAction SilentlyContinue)) { Die "Docker is not installed. Run atglance-installer.ps1, which installs Docker Desktop." }
if (-not (Get-Command curl.exe -ErrorAction SilentlyContinue)) { Die "curl.exe is required (included in Windows 10 1803 and later)." }
docker info *> $null
if ($LASTEXITCODE -ne 0) { Die "Docker Desktop is not running. Start it and re-run." }
if ((docker version --format '{{.Server.Os}}' 2>$null) -ne 'linux') { Die "Docker is in Windows-containers mode. Switch Docker Desktop to Linux containers." }
$composeText = "$(docker compose version --short 2>$null)".Trim() -replace '^v', ''
if (-not $composeText) { Die "Docker Compose plugin not found. Update Docker Desktop." }
if ([version](($composeText -split '[-+]')[0]) -lt $MinCompose) { Die "Docker Compose $composeText is too old (need $MinCompose or newer). Update Docker Desktop." }
Ok "Docker Compose $composeText"

# ---------------------------------------------------------------------------
Step "Checking the release and the install"

$NewDir = Join-Path ([IO.Path]::GetTempPath()) ("atglance-release-" + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Force -Path $NewDir | Out-Null
$fetched = $true
foreach ($f in $ComposeNames) {
    $dest = Join-Path $NewDir $f
    try {
        if ($f -eq 'docker-compose.yml' -and $ComposeFile) { Copy-Item -Force $ComposeFile $dest -ErrorAction Stop }
        else { Invoke-WebRequest -UseBasicParsing "$RepoRaw/$f" -OutFile $dest -ErrorAction Stop }
    } catch {
        Remove-Item -Force -ErrorAction SilentlyContinue $dest
        if ($f -eq 'docker-compose.yml') { $fetched = $false }
    }
}
$NewMain = Join-Path $NewDir 'docker-compose.yml'
if ($fetched) {
    docker compose -f $NewMain config --quiet *> $null
    if ($LASTEXITCODE -ne 0) {
        Remove-Item -Force $NewMain; $fetched = $false
        Warn "The newest docker-compose.yml does not load with Docker Compose $composeText (too old, or a download problem)."
    }
}

# Install directory: -Dir / ATGLANCE_DIR, else the folder the project's containers were deployed from.
$Project = ''
if ($fetched) { $m = Get-ComposeModel @('-f', $NewMain) @{}; if ($m) { $Project = $m.Name } }
if (-not $AtglanceDir -and $Project) {
    $any = @(Get-ProjectContainers) | Select-Object -First 1
    if ($any) {
        $wd = (Get-ContainerLabels $any).'com.docker.compose.project.working_dir'
        if ($wd -and (Test-Path -LiteralPath $wd)) { $AtglanceDir = $wd }
    }
}
if (-not $AtglanceDir) { $AtglanceDir = Join-Path $env:ProgramData 'AtGlance' }
try { New-Item -ItemType Directory -Force -Path $AtglanceDir -ErrorAction Stop | Out-Null }
catch { Die "Cannot create $AtglanceDir. Run from an elevated PowerShell (Run as administrator)." }
Set-Location $AtglanceDir
# The default install (ProgramData) is writable by administrators only.
try { [IO.File]::WriteAllText((Join-Path $AtglanceDir '.write-test'), 'x'); Remove-Item -Force (Join-Path $AtglanceDir '.write-test') }
catch { Die "Cannot write to $AtglanceDir. Run from an elevated PowerShell (Run as administrator)." }
Ok "Install directory $AtglanceDir"

if (-not $fetched) {
    if (-not (Test-Path docker-compose.yml)) { Die "Could not download docker-compose.yml from $RepoRaw. Check the internet connection." }
    Warn "Using the current docker-compose.yml (the newest one could not be used)."
    Copy-Item -Force docker-compose.yml $NewMain
}
if (-not $Project) { $m = Get-ComposeModel @('--project-directory', $AtglanceDir, '-f', $NewMain) @{}; if ($m) { $Project = $m.Name } }
if (-not $Project) { Die "docker-compose.yml does not load with Docker Compose $composeText." }

# Overlays the install was deployed with (custom domain, ...) stay in use.
$Files = @('docker-compose.yml')
$any = @(Get-ProjectContainers) | Select-Object -First 1
if ($any) {
    $used = "$((Get-ContainerLabels $any).'com.docker.compose.project.config_files')"
    foreach ($p in ($used -split ',')) {
        if (-not $p) { continue }
        $b = Split-Path -Leaf $p
        if ($b -eq 'docker-compose.yml' -or $Files -contains $b) { continue }
        if (-not (Test-Path (Join-Path $NewDir $b))) { if (Test-Path $b) { Copy-Item -Force $b (Join-Path $NewDir $b) } else { continue } }
        $Files += $b; Ok "Compose file $b in use: kept"
    }
}
$NewArgs = @('--project-directory', $AtglanceDir); $script:DirArgs = @()
foreach ($f in $Files) { $NewArgs += @('-f', (Join-Path $NewDir $f)); $script:DirArgs += @('-f', $f) }

# No Docker volume of the project yet: a new install.
$Fresh = -not (@(docker volume ls -q --filter "label=com.docker.compose.project=$Project" 2>$null | Where-Object { $_ }).Count -gt 0)
if ($Fresh) { Ok "No AtGlance install found: a new console will be deployed" } else { Ok "Existing install (compose project $Project)" }

# ---------------------------------------------------------------------------
# Settings: every variable of the compose files, without a required .env.
$Vars = Get-ComposeVars @($Files | ForEach-Object { Join-Path $NewDir $_ })
$probeEnv = @{ $VersionVar = "$Probe${VersionVar}__" }
$m = Get-ComposeModel $NewArgs @{}
if (-not $m) { Die "docker-compose.yml does not load with Docker Compose $composeText." }
$DbSvc = ''
foreach ($s in $m.Services.Values) { if ($s.Image -match $DbImageRe) { $DbSvc = $s.Name; break } }

# Where a variable lands in the rendered compose files, found by rendering it with a marker value
# (a path or a number when a word does not load, as for bind mounts and ports). Database service first.
function Get-VarUses($Var) {
    $cfg = $null; $mark = ''
    foreach ($mk in @("$Probe${Var}__", "/$Probe${Var}__", "$(Get-Random -Minimum 61000 -Maximum 65000)")) {
        $cfg = Get-ComposeConfig $NewArgs @{ $Var = $mk }
        if ($cfg) { $mark = $mk; break }
    }
    $uses = @()
    if (-not $cfg) { return $uses }
    foreach ($p in $cfg.services.PSObject.Properties) {
        $svc = $p.Name; $s = $p.Value
        if ($s.environment) {
            foreach ($e in $s.environment.PSObject.Properties) {
                if ("$($e.Value)" -eq $mark) { $uses += [pscustomobject]@{ Kind = 'E'; Svc = $svc; A = $e.Name; Tmpl = ''; Mark = $mark; Db = ($svc -eq $DbSvc) } }
            }
        }
        foreach ($pt in @($s.ports)) {
            if ($pt -and "$($pt.published)" -eq $mark) { $uses += [pscustomobject]@{ Kind = 'P'; Svc = $svc; A = "$($pt.target)"; Tmpl = ''; Mark = $mark; Db = $false } }
        }
        if ("$($s.image)".Contains($mark)) { $uses += [pscustomobject]@{ Kind = 'I'; Svc = $svc; A = ''; Tmpl = "$($s.image)"; Mark = $mark; Db = $false } }
        foreach ($vol in @($s.volumes)) {
            if ($vol -and "$($vol.source)".Contains($mark)) { $uses += [pscustomobject]@{ Kind = 'V'; Svc = $svc; A = "$($vol.target)"; Tmpl = "$($vol.source)"; Mark = $mark; Db = $false } }
        }
    }
    return @($uses | Sort-Object -Property @{ Expression = 'Db'; Descending = $true })
}
# The part of $Actual that the marker stands for in $Tmpl ('' when $Actual does not fit the template).
function Get-TemplateValue($Tmpl, $Mark, $Actual) {
    $i = $Tmpl.IndexOf($Mark); $pre = $Tmpl.Substring(0, $i); $suf = $Tmpl.Substring($i + $Mark.Length)
    if ($Actual.Length -ge ($pre.Length + $suf.Length) -and $Actual.StartsWith($pre) -and $Actual.EndsWith($suf)) {
        return $Actual.Substring($pre.Length, $Actual.Length - $pre.Length - $suf.Length)
    }
    return ''
}
# Value of a variable as the existing containers got it.
function Get-VarFromContainers($Uses) {
    foreach ($u in $Uses) {
        $c = Get-SvcContainer $u.Svc
        if (-not $c) { continue }
        $v = ''
        switch ($u.Kind) {
            'E' { $v = Get-ContainerEnv $c $u.A }
            'P' { foreach ($l in @(docker port $c $u.A 2>$null)) { if ("$l" -match ':(\d+)\s*$') { $v = $Matches[1]; break } } }
            'I' { $v = Get-TemplateValue $u.Tmpl $u.Mark "$(docker inspect -f '{{.Config.Image}}' $c 2>$null)".Trim() }
            'V' {
                $mounts = docker inspect -f '{{json .Mounts}}' $c 2>$null
                try { $src = @(("$mounts" | ConvertFrom-Json) | Where-Object { $_.Destination -eq $u.A } | Select-Object -First 1).Source } catch { $src = '' }
                if ($src) { $v = Get-TemplateValue $u.Tmpl $u.Mark "$src" }
            }
        }
        if ($v) { return $v }
    }
    return ''
}

# Variables of the compose files the install runs now ($null when it has none): a secret
# missing from them is new in this release.
$OldVars = $null
if (-not $Fresh -and (Test-Path docker-compose.yml)) {
    $oldFiles = @($Files | Where-Object { Test-Path $_ } | ForEach-Object { Join-Path $AtglanceDir $_ })
    $OldVars = Get-ComposeVars $oldFiles
    $OldDefaults = Get-ComposeDefaults $oldFiles
}
# Secrets that still hold the default of the old compose files (an install started without
# .env): replaced after the update, see "Replacing default passwords".
$RotateUses = @{}

$missing = @(); $generated = 0; $recovered = 0
$envExisted = Test-Path .env
foreach ($n in ($Vars.Keys | Sort-Object)) {
    if ($n -eq $VersionVar) { continue }
    $v = [Environment]::GetEnvironmentVariable($n)
    if (-not $v) { $v = Read-EnvValue $n '' }
    $uses = @()
    if (-not $v -and -not $Fresh) {
        $uses = Get-VarUses $n
        $v = Get-VarFromContainers $uses
        if ($v) { $recovered++ }
    }
    if (-not $v -and (Test-Secret $n $Vars[$n])) {
        # A new install, or a secret this release adds: a new value. A secret the install
        # already uses but whose value is lost (no .env, no containers) cannot be replaced.
        $newSecret = $false
        if ($Fresh) { $newSecret = $true }
        elseif ($null -ne $OldVars) { $newSecret = -not $OldVars.ContainsKey($n) }
        else { $newSecret = ($any -and -not (@($uses | Where-Object { Get-SvcContainer $_.Svc }).Count -gt 0)) }
        if ($newSecret) { $v = New-RandomSecret; $generated++ }
        else { $missing += $n }
    }
    # Not set anywhere and not a secret: the compose default applies.
    if (-not $v) { continue }
    if ($null -ne $OldVars -and (Test-Secret $n $Vars[$n]) -and $OldDefaults.ContainsKey($n) -and $OldDefaults[$n] -eq $v) {
        if ($uses.Count -eq 0) { $uses = Get-VarUses $n }
        $RotateUses[$n] = $uses
        Warn "$n is still the default of the old docker-compose.yml: it is replaced after the update"
    }
    [Environment]::SetEnvironmentVariable($n, $v, 'Process')
    if ((Read-EnvValue $n '') -ne $v) { Set-EnvValue $n $v }
}
if ($missing.Count -gt 0) {
    Die ("Found the AtGlance volumes but not the value of: $($missing -join ' ') (no .env and no containers to read it from).`n" +
         "    Re-run with -Dir pointing at the original install folder, or set those variables for this run.")
}
# .env holds the database passwords: Administrators and SYSTEM only.
if ((Test-Path .env) -and -not $envExisted -and $IsAdmin) { icacls .env /inheritance:r /grant:r "*S-1-5-32-544:F" "*S-1-5-18:F" | Out-Null }
[Environment]::SetEnvironmentVariable($VersionVar, 'latest', 'Process')
Ok "Settings: $AtglanceDir\.env ($generated generated, $recovered read from the containers)"

$Model = Get-ComposeModel $NewArgs @{}
if (-not $Model -or $Model.Services.Count -eq 0) { Die "docker-compose.yml has no services." }

if ($Fresh) {
    $diskGb = [int][math]::Floor((Get-PSDrive -Name (Resolve-Path $AtglanceDir).Drive.Name).Free / 1GB)
    if ($diskGb -lt $MinDiskGb) { Die "Need $MinDiskGb GB free in $AtglanceDir, found $diskGb GB." }
    Ok "Disk: $diskGb GB free"
    $ports = @($Model.Services.Values | ForEach-Object { $_.Ports } | Sort-Object -Unique)
    foreach ($p in $ports) { if (Test-PortInUse ([int]$p)) { Die "Port $p is in use. Free it (or set its port variable, see docker-compose.yml) and re-run." } }
    Ok "Ports free: $($ports -join ' ')"
}

$script:DbContainer = ''; $DbName = ''
if (-not $Fresh) {
    if (-not $DbSvc) {
        Warn "No MySQL/MariaDB service in docker-compose.yml: the database backup and checks are skipped."
    } else {
        $script:DbContainer = Get-SvcContainer $DbSvc
        if (-not $script:DbContainer -or -not (Test-Running $script:DbContainer)) {
            Warn "The database is not running: starting it for the backup"
            if ($script:DbContainer) { docker start $script:DbContainer *> $null }
            else { docker compose @NewArgs up -d --no-deps $DbSvc *> $null; $script:DbContainer = Get-SvcContainer $DbSvc }
            if (-not $script:DbContainer -or -not (Wait-Healthy $script:DbContainer)) { Die "The database did not start. Check: cd $AtglanceDir; docker compose logs $DbSvc" }
        }
        $DbName = Get-ContainerEnv $script:DbContainer 'MYSQL_DATABASE'
        if (-not $DbName) { $DbName = Get-ContainerEnv $script:DbContainer 'MARIADB_DATABASE' }
        if (-not $DbName) { Die "Cannot tell the database name (MYSQL_DATABASE of $script:DbContainer)." }
        Ok "Database $DbName in $script:DbContainer"

        $dbSizeMb = (Invoke-DbSql "SELECT CEIL(SUM(data_length + index_length) / 1024 / 1024) FROM information_schema.tables WHERE table_schema = '$DbName'") | Select-Object -First 1
        if (-not $dbSizeMb -or $dbSizeMb -eq 'NULL') { $dbSizeMb = 0 }
        $freeMb = [int][math]::Floor((Get-PSDrive -Name (Resolve-Path $AtglanceDir).Drive.Name).Free / 1MB)
        $needMb = [int]$dbSizeMb * 3 + 2048
        if ($freeMb -lt $needMb) { Die "Need about $needMb MB free in $AtglanceDir (database $dbSizeMb MB + new images), found $freeMb MB." }
        Ok "Disk: $freeMb MB free, database $dbSizeMb MB"
    }
}

$OldId = @{}; $NewId = @{}
$Stamp = (Get-Date).ToUniversalTime().ToString('yyyyMMdd-HHmmss')
# Each backup has its own image tag, so an older backup still rolls back to its own version.
$RollbackTag = "rollback-$Stamp"
$BackupDir = Join-Path $AtglanceDir "backups\update-$Stamp"
$RollbackRepos = @()

if (-not $Fresh) {
    # -------------------------------------------------------------------------
    Step "1. Backing up"

    New-Item -ItemType Directory -Force -Path $BackupDir | Out-Null
    if ($script:DbContainer) {
        # Dumped inside the container and copied out with docker cp: byte-exact (no PowerShell text pipeline).
        docker exec $script:DbContainer sh -c "MYSQL_PWD=`${MYSQL_ROOT_PASSWORD:-`$MARIADB_ROOT_PASSWORD} mysqldump -uroot --single-transaction --quick --routines --triggers --no-tablespaces --set-gtid-purged=OFF $DbName > /tmp/atglance-update.sql"
        if ($LASTEXITCODE -ne 0) { Die "mysqldump failed. Nothing was changed." }
        $sqlPath = Join-Path $BackupDir "$DbName.sql"
        docker cp "${script:DbContainer}:/tmp/atglance-update.sql" $sqlPath *> $null
        if ($LASTEXITCODE -ne 0) { Die "Could not copy the backup out of the database container. Nothing was changed." }
        if ((Get-Content $sqlPath -Tail 1) -notmatch 'Dump completed') { Die "The backup did not finish (no 'Dump completed' marker). Nothing was changed." }

        $gzPath = "$sqlPath.gz"
        $in = [IO.File]::OpenRead($sqlPath)
        $out = [IO.File]::Create($gzPath)
        $gz = New-Object IO.Compression.GZipStream($out, [IO.Compression.CompressionMode]::Compress)
        try { $in.CopyTo($gz) } finally { $gz.Dispose(); $out.Dispose(); $in.Dispose() }
        Remove-Item -Force $sqlPath

        Get-TableCounts $DbName | Set-Content (Join-Path $BackupDir 'row-counts.txt')
        $beforeCounts = @(Get-Content (Join-Path $BackupDir 'row-counts.txt'))
        if ($beforeCounts.Count -eq 0) { Die "Could not read the database tables. Nothing was changed." }
        Ok "Database: $gzPath ($([math]::Round((Get-Item $gzPath).Length / 1KB)) KB, $($beforeCounts.Count) tables)"
    }

    # The app key encrypts saved secrets; the backup is useless for those without it.
    $appC = Find-AppContainer
    $copied = $false
    if ($appC) { docker cp "${appC}:/app/storage/.env" (Join-Path $BackupDir 'app.env') *> $null; $copied = ($LASTEXITCODE -eq 0) }
    if ($copied) { Ok "App settings (APP_KEY): $BackupDir\app.env" }
    else { Warn "Could not copy the app's .env (APP_KEY). Back it up by hand: docker cp ce-atglance-app:/app/storage/.env ." }
    Copy-Item -Force .env $BackupDir
    Get-ChildItem -File -Filter 'docker-compose*.yml' | ForEach-Object { Copy-Item -Force $_.FullName $BackupDir }
    Get-RollbackText | Set-Content (Join-Path $BackupDir 'ROLLBACK.txt')
    # Backups contain secrets: Administrators and SYSTEM only (when run elevated, as for a ProgramData install).
    if ($IsAdmin) { icacls $BackupDir /inheritance:r /grant:r "*S-1-5-32-544:(OI)(CI)F" "*S-1-5-18:(OI)(CI)F" | Out-Null }

    # Older update backups beyond -Keep are removed, with their rollback image tags.
    $old = @(Get-ChildItem -Directory (Join-Path $AtglanceDir 'backups') -Filter 'update-*' | Sort-Object Name)
    if ($old.Count -gt $Keep) {
        foreach ($o in $old[0..($old.Count - $Keep - 1)]) {
            $tag = ":rollback-$($o.Name -replace '^update-', '')"
            docker images --format '{{.Repository}}:{{.Tag}}' 2>$null | Where-Object { "$_".EndsWith($tag) } | ForEach-Object { docker rmi $_ *> $null }
            Remove-Item -Recurse -Force $o.FullName
        }
    }

    # -------------------------------------------------------------------------
    Step "2. Checksums of the running application"

    # Rollback needs every image the current compose files tag with the version variable.
    $rbArgs = $NewArgs
    if (Test-Path docker-compose.yml) {
        $rbArgs = @('--project-directory', $AtglanceDir)
        foreach ($f in $Files) { if (Test-Path $f) { $rbArgs += @('-f', (Join-Path $AtglanceDir $f)) } }
    }
    $rbModel = Get-ComposeModel $rbArgs $probeEnv
    $suffix = ":$Probe${VersionVar}__"
    if ($rbModel) {
        foreach ($s in $rbModel.Services.Values) {
            if (-not $s.Image.EndsWith($suffix)) { continue }
            $repo = $s.Image.Substring(0, $s.Image.Length - $suffix.Length)
            $RollbackRepos += $repo
            $c = Get-SvcContainer $s.Name
            # Kept under :rollback-<time> so the previous version can be started again.
            if ($c) { $id = Get-ContainerImageId $c; if ($id) { docker tag $id "${repo}:$RollbackTag" *> $null } }
        }
    }

    foreach ($svc in $Model.Services.Keys) {
        $c = Get-SvcContainer $svc
        $OldId[$svc] = ''
        if ($c) { $OldId[$svc] = Get-ContainerImageId $c }
        if ($OldId[$svc]) {
            $digest = Get-ImageDigest $OldId[$svc]; if (-not $digest) { $digest = 'unknown' }
            Ok "$c  image $(Short $OldId[$svc])  digest $digest"
        } else {
            Warn "Service $svc has no container (it will be created)"
        }
    }
}

# ---------------------------------------------------------------------------
Step "3. Checksums of the newest release"

$updated = $Fresh
foreach ($ref in @($Model.Services.Values | ForEach-Object { $_.Image } | Sort-Object -Unique)) {
    $out = (docker pull -q $ref 2>&1 | Out-String)
    if ($LASTEXITCODE -ne 0) {
        Write-Host $out
        if ($out -match 'no matching manifest|does not match the specified platform') { Die "$ref has no build for this CPU. Nothing was changed." }
        if ($out -match 'not found|manifest unknown') { Die "$ref does not exist. Check the registry setting (ATGLANCE_REGISTRY). Nothing was changed." }
        Die "Could not pull $ref. Nothing was changed. Check the internet connection and the registry."
    }
}
foreach ($s in $Model.Services.Values) {
    $NewId[$s.Name] = "$(docker image inspect -f '{{.Id}}' $s.Image)".Trim()
    $digest = Get-ImageDigest $s.Image
    if ($NewId[$s.Name] -eq $OldId[$s.Name]) { Ok "$($s.Image)  digest $digest  (unchanged)" }
    else { Ok "$($s.Image)  digest $digest  (new)"; $updated = $true }
    # Missing or stopped containers (for example after "docker compose down") are deployed too.
    $c = Get-SvcContainer $s.Name
    if (-not $s.Optional -and -not ($c -and (Test-Running $c))) { $updated = $true }
}
# Same images but changed settings (compose file or .env): the containers are recreated too.
foreach ($line in @(docker compose @NewArgs config --hash=* 2>$null)) {
    $parts = "$line".Trim() -split '\s+'
    if ($parts.Count -lt 2) { continue }
    $c = Get-SvcContainer $parts[0]
    if (-not $c) { continue }
    if ("$((Get-ContainerLabels $c).'com.docker.compose.config-hash')" -ne $parts[1]) { Ok "$($parts[0]): settings changed"; $updated = $true }
}
if ($RotateUses.Count -gt 0) { $updated = $true }
if (-not $Fresh) {
    # A service without an older image rolls back to the new one.
    foreach ($repo in $RollbackRepos) {
        docker image inspect "${repo}:$RollbackTag" *> $null
        if ($LASTEXITCODE -ne 0) { docker tag "${repo}:latest" "${repo}:$RollbackTag" *> $null }
    }
}

if (-not $updated -and -not $Force) {
    Write-Host ""
    Write-Host "AtGlance is already up to date. Nothing changed (backup kept in $BackupDir). Use -Force to redeploy anyway." -ForegroundColor White
    Remove-Item -Recurse -Force -ErrorAction SilentlyContinue $NewDir
    Remove-Clone
    exit 0
}

if (-not $Fresh -and -not $AssumeYes -and [Environment]::UserInteractive -and -not [Console]::IsInputRedirected) {
    $answer = Read-Host "`n  Update now? Users may see errors for a minute while containers restart. [y/N]"
    if ($answer -notmatch '^(y|yes)$') { Write-Host "  Cancelled. Nothing was changed."; exit 1 }
}

# ---------------------------------------------------------------------------
if ($Fresh) { Step "4. Deploying all containers" } else { Step "4. Updating all containers" }

$startTs = (Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ')
foreach ($f in @($ComposeNames + $Files | Sort-Object -Unique)) {
    $src = Join-Path $NewDir $f
    if (Test-Path $src) { Copy-Item -Force $src (Join-Path $AtglanceDir $f) }
}
Remove-Item -Recurse -Force -ErrorAction SilentlyContinue $NewDir
Ok "Compose files: $($Files -join ' ')"
Set-EnvValue $VersionVar latest
docker compose @script:DirArgs up -d --remove-orphans
if ($LASTEXITCODE -ne 0) { Fail "docker compose up failed" }
Ok "Containers recreated (database migrations run when the app starts)"

# ---------------------------------------------------------------------------
Step "5. Health checks"

$restarts = @{}
foreach ($s in $Model.Services.Values) {
    $c = Get-SvcContainer $s.Name
    if (-not $c) { if ($s.Optional) { Ok "$($s.Name): no container (optional)" } else { Fail "$($s.Name): no container" }; continue }
    if ($s.Optional -and -not (Test-Running $c)) { Ok "$c stopped (optional; the console starts it)"; continue }
    if ((Get-Health $c) -eq 'none') {
        if (Test-Running $c) { Ok "$c running" } else { Fail "$c is not running" }
    } elseif (Wait-Healthy $c) {
        Ok "$c healthy"
    } else {
        docker logs --tail 30 $c 2>&1 | ForEach-Object { Write-Host "    $_" }
        if ($s.Optional) { Warn "$c did not become healthy (optional)" }
        else { Fail "$c did not become healthy within $($HealthTimeoutS / 60) minutes (last lines above)" }
    }
    $running = Get-ContainerImageId $c
    if ($running -eq $NewId[$s.Name]) { Ok "$c runs the new image $(Short $running)" }
    else { Fail "$c runs $(Short $running), expected $(Short $NewId[$s.Name])" }
    $restarts[$c] = [int]"$(docker inspect -f '{{.RestartCount}}' $c 2>$null)".Trim()
}

foreach ($s in $Model.Services.Values) {
    foreach ($p in $s.Ports) {
        $code = Get-HttpCode "http://127.0.0.1:$p/"
        if ($code -ne '000') { Ok "$($s.Name) answers on port $p (HTTP $code)" } else { Fail "$($s.Name) does not answer on port $p" }
    }
}

$appC = Find-AppContainer
if ($appC) {
    $status = docker exec $appC php artisan migrate:status 2>$null
    if ($LASTEXITCODE -eq 0) {
        $pending = @($status | Where-Object { $_ -match 'Pending' }).Count
        if ($pending -eq 0) { Ok "Database migrations: all applied" } else { Fail "Database migrations: $pending pending" }
    } else { Fail "php artisan migrate:status failed in $appC" }
}

Start-Sleep -Seconds 20
foreach ($c in $restarts.Keys) {
    $now = [int]"$(docker inspect -f '{{.RestartCount}}' $c 2>$null)".Trim()
    if ($now -gt $restarts[$c]) { Fail "$c restarted $($now - $restarts[$c]) times in the last 20 seconds (crash loop?)" }
}
Ok "No container restarted during the checks"

if ($appC) {
    $errors = @(docker logs --since $startTs $appC 2>&1 | Where-Object { "$_" -match 'production\.ERROR' }).Count
    if ($errors -eq 0) { Ok "No errors in the app log since the update" }
    else { Warn "$errors error lines in the app log since the update: docker logs --since $startTs $appC" }
}

$freeMb = [int][math]::Floor((Get-PSDrive -Name (Resolve-Path $AtglanceDir).Drive.Name).Free / 1MB)
if ($freeMb -ge 1024) { Ok "Disk: $freeMb MB free" } else { Warn "Disk: only $freeMb MB free" }

# ---------------------------------------------------------------------------
# A secret on the old compose default is known to everyone: replace it. Database accounts
# get the new password with ALTER USER first; then .env and the containers.
if ($RotateUses.Count -gt 0) {
    Step "Replacing default passwords"
    if ($script:Failures -gt 0) {
        Warn "Skipped because a check failed. Re-run the updater once AtGlance is healthy."
    } else {
        $script:DbContainer = Get-SvcContainer $DbSvc
        $sql = ''; $newSecrets = @{}
        foreach ($n in $RotateUses.Keys) {
            $nv = New-RandomSecret; $newSecrets[$n] = $nv
            foreach ($u in $RotateUses[$n]) {
                if ($u.Kind -ne 'E' -or -not $DbSvc -or $u.Svc -ne $DbSvc) { continue }
                if ($u.A -in @('MYSQL_ROOT_PASSWORD', 'MARIADB_ROOT_PASSWORD')) {
                    $sql += " ALTER USER IF EXISTS 'root'@'%' IDENTIFIED BY '$nv'; ALTER USER IF EXISTS 'root'@'localhost' IDENTIFIED BY '$nv';"
                } elseif ($u.A -in @('MYSQL_PASSWORD', 'MARIADB_PASSWORD')) {
                    $user = Get-ContainerEnv $script:DbContainer ($u.A -replace '_PASSWORD$', '_USER')
                    if ($user) { $sql += " ALTER USER IF EXISTS '$user'@'%' IDENTIFIED BY '$nv';" }
                }
            }
        }
        $altered = $true
        if ($sql) { Invoke-DbSql $sql | Out-Null; $altered = ($LASTEXITCODE -eq 0) }
        if (-not $altered) {
            Warn "Could not change the database passwords: they stay on the default."
        } else {
            foreach ($n in $newSecrets.Keys) {
                [Environment]::SetEnvironmentVariable($n, $newSecrets[$n], 'Process'); Set-EnvValue $n $newSecrets[$n]
                Ok "$n replaced (new value in $AtglanceDir\.env)"
            }
            docker compose @script:DirArgs up -d --remove-orphans *> $null
            if ($LASTEXITCODE -ne 0) { Fail "docker compose up failed after replacing the passwords" }
            foreach ($s in $Model.Services.Values) {
                $c = Get-SvcContainer $s.Name
                if (-not $c -or -not (Test-Running $c) -or (Get-Health $c) -eq 'none') { continue }
                if (Wait-Healthy $c) { Ok "$c healthy" } else { Fail "$c did not become healthy after replacing the passwords" }
            }
        }
    }
}

# ---------------------------------------------------------------------------
function Compare-Counts($Reference, $Current, $Label) {
    $cur = @{}
    foreach ($line in $Current) { $p = "$line" -split ' '; if ($p.Count -ge 2) { $cur[$p[0]] = [long]$p[1] } }
    $before = $script:Failures
    foreach ($line in $Reference) {
        $p = "$line" -split ' '
        if ($p.Count -lt 2) { continue }
        $table = $p[0]; $nRef = [long]$p[1]
        if (-not $cur.ContainsKey($table)) { Warn "${Label}: table $table is gone (dropped by a migration?)" }
        elseif ($cur[$table] -lt $nRef) {
            if ($table -match $VolatileRe) { Ok "${Label}: $table $nRef -> $($cur[$table]) (changes on its own)" }
            else { Fail "${Label}: table $table has $($cur[$table]) rows, backup has $nRef" }
        }
    }
    return ($script:Failures -eq $before)
}

if (-not $Fresh -and $script:DbContainer) {
    Step "6. Database check against the backup"

    $script:DbContainer = Get-SvcContainer $DbSvc
    $afterCounts = @(Get-TableCounts $DbName)
    $afterCounts | Set-Content (Join-Path $BackupDir 'row-counts-after.txt')
    if (Compare-Counts $beforeCounts $afterCounts 'Live database') {
        Ok "Live database: no rows lost in $($beforeCounts.Count) tables ($($afterCounts.Count - $beforeCounts.Count) new tables)"
    }

    if (-not $SkipDbVerify) {
        # Restore the backup into a scratch database: proves it is complete and restorable.
        # The dump is copied back in: the database container may have been recreated by the update.
        docker cp (Join-Path $BackupDir "$DbName.sql.gz") "${script:DbContainer}:/tmp/atglance-update.sql.gz" *> $null
        Invoke-DbSql "DROP DATABASE IF EXISTS ``$ScratchDb``; CREATE DATABASE ``$ScratchDb``" | Out-Null
        docker exec $script:DbContainer sh -c "gunzip -c /tmp/atglance-update.sql.gz | MYSQL_PWD=`${MYSQL_ROOT_PASSWORD:-`$MARIADB_ROOT_PASSWORD} mysql -uroot $ScratchDb"
        if ($LASTEXITCODE -eq 0) {
            $restored = @(Get-TableCounts $ScratchDb)
            $restored | Set-Content (Join-Path $BackupDir 'row-counts-restored.txt')
            if (($restored -join "`n") -eq ($beforeCounts -join "`n")) {
                Ok "Backup restores completely: every table and row count matches the database before the update"
            } elseif (Compare-Counts $beforeCounts $restored 'Restored backup') {
                Ok "Backup restores: differences only in tables that change on their own"
            }
        } else {
            Fail "The backup could not be restored into a scratch database"
        }
        Invoke-DbSql "DROP DATABASE IF EXISTS ``$ScratchDb``" | Out-Null
    }
    docker exec $script:DbContainer rm -f /tmp/atglance-update.sql /tmp/atglance-update.sql.gz *> $null
}

# ---------------------------------------------------------------------------
Write-Host ""
if ($script:Failures -gt 0) {
    if ($Fresh) {
        Write-Host "Install finished with $($script:Failures) failed check(s) and $($script:Warnings) warning(s)." -ForegroundColor Red
        Write-Host "  Logs: cd `"$AtglanceDir`"; docker compose logs"
    } else {
        Write-Host "Update finished with $($script:Failures) failed check(s) and $($script:Warnings) warning(s)." -ForegroundColor Red
        Write-Host ""
        Get-RollbackText | Write-Host
    }
    exit 2
}

if ($Fresh) {
    $hostIp = Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue |
        Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' -and $_.InterfaceAlias -notlike 'vEthernet*' } |
        Select-Object -First 1 -ExpandProperty IPAddress
    if (-not $hostIp) { $hostIp = 'localhost' }
    $summary = "AtGlance CE is installed and healthy."
    if ($script:Warnings -gt 0) { $summary += " ($($script:Warnings) warning(s) above)" }
    Write-Host $summary -ForegroundColor White
    Write-Host ""
    $appSvc = ''
    if ($appC) { $appSvc = "$((Get-ContainerLabels $appC).'com.docker.compose.service')" }
    foreach ($s in $Model.Services.Values) {
        foreach ($p in $s.Ports) {
            $note = ''; if ($s.Name -eq $appSvc) { $note = '  <- open this to run the setup wizard' }
            Write-Host ("  {0,-12} http://{1}:{2}{3}" -f $s.Name, $hostIp, $p, $note)
        }
    }
    Write-Host "  Install dir:   $AtglanceDir (settings and database passwords in .env)"
    Write-Host ""
    Write-Host "  Open these ports in Windows Firewall for users and servers."
    Write-Host "  Back up the app key (it encrypts stored secrets):"
    $appName = $appC; if (-not $appName) { $appName = 'ce-atglance-app' }
    Write-Host "    docker exec $appName grep APP_KEY /app/storage/.env"
    Write-Host "  Update later: re-run this script."
    Remove-Clone
    exit 0
}

$summary = "AtGlance is updated and healthy."
if ($script:Warnings -gt 0) { $summary += " ($($script:Warnings) warning(s) above)" }
Write-Host $summary -ForegroundColor White
foreach ($svc in $Model.Services.Keys) {
    if ($OldId[$svc] -ne $NewId[$svc]) {
        $from = 'none'; if ($OldId[$svc]) { $from = Short $OldId[$svc] }
        Write-Host "  $svc  $from -> $(Short $NewId[$svc])"
    }
}
Write-Host "  Backup:   $BackupDir"
Write-Host "  Rollback: $BackupDir\ROLLBACK.txt (previous images are tagged :$RollbackTag)"
Remove-Clone
exit 0
