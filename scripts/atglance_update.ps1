<#
.SYNOPSIS
    AtGlance Community Edition updater for Windows (Docker Desktop): deploys the newest
    release ("latest") over an existing install.

.DESCRIPTION
    Run from an elevated PowerShell (Run as administrator):

      powershell -ExecutionPolicy Bypass -File .\atglance_update.ps1

    or

      irm https://raw.githubusercontent.com/niketchandra/atGlance-managementGUI/main/scripts/atglance_update.ps1 | iex

    Steps: pre-checks -> database backup -> current image checksums -> new image checksums ->
           update all containers -> health checks -> database check against the backup.

    Options (or environment variables when piped to iex):
      -Dir DIR         install directory (ATGLANCE_DIR, default %ProgramData%\AtGlance)
      -Force           redeploy even when the images are already the newest
      -Yes             do not ask for confirmation (ATGLANCE_YES=1)
      -SkipDbVerify    skip restoring the backup into a scratch database for the comparison
      -Keep N          backups to keep (default 5)
      -KeepClone       keep the git clone this script runs from (ATGLANCE_KEEP_CLONE=1);
                       by default a clean clone is removed after a successful run

    Exit codes: 0 updated or already up to date, 1 stopped before changing anything,
                2 updated but a check failed (rollback steps are printed).
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

$AtglanceDir = Get-Setting $Dir 'ATGLANCE_DIR' (Join-Path $env:ProgramData 'AtGlance')
$AtglanceRef = Get-Setting $null 'ATGLANCE_REF' 'main'
$RepoRaw     = "https://raw.githubusercontent.com/niketchandra/atGlance-managementGUI/$AtglanceRef"
# Local compose file instead of downloading one (testing a branch).
$ComposeFile = Get-Setting $null 'ATGLANCE_COMPOSE_FILE' ''
$AssumeYes   = $Yes -or ([Environment]::GetEnvironmentVariable('ATGLANCE_YES') -eq '1')
$MinCompose  = [version]'2.23.0'
$HealthTimeoutS = 300
$DbName      = 'atglance'
$ScratchDb   = 'atglance_update_verify'
# Tables that change on their own (cache, queues, sessions, log pruning): differences are warnings, not failures.
$VolatileTables = @('cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'sessions', 'web_sessions', 'session_tokens', 'activity_logs')
$Services = @('app', 'gateway', 'mcp')

$script:Warnings = 0
$script:Failures = 0
function Step($m) { Write-Host ""; Write-Host "==> $m" -ForegroundColor White }
function Ok($m)   { Write-Host "  " -NoNewline; Write-Host ([char]0x2713) -ForegroundColor Green -NoNewline; Write-Host " $m" }
function Warn($m) { Write-Host "  " -NoNewline; Write-Host "!" -ForegroundColor Yellow -NoNewline; Write-Host " $m"; $script:Warnings++ }
function Fail($m) { Write-Host "  $([char]0x2717) $m" -ForegroundColor Red; $script:Failures++ }
function Die($m)  { Write-Host "  $([char]0x2717) $m" -ForegroundColor Red; exit 1 }
function Short($v) { $v = "$v" -replace '^sha256:', ''; if ($v.Length -gt 12) { $v.Substring(0, 12) } else { $v } }

# SQL as root inside the database container; the password stays in the container (MYSQL_PWD).
# Windows PowerShell 5.1 drops inner double quotes when passing arguments to programs, so the SQL is
# sent base64-encoded (one argument, no quotes or spaces) and no command below uses inner double quotes.
function Invoke-DbSql([string[]]$MysqlArgs) {
    $sql = $MysqlArgs[-1]
    $b64 = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($sql))
    $out = docker exec ce-atglance-db sh -c 'echo $0 | base64 -d | MYSQL_PWD=$MYSQL_ROOT_PASSWORD mysql -uroot -N -B' $b64 2>$null
    return @($out | Where-Object { $_ -ne $null -and "$_".Trim() -ne '' })
}

# "table count" lines for every base table of a database, sorted by table name.
function Get-TableCounts([string]$Db) {
    $tables = Invoke-DbSql @('-e', "SELECT table_name FROM information_schema.tables WHERE table_schema = '$Db' AND table_type = 'BASE TABLE' ORDER BY table_name")
    foreach ($t in $tables) {
        $n = (Invoke-DbSql @('-e', "SELECT COUNT(*) FROM ``$Db``.``$t``")) | Select-Object -First 1
        "$t $n"
    }
}

function Get-ContainerImageId($Name) { $id = docker inspect -f '{{.Image}}' $Name 2>$null; if ($LASTEXITCODE -eq 0) { "$id".Trim() } else { '' } }
function Get-ImageDigest($Ref) {
    $d = docker image inspect -f '{{range .RepoDigests}}{{println .}}{{end}}' $Ref 2>$null | Select-Object -First 1
    if ($d) { ("$d".Trim() -split '@')[-1] } else { '' }
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
function Get-HttpCode([string[]]$CurlArgs) {
    $code = curl.exe -s -o NUL -w '%{http_code}' --max-time 15 @CurlArgs 2>$null
    if (-not $code) { '000' } else { "$code".Trim() }
}
function Read-EnvValue($Name, $Default) {
    $line = Get-Content .env | Where-Object { $_ -match "^$([regex]::Escape($Name))=" } | Select-Object -Last 1
    if ($line) { $line.Substring($Name.Length + 1) } else { $Default }
}
function Set-EnvValue($Name, $Value) {
    $lines = @(Get-Content .env)
    $found = $false
    $lines = $lines | ForEach-Object { if ($_ -match "^$([regex]::Escape($Name))=") { $found = $true; "$Name=$Value" } else { $_ } }
    if (-not $found) { $lines += "$Name=$Value" }
    [IO.File]::WriteAllText((Join-Path (Get-Location) '.env'), (($lines -join "`n") + "`n"), (New-Object Text.UTF8Encoding($false)))
}

function Get-RollbackText {
    $compose = ($script:ComposeArgs -join ' ')
@"
Rollback (the previous images are tagged :$RollbackTag, the database backup is in $BackupDir):
  1. Previous containers (elevated PowerShell):
       cd "$AtglanceDir"
       (Get-Content .env) -replace '^ATGLANCE_VERSION=.*', 'ATGLANCE_VERSION=$RollbackTag' | Set-Content .env
       Copy-Item -Force "$BackupDir\docker-compose.yml" .
       docker compose $compose up -d --remove-orphans
  2. Only if data is wrong, restore the database (replaces ALL current data):
       docker cp "$BackupDir\$DbName.sql" ce-atglance-db:/tmp/restore.sql
       docker exec ce-atglance-db sh -c 'MYSQL_PWD=`$MYSQL_ROOT_PASSWORD mysql -uroot $DbName < /tmp/restore.sql && rm /tmp/restore.sql'
     ($DbName.sql is inside $DbName.sql.gz: Expand it with 7-Zip, or run
       docker run --rm -v "${BackupDir}:/b" alpine gunzip -k /b/$DbName.sql.gz )
  3. Later, return to normal updates: set ATGLANCE_VERSION=latest in $AtglanceDir\.env
"@
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
Step "Checking the existing install"

$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$IsAdmin = (New-Object Security.Principal.WindowsPrincipal($identity)).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not (Get-Command docker -ErrorAction SilentlyContinue)) { Die "Docker is not installed. Use atglance-installer.ps1 for a new install." }
if (-not (Get-Command curl.exe -ErrorAction SilentlyContinue)) { Die "curl.exe is required (included in Windows 10 1803 and later)." }
docker info *> $null
if ($LASTEXITCODE -ne 0) { Die "Docker Desktop is not running. Start it and re-run." }
if ((docker version --format '{{.Server.Os}}' 2>$null) -ne 'linux') { Die "Docker is in Windows-containers mode. Switch Docker Desktop to Linux containers." }

if (-not ((Test-Path (Join-Path $AtglanceDir 'docker-compose.yml')) -and (Test-Path (Join-Path $AtglanceDir '.env')))) {
    Die "No AtGlance install in $AtglanceDir (docker-compose.yml and .env). Use -Dir, or atglance-installer.ps1 for a new install."
}
Set-Location $AtglanceDir
# The default install (ProgramData) is writable by administrators only.
try { [IO.File]::WriteAllText((Join-Path $AtglanceDir '.write-test'), 'x'); Remove-Item -Force (Join-Path $AtglanceDir '.write-test') }
catch { Die "Cannot write to $AtglanceDir. Run from an elevated PowerShell (Run as administrator)." }
Ok "Install directory $AtglanceDir"

$composeText = "$(docker compose version --short 2>$null)".Trim() -replace '^v', ''
if (-not $composeText) { Die "Docker Compose plugin not found. Update Docker Desktop." }
if ([version](($composeText -split '[-+]')[0]) -lt $MinCompose) { Die "Docker Compose $composeText is too old (need $MinCompose or newer). Update Docker Desktop." }
Ok "Docker Compose $composeText"

foreach ($c in 'ce-atglance-app', 'ce-atglance-db') {
    if (-not (Test-Running $c)) { Die "$c is not running. Start AtGlance first (cd $AtglanceDir; docker compose up -d), then update." }
}
Ok "AtGlance is running"

$Registry    = Read-EnvValue 'ATGLANCE_REGISTRY' 'atglance'
$AppPort     = Read-EnvValue 'APP_PORT' '8000'
$GatewayPort = Read-EnvValue 'GATEWAY_PORT' '8002'

$script:ComposeArgs = @('-f', 'docker-compose.yml')
if (Test-Path docker-compose.domain.yml) {
    $appEnv = docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' ce-atglance-app 2>$null
    if ($appEnv -contains 'ATGLANCE_PROXY=builtin') {
        $script:ComposeArgs += @('-f', 'docker-compose.domain.yml')
        Ok "Custom domain and HTTPS proxy in use: kept"
    }
}

$dbSizeMb = (Invoke-DbSql @('-e', "SELECT CEIL(SUM(data_length + index_length) / 1024 / 1024) FROM information_schema.tables WHERE table_schema = '$DbName'")) | Select-Object -First 1
if (-not $dbSizeMb -or $dbSizeMb -eq 'NULL') { $dbSizeMb = 0 }
$freeMb = [int][math]::Floor((Get-PSDrive -Name (Resolve-Path $AtglanceDir).Drive.Name).Free / 1MB)
$needMb = [int]$dbSizeMb * 3 + 2048
if ($freeMb -lt $needMb) { Die "Need about $needMb MB free in $AtglanceDir (database $dbSizeMb MB + new images), found $freeMb MB." }
Ok "Disk: $freeMb MB free, database $dbSizeMb MB"

# ---------------------------------------------------------------------------
Step "1. Backing up the database"

$Stamp = (Get-Date).ToUniversalTime().ToString('yyyyMMdd-HHmmss')
# Each backup has its own image tag, so an older backup still rolls back to its own version.
$RollbackTag = "rollback-$Stamp"
$BackupDir = Join-Path $AtglanceDir "backups\update-$Stamp"
New-Item -ItemType Directory -Force -Path $BackupDir | Out-Null

# Dumped inside the container and copied out with docker cp: byte-exact (no PowerShell text pipeline).
docker exec ce-atglance-db sh -c "MYSQL_PWD=`$MYSQL_ROOT_PASSWORD mysqldump -uroot --single-transaction --quick --routines --triggers --no-tablespaces --set-gtid-purged=OFF $DbName > /tmp/atglance-update.sql"
if ($LASTEXITCODE -ne 0) { Die "mysqldump failed. Nothing was changed." }
docker cp "ce-atglance-db:/tmp/atglance-update.sql" (Join-Path $BackupDir "$DbName.sql") *> $null
if ($LASTEXITCODE -ne 0) { Die "Could not copy the backup out of the database container. Nothing was changed." }
$sqlPath = Join-Path $BackupDir "$DbName.sql"
$tail = Get-Content $sqlPath -Tail 1
if ($tail -notmatch 'Dump completed') { Die "The backup did not finish (no 'Dump completed' marker). Nothing was changed." }

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

# The app key encrypts saved secrets; the backup is useless for those without it.
docker cp "ce-atglance-app:/app/storage/.env" (Join-Path $BackupDir 'app.env') *> $null
if ($LASTEXITCODE -eq 0) { Ok "App settings (APP_KEY): $BackupDir\app.env" }
else { Warn "Could not copy the app's .env (APP_KEY). Back it up by hand: docker cp ce-atglance-app:/app/storage/.env ." }
Copy-Item -Force docker-compose.yml, .env $BackupDir
if (Test-Path docker-compose.domain.yml) { Copy-Item -Force docker-compose.domain.yml $BackupDir }
Get-RollbackText | Set-Content (Join-Path $BackupDir 'ROLLBACK.txt')
# Backups contain secrets: Administrators and SYSTEM only (when run elevated, as for a ProgramData install).
if ($IsAdmin) { icacls $BackupDir /inheritance:r /grant:r "*S-1-5-32-544:(OI)(CI)F" "*S-1-5-18:(OI)(CI)F" | Out-Null }

# Older update backups beyond -Keep are removed, with their rollback image tags.
$old = @(Get-ChildItem -Directory (Join-Path $AtglanceDir 'backups') -Filter 'update-*' | Sort-Object Name)
if ($old.Count -gt $Keep) {
    foreach ($o in $old[0..($old.Count - $Keep - 1)]) {
        foreach ($s in $Services) { docker rmi "$Registry/ce-atglance-${s}:rollback-$($o.Name -replace '^update-', '')" *> $null }
        Remove-Item -Recurse -Force $o.FullName
    }
}

# ---------------------------------------------------------------------------
Step "2. Checksums of the running application"

$OldId = @{}; $NewId = @{}; $NewDigest = @{}
foreach ($s in $Services) {
    $OldId[$s] = Get-ContainerImageId "ce-atglance-$s"
    if ($OldId[$s]) {
        $digest = Get-ImageDigest $OldId[$s]
        if (-not $digest) { $digest = 'unknown' }
        Ok "ce-atglance-$s  image $(Short $OldId[$s])  digest $digest"
        # Kept under :rollback-<time> so the previous version can be started again.
        docker tag $OldId[$s] "$Registry/ce-atglance-${s}:$RollbackTag" *> $null
    } else {
        Warn "ce-atglance-$s is not present (it will be created)"
    }
}

# ---------------------------------------------------------------------------
Step "3. Checksums of the newest release"

$updated = $false
foreach ($s in $Services) {
    $ref = "$Registry/ce-atglance-${s}:latest"
    docker pull -q $ref *> $null
    if ($LASTEXITCODE -ne 0) { Die "Could not pull $ref. Nothing was changed. Check the internet connection and Docker Hub." }
    $NewId[$s] = "$(docker image inspect -f '{{.Id}}' $ref)".Trim()
    $NewDigest[$s] = Get-ImageDigest $ref
    # A service that did not exist before rolls back to the new image (there is no older one).
    if (-not $OldId[$s]) { docker tag $NewId[$s] "$Registry/ce-atglance-${s}:$RollbackTag" *> $null }
    if ($NewId[$s] -eq $OldId[$s]) { Ok "$ref  digest $($NewDigest[$s])  (unchanged)" }
    else { Ok "$ref  digest $($NewDigest[$s])  (new)"; $updated = $true }
}

if (-not $updated -and -not $Force) {
    Write-Host ""
    Write-Host "AtGlance is already up to date. Nothing changed (backup kept in $BackupDir). Use -Force to redeploy anyway." -ForegroundColor White
    Remove-Clone
    exit 0
}

if (-not $AssumeYes -and [Environment]::UserInteractive -and -not [Console]::IsInputRedirected) {
    $answer = Read-Host "`n  Update now? Users may see errors for a minute while containers restart. [y/N]"
    if ($answer -notmatch '^(y|yes)$') { Write-Host "  Cancelled. Nothing was changed."; exit 1 }
}

# ---------------------------------------------------------------------------
Step "4. Updating all containers"

$startTs = (Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ')
$newCompose = 'docker-compose.yml.new'
try {
    if ($ComposeFile) { Copy-Item -Force $ComposeFile $newCompose }
    else { Invoke-WebRequest -UseBasicParsing "$RepoRaw/docker-compose.yml" -OutFile $newCompose -ErrorAction Stop }
} catch {
    Remove-Item -Force -ErrorAction SilentlyContinue $newCompose
    Warn "Could not download the newest docker-compose.yml; keeping the current one."
}
if (Test-Path $newCompose) {
    docker compose -f $newCompose config --quiet *> $null
    if ($LASTEXITCODE -eq 0) { Move-Item -Force $newCompose docker-compose.yml; Ok "docker-compose.yml updated" }
    else { Remove-Item -Force $newCompose; Warn "The downloaded docker-compose.yml is not valid; keeping the current one." }
}
try {
    Invoke-WebRequest -UseBasicParsing "$RepoRaw/docker-compose.domain.yml" -OutFile docker-compose.domain.yml.new -ErrorAction Stop
    Move-Item -Force docker-compose.domain.yml.new docker-compose.domain.yml
} catch { Remove-Item -Force -ErrorAction SilentlyContinue docker-compose.domain.yml.new }

Set-EnvValue ATGLANCE_VERSION latest
docker compose @script:ComposeArgs pull --quiet
if ($LASTEXITCODE -ne 0) { Fail "docker compose pull failed" }
docker compose @script:ComposeArgs up -d --remove-orphans
if ($LASTEXITCODE -ne 0) { Fail "docker compose up failed" }
Ok "Containers recreated (database migrations run when the app starts)"

# ---------------------------------------------------------------------------
Step "5. Health checks"

if (Wait-Healthy ce-atglance-app) { Ok "ce-atglance-app healthy" } else {
    docker logs --tail 30 ce-atglance-app 2>&1 | ForEach-Object { Write-Host "    $_" }
    Fail "ce-atglance-app did not become healthy within $($HealthTimeoutS / 60) minutes (last lines above)"
}
if (Wait-Healthy ce-atglance-gateway) { Ok "ce-atglance-gateway healthy" } else { Fail "ce-atglance-gateway did not become healthy" }
foreach ($c in 'ce-atglance-db', 'ce-atglance-redis') {
    $h = Get-Health $c
    if ($h -eq 'healthy') { Ok "$c healthy" } else { Fail "$c is $h" }
}
foreach ($c in 'ce-atglance-worker', 'ce-atglance-scheduler', 'ce-atglance-controller') {
    if (Test-Running $c) { Ok "$c running" } else { Fail "$c is not running" }
}

$restarts = @{}
foreach ($c in 'ce-atglance-app', 'ce-atglance-worker', 'ce-atglance-scheduler', 'ce-atglance-gateway') {
    $restarts[$c] = [int]"$(docker inspect -f '{{.RestartCount}}' $c 2>$null)".Trim()
}

foreach ($s in $Services) {
    $running = Get-ContainerImageId "ce-atglance-$s"
    if ($s -eq 'mcp' -and -not $running) { continue }
    if ($running -eq $NewId[$s]) { Ok "ce-atglance-$s runs the new image $(Short $running)" }
    else { Fail "ce-atglance-$s runs $(Short $running), expected $(Short $NewId[$s])" }
}

$code = Get-HttpCode @("http://127.0.0.1:$AppPort/up")
if ($code -eq '200') { Ok "Web console answers (GET /up -> 200)" } else { Fail "Web console GET http://127.0.0.1:$AppPort/up returned $code" }
$code = Get-HttpCode @("http://127.0.0.1:$GatewayPort/")
if ($code -ne '000') { Ok "API gateway answers on port $GatewayPort (HTTP $code)" } else { Fail "API gateway on port $GatewayPort does not answer" }

$pending = @(docker exec ce-atglance-app php artisan migrate:status 2>$null | Where-Object { $_ -match 'Pending' }).Count
if ($pending -eq 0) { Ok "Database migrations: all applied" } else { Fail "Database migrations: $pending pending" }

# Read the worker's own process list (no dependency on the host's tools).
$procs = docker exec ce-atglance-worker sh -c 'for f in /proc/[0-9]*/cmdline; do tr ''\000'' '' '' < $f; echo; done' 2>$null
if ($procs -match 'queue:work') { Ok "Queue worker is processing (queue:work)" } else { Fail "Queue worker process not found" }
docker exec ce-atglance-app php artisan schedule:list *> $null
if ($LASTEXITCODE -eq 0) { Ok "Scheduler configuration loads (schedule:list)" } else { Fail "php artisan schedule:list failed" }

$mcpEnabled = (Invoke-DbSql @('-e', "SELECT setting_value FROM $DbName.admin_settings WHERE setting_key = 'mcp_enabled'")) | Select-Object -First 1
if ($mcpEnabled -eq 'true') {
    if (Wait-Healthy ce-atglance-mcp) {
        $body = '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26","capabilities":{},"clientInfo":{"name":"atglance-update","version":"1"}}}'
        $bodyFile = Join-Path $env:TEMP 'atglance-mcp-init.json'
        [IO.File]::WriteAllText($bodyFile, $body)
        $code = Get-HttpCode @('-X', 'POST', "http://127.0.0.1:$GatewayPort/mcp", '-H', 'Content-Type: application/json', '-H', 'Accept: application/json, text/event-stream', '--data-binary', "@$bodyFile")
        Remove-Item -Force -ErrorAction SilentlyContinue $bodyFile
        if ($code -eq '200') { Ok "MCP server answers through the gateway" }
        else { Warn "MCP server returned HTTP $code (Kong re-resolves the container within 5 seconds; check again shortly)" }
    } else { Fail "MCP is turned on but ce-atglance-mcp is not healthy" }
} else {
    Ok "MCP is off (the console stops ce-atglance-mcp within a minute)"
}

Start-Sleep -Seconds 20
foreach ($c in $restarts.Keys) {
    $now = [int]"$(docker inspect -f '{{.RestartCount}}' $c 2>$null)".Trim()
    if ($now -gt $restarts[$c]) { Fail "$c restarted $($now - $restarts[$c]) times in the last 20 seconds (crash loop?)" }
}
Ok "No container restarted during the checks"

$errors = @(docker logs --since $startTs ce-atglance-app 2>&1 | Where-Object { "$_" -match 'production\.ERROR' }).Count
if ($errors -eq 0) { Ok "No errors in the app log since the update" }
else { Warn "$errors error lines in the app log since the update: docker logs --since $startTs ce-atglance-app" }

$freeMb = [int][math]::Floor((Get-PSDrive -Name (Resolve-Path $AtglanceDir).Drive.Name).Free / 1MB)
if ($freeMb -ge 1024) { Ok "Disk: $freeMb MB free" } else { Warn "Disk: only $freeMb MB free" }

# ---------------------------------------------------------------------------
Step "6. Database check against the backup"

function Compare-Counts($Reference, $Current, $Label) {
    $cur = @{}
    foreach ($line in $Current) { $p = "$line" -split ' '; if ($p.Count -ge 2) { $cur[$p[0]] = [long]$p[1] } }
    $before = $script:Failures
    foreach ($line in $Reference) {
        $p = "$line" -split ' '
        if ($p.Count -lt 2) { continue }
        $table = $p[0]; $nRef = [long]$p[1]
        if (-not $cur.ContainsKey($table)) { Fail "${Label}: table $table is missing" }
        elseif ($cur[$table] -lt $nRef) {
            if ($VolatileTables -contains $table) { Ok "${Label}: $table $nRef -> $($cur[$table]) (changes on its own)" }
            else { Fail "${Label}: table $table has $($cur[$table]) rows, backup has $nRef" }
        }
    }
    return ($script:Failures -eq $before)
}

$afterCounts = @(Get-TableCounts $DbName)
$afterCounts | Set-Content (Join-Path $BackupDir 'row-counts-after.txt')
if (Compare-Counts $beforeCounts $afterCounts 'Live database') {
    Ok "Live database: all $($beforeCounts.Count) tables present, no rows lost ($($afterCounts.Count - $beforeCounts.Count) new tables)"
}

if (-not $SkipDbVerify) {
    # Restore the backup into a scratch database: proves it is complete and restorable.
    Invoke-DbSql @('-e', "DROP DATABASE IF EXISTS ``$ScratchDb``; CREATE DATABASE ``$ScratchDb``") | Out-Null
    docker exec ce-atglance-db sh -c "MYSQL_PWD=`$MYSQL_ROOT_PASSWORD mysql -uroot $ScratchDb < /tmp/atglance-update.sql"
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
    Invoke-DbSql @('-e', "DROP DATABASE IF EXISTS ``$ScratchDb``") | Out-Null
}
docker exec ce-atglance-db rm -f /tmp/atglance-update.sql *> $null

# ---------------------------------------------------------------------------
Write-Host ""
if ($script:Failures -gt 0) {
    Write-Host "Update finished with $($script:Failures) failed check(s) and $($script:Warnings) warning(s)." -ForegroundColor Red
    Write-Host ""
    Get-RollbackText | Write-Host
    exit 2
}

$summary = "AtGlance is updated and healthy."
if ($script:Warnings -gt 0) { $summary += " ($($script:Warnings) warning(s) above)" }
Write-Host $summary -ForegroundColor White
foreach ($s in $Services) {
    if ($OldId[$s] -ne $NewId[$s]) {
        $from = 'none'; if ($OldId[$s]) { $from = Short $OldId[$s] }
        Write-Host "  ce-atglance-$s  $from -> $(Short $NewId[$s])"
    }
}
Write-Host "  Backup:   $BackupDir"
Write-Host "  Rollback: $BackupDir\ROLLBACK.txt (previous images are tagged :$RollbackTag)"
Remove-Clone
exit 0
