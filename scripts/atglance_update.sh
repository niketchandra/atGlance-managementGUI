#!/usr/bin/env bash
# AtGlance Community Edition updater: deploys the newest release ("latest") over an existing install.
#
#   curl -fsSL https://raw.githubusercontent.com/niketchandra/atGlance-managementGUI/main/scripts/atglance_update.sh | sudo bash
#   sudo bash atglance_update.sh [options]
#
# Steps: pre-checks -> database backup -> current image checksums -> new image checksums ->
#        update all containers -> health checks -> database check against the backup.
#
# Options (or environment variables):
#   --dir DIR          install directory (ATGLANCE_DIR, default /opt/atglance)
#   --force            redeploy even when the images are already the newest
#   --yes              do not ask for confirmation
#   --skip-db-verify   skip restoring the backup into a scratch database for the comparison
#   --keep N           backups to keep (default 5)
#   --keep-clone       keep the git clone this script runs from (ATGLANCE_KEEP_CLONE=1);
#                      by default a clean clone is removed after a successful run
#
# Exit codes: 0 updated or already up to date, 1 stopped before changing anything,
#             2 updated but a check failed (rollback steps are printed).
set -euo pipefail
# Note: no "grep -q" after a pipe: it exits early, the writer gets SIGPIPE and pipefail fails the check.

ATGLANCE_DIR="${ATGLANCE_DIR:-/opt/atglance}"
ATGLANCE_REF="${ATGLANCE_REF:-main}"
REPO_RAW="https://raw.githubusercontent.com/niketchandra/atGlance-managementGUI/${ATGLANCE_REF}"
# Local compose file instead of downloading one (testing a branch).
ATGLANCE_COMPOSE_FILE="${ATGLANCE_COMPOSE_FILE:-}"
FORCE=0
ASSUME_YES=0
VERIFY_DB=1
KEEP_BACKUPS=5
MIN_COMPOSE=2.23.0
HEALTH_TIMEOUT_S=300
DB_NAME=atglance
SCRATCH_DB=atglance_update_verify
# Tables that change on their own (cache, queues, sessions, log pruning): differences are warnings, not failures.
VOLATILE_TABLES=" cache cache_locks jobs job_batches failed_jobs sessions web_sessions session_tokens activity_logs "
SERVICES="app gateway mcp"

# Directory of this script when run from a file (empty when piped from curl); see remove_clone.
SCRIPT_DIR=""
if [ -n "${BASH_SOURCE[0]:-}" ] && [ -f "${BASH_SOURCE[0]}" ]; then
    SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)
fi
KEEP_CLONE="${ATGLANCE_KEEP_CLONE:-0}"

while [ $# -gt 0 ]; do
    case "$1" in
        --dir) ATGLANCE_DIR="$2"; shift 2 ;;
        --force) FORCE=1; shift ;;
        --yes|-y) ASSUME_YES=1; shift ;;
        --skip-db-verify) VERIFY_DB=0; shift ;;
        --keep) KEEP_BACKUPS="$2"; shift 2 ;;
        --keep-clone) KEEP_CLONE=1; shift ;;
        -h|--help) sed -n '2,20p' "$0" 2>/dev/null || true; exit 0 ;;
        *) echo "Unknown option: $1" >&2; exit 1 ;;
    esac
done

if [ -t 1 ]; then
    C_OK=$'\e[32m'; C_WARN=$'\e[33m'; C_ERR=$'\e[31m'; C_B=$'\e[1m'; C_0=$'\e[0m'
else
    C_OK=""; C_WARN=""; C_ERR=""; C_B=""; C_0=""
fi
step() { echo; echo "${C_B}==> $*${C_0}"; }
ok()   { echo "  ${C_OK}✓${C_0} $*"; }
warn() { echo "  ${C_WARN}!${C_0} $*"; WARNINGS=$((WARNINGS + 1)); }
die()  { echo "  ${C_ERR}✗ $*${C_0}" >&2; exit 1; }
fail() { echo "  ${C_ERR}✗ $*${C_0}"; FAILURES=$((FAILURES + 1)); }
WARNINGS=0
FAILURES=0
UPDATED=0

version_ge() { [ "$(printf '%s\n%s\n' "$2" "$1" | sort -V | head -n1)" = "$2" ]; }

# SQL as root inside the database container; the password stays in the container (MYSQL_PWD).
db_sql() {
    docker exec -i ce-atglance-db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot -N -B "$@"' sh "$@"
}

# "table count" lines for every base table of a database, sorted by table name.
table_counts() {
    local db="$1" table
    for table in $(db_sql -e "SELECT table_name FROM information_schema.tables WHERE table_schema = '$db' AND table_type = 'BASE TABLE' ORDER BY table_name"); do
        echo "$table $(db_sql -e "SELECT COUNT(*) FROM \`$db\`.\`$table\`")"
    done
}

is_volatile() { case "$VOLATILE_TABLES" in *" $1 "*) return 0 ;; *) return 1 ;; esac; }

image_id() { docker inspect -f '{{.Image}}' "$1" 2>/dev/null || true; }
image_digest() {
    local digest
    digest=$(docker image inspect -f '{{range .RepoDigests}}{{println .}}{{end}}' "$1" 2>/dev/null | head -n1 || true)
    echo "${digest#*@}"
}
short() { local v="${1#sha256:}"; echo "${v:0:12}"; }

container_running() { [ "$(docker inspect -f '{{.State.Running}}' "$1" 2>/dev/null || echo false)" = "true" ]; }
container_health() { docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$1" 2>/dev/null || echo missing; }

wait_healthy() {
    local name="$1" status=starting
    printf "  Waiting for %s" "$name"
    for _ in $(seq 1 $((HEALTH_TIMEOUT_S / 3))); do
        status=$(container_health "$name")
        [ "$status" = "healthy" ] && break
        printf "."
        sleep 3
    done
    echo
    [ "$status" = "healthy" ]
}

print_rollback() {
    echo
    echo "${C_B}Rollback${C_0} (the previous images are tagged :$ROLLBACK_TAG, the database backup is in $BACKUP_DIR):"
    echo "  1. Previous containers:"
    echo "       cd $ATGLANCE_DIR && sed -i 's/^ATGLANCE_VERSION=.*/ATGLANCE_VERSION=$ROLLBACK_TAG/' .env"
    echo "       cp $BACKUP_DIR/docker-compose.yml . && ${COMPOSE_CMD[*]} up -d --remove-orphans"
    echo "  2. Only if data is wrong, restore the database (replaces ALL current data):"
    echo "       gunzip -c $BACKUP_DIR/$DB_NAME.sql.gz | docker exec -i ce-atglance-db sh -c 'MYSQL_PWD=\"\$MYSQL_ROOT_PASSWORD\" mysql -uroot $DB_NAME'"
    echo "  3. Later, return to normal updates: set ATGLANCE_VERSION=latest in $ATGLANCE_DIR/.env"
}

# ---------------------------------------------------------------------------
# Clone clean-up. When this script runs from a git clone of the AtGlance repository
# (git clone ... && sudo ./scripts/atglance_update.sh), the clone is not needed once AtGlance is running:
# everything lives in the install directory and the Docker volumes. It is removed only
# after a successful run, and only when it holds nothing of the user's own:
# no uncommitted or untracked files, no stash, no commits that are not on the remote.
# --keep-clone (ATGLANCE_KEEP_CLONE=1) keeps it.
remove_clone() {
    [ "$KEEP_CLONE" = 1 ] && return 0
    [ -n "$SCRIPT_DIR" ] || return 0                      # piped from curl: no clone
    command -v git >/dev/null 2>&1 || return 0
    local g=(git -c safe.directory='*' -C "$SCRIPT_DIR")  # clone owned by the sudo user
    local top
    top=$("${g[@]}" rev-parse --show-toplevel 2>/dev/null) || return 0
    top=$(cd "$top" && pwd -P)
    case "$("${g[@]}" remote get-url origin 2>/dev/null)" in
        *niketchandra/atGlance-managementGUI*) ;;
        *) return 0 ;;
    esac
    local install
    install=$(cd "$ATGLANCE_DIR" 2>/dev/null && pwd -P) || return 0
    [ "$(printf '%s' "$top" | tr -cd / | wc -c)" -ge 2 ] || return 0          # never a top-level directory
    getent passwd 2>/dev/null | cut -d: -f6 | grep -Fx -- "$top" >/dev/null && return 0  # never a home directory
    case "$install/" in "$top"/*) warn "Kept the clone $top: the install directory is inside it."; return 0 ;; esac
    case "$top/" in "$install"/*) return 0 ;; esac
    if [ -n "$("${g[@]}" status --porcelain 2>/dev/null)" ] ||
       [ -n "$("${g[@]}" stash list 2>/dev/null)" ] ||
       [ -n "$("${g[@]}" log --branches --not --remotes --oneline 2>/dev/null)" ]; then
        warn "Kept the clone $top: it has local changes or commits. Remove it yourself when done: rm -rf $top"
        return 0
    fi
    cd / && rm -rf -- "$top" && ok "Removed the clone $top (not needed any more; --keep-clone keeps it)" ||
        warn "Could not remove the clone $top"
}

# ---------------------------------------------------------------------------
step "Checking the existing install"

[ "$(id -u)" -eq 0 ] || die "Run as root: sudo bash $0"
command -v docker >/dev/null 2>&1 || die "Docker is not installed. Use atglance-installer.sh for a new install."
docker info >/dev/null 2>&1 || die "The Docker daemon is not running."
[ -f "$ATGLANCE_DIR/docker-compose.yml" ] && [ -f "$ATGLANCE_DIR/.env" ] ||
    die "No AtGlance install in $ATGLANCE_DIR (docker-compose.yml and .env). Use --dir, or atglance-installer.sh for a new install."
cd "$ATGLANCE_DIR"
ok "Install directory $ATGLANCE_DIR"

compose_version=$(docker compose version --short 2>/dev/null | sed 's/^v//' || true)
[ -n "$compose_version" ] || die "Docker Compose plugin not found."
version_ge "$compose_version" "$MIN_COMPOSE" ||
    die "Docker Compose $compose_version is too old (need $MIN_COMPOSE or newer). Re-run atglance-installer.sh, which upgrades it."
ok "Docker Compose $compose_version"

for c in ce-atglance-app ce-atglance-db; do
    container_running "$c" || die "$c is not running. Start AtGlance first (cd $ATGLANCE_DIR && docker compose up -d), then update."
done
ok "AtGlance is running"

REGISTRY=$(grep -E '^ATGLANCE_REGISTRY=' .env | tail -n1 | cut -d= -f2- || true)
REGISTRY="${REGISTRY:-atglance}"
APP_PORT=$(grep -E '^APP_PORT=' .env | tail -n1 | cut -d= -f2- || true); APP_PORT="${APP_PORT:-8000}"
GATEWAY_PORT=$(grep -E '^GATEWAY_PORT=' .env | tail -n1 | cut -d= -f2- || true); GATEWAY_PORT="${GATEWAY_PORT:-8002}"

COMPOSE_CMD=(docker compose -f docker-compose.yml)
if [ -f docker-compose.domain.yml ] &&
    docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' ce-atglance-app 2>/dev/null | grep -x 'ATGLANCE_PROXY=builtin' >/dev/null; then
    COMPOSE_CMD+=(-f docker-compose.domain.yml)
    ok "Custom domain and HTTPS proxy in use: kept"
fi

db_size_mb=$(db_sql -e "SELECT CEIL(SUM(data_length + index_length) / 1024 / 1024) FROM information_schema.tables WHERE table_schema = '$DB_NAME'")
db_size_mb="${db_size_mb:-0}"; [ "$db_size_mb" = "NULL" ] && db_size_mb=0
free_mb=$(df -Pm "$ATGLANCE_DIR" | awk 'NR==2 {print $4}')
need_mb=$(( db_size_mb * 2 + 2048 ))
[ "$free_mb" -ge "$need_mb" ] || die "Need about ${need_mb} MB free in $ATGLANCE_DIR (database ${db_size_mb} MB + new images), found ${free_mb} MB."
ok "Disk: ${free_mb} MB free, database ${db_size_mb} MB"

# ---------------------------------------------------------------------------
step "1. Backing up the database"

STAMP=$(date -u +%Y%m%d-%H%M%S)
# Each backup has its own image tag, so an older backup still rolls back to its own version.
ROLLBACK_TAG="rollback-$STAMP"
BACKUP_DIR="$ATGLANCE_DIR/backups/update-$STAMP"
umask 077
mkdir -p "$BACKUP_DIR"

docker exec ce-atglance-db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysqldump -uroot --single-transaction --quick --routines --triggers --no-tablespaces --set-gtid-purged=OFF '"$DB_NAME" \
    | gzip > "$BACKUP_DIR/$DB_NAME.sql.gz"
gzip -t "$BACKUP_DIR/$DB_NAME.sql.gz" || die "The backup file is corrupt. Nothing was changed."
gunzip -c "$BACKUP_DIR/$DB_NAME.sql.gz" | tail -n 1 | grep 'Dump completed' >/dev/null ||
    die "The backup did not finish (no 'Dump completed' marker). Nothing was changed."
table_counts "$DB_NAME" > "$BACKUP_DIR/row-counts.txt"
[ -s "$BACKUP_DIR/row-counts.txt" ] || die "Could not read the database tables. Nothing was changed."
ok "Database: $BACKUP_DIR/$DB_NAME.sql.gz ($(du -h "$BACKUP_DIR/$DB_NAME.sql.gz" | cut -f1), $(wc -l < "$BACKUP_DIR/row-counts.txt") tables)"

# The app key encrypts saved secrets; the backup is useless for those without it.
if docker exec ce-atglance-app cat /app/storage/.env > "$BACKUP_DIR/app.env" 2>/dev/null && [ -s "$BACKUP_DIR/app.env" ]; then
    ok "App settings (APP_KEY): $BACKUP_DIR/app.env"
else
    rm -f "$BACKUP_DIR/app.env"
    warn "Could not copy the app's .env (APP_KEY). Back it up by hand: docker exec ce-atglance-app cat /app/storage/.env"
fi
cp docker-compose.yml .env "$BACKUP_DIR/"
[ -f docker-compose.domain.yml ] && cp docker-compose.domain.yml "$BACKUP_DIR/"
print_rollback | sed $'s/\e\\[[0-9;]*m//g' > "$BACKUP_DIR/ROLLBACK.txt"
umask 022

# Older update backups beyond --keep are removed.
ls -1d "$ATGLANCE_DIR"/backups/update-* 2>/dev/null | sort | head -n -"$KEEP_BACKUPS" | while read -r old; do
    for s in $SERVICES; do docker rmi "$REGISTRY/ce-atglance-$s:rollback-${old##*/update-}" >/dev/null 2>&1 || true; done
    rm -rf "$old"
done

# ---------------------------------------------------------------------------
step "2. Checksums of the running application"

declare -A OLD_ID OLD_DIGEST NEW_ID NEW_DIGEST
for s in $SERVICES; do
    OLD_ID[$s]=$(image_id "ce-atglance-$s")
    OLD_DIGEST[$s]=$( [ -n "${OLD_ID[$s]}" ] && image_digest "${OLD_ID[$s]}" || true )
    if [ -n "${OLD_ID[$s]}" ]; then
        ok "ce-atglance-$s  image $(short "${OLD_ID[$s]}")  digest ${OLD_DIGEST[$s]:-unknown}"
        # Kept under :rollback-<time> so the previous version can be started again.
        docker tag "${OLD_ID[$s]}" "$REGISTRY/ce-atglance-$s:$ROLLBACK_TAG"
    else
        warn "ce-atglance-$s is not present (it will be created)"
    fi
done

# ---------------------------------------------------------------------------
step "3. Checksums of the newest release"

for s in $SERVICES; do
    ref="$REGISTRY/ce-atglance-$s:latest"
    docker pull -q "$ref" >/dev/null 2>&1 || die "Could not pull $ref. Nothing was changed. Check the internet connection and Docker Hub."
    NEW_ID[$s]=$(docker image inspect -f '{{.Id}}' "$ref")
    NEW_DIGEST[$s]=$(image_digest "$ref")
    # A service that did not exist before rolls back to the new image (there is no older one).
    [ -n "${OLD_ID[$s]}" ] || docker tag "${NEW_ID[$s]}" "$REGISTRY/ce-atglance-$s:$ROLLBACK_TAG"
    if [ "${NEW_ID[$s]}" = "${OLD_ID[$s]}" ]; then
        ok "$ref  digest ${NEW_DIGEST[$s]}  (unchanged)"
    else
        ok "$ref  digest ${NEW_DIGEST[$s]}  (new)"
        UPDATED=1
    fi
done

if [ "$UPDATED" -eq 0 ] && [ "$FORCE" -eq 0 ]; then
    echo
    echo "${C_B}AtGlance is already up to date.${C_0} Nothing changed (backup kept in $BACKUP_DIR). Use --force to redeploy anyway."
    remove_clone
    exit 0
fi

if [ "$ASSUME_YES" -eq 0 ] && [ -t 0 ]; then
    printf "\n  Update now? Users may see errors for a minute while containers restart. [y/N] "
    read -r answer
    case "$answer" in y|Y|yes|YES) ;; *) echo "  Cancelled. Nothing was changed."; exit 1 ;; esac
fi

# ---------------------------------------------------------------------------
step "4. Updating all containers"

START_TS=$(date -u +%Y-%m-%dT%H:%M:%SZ)
if [ -n "$ATGLANCE_COMPOSE_FILE" ]; then
    cp "$ATGLANCE_COMPOSE_FILE" docker-compose.yml.new
elif ! curl -fsSL "$REPO_RAW/docker-compose.yml" -o docker-compose.yml.new; then
    rm -f docker-compose.yml.new
    warn "Could not download the newest docker-compose.yml; keeping the current one."
fi
if [ -f docker-compose.yml.new ]; then
    if docker compose -f docker-compose.yml.new config --quiet 2>/dev/null; then
        mv docker-compose.yml.new docker-compose.yml
        ok "docker-compose.yml updated"
    else
        rm -f docker-compose.yml.new
        warn "The downloaded docker-compose.yml is not valid; keeping the current one."
    fi
fi
if curl -fsSL "$REPO_RAW/docker-compose.domain.yml" -o docker-compose.domain.yml.new 2>/dev/null; then
    mv docker-compose.domain.yml.new docker-compose.domain.yml
else
    rm -f docker-compose.domain.yml.new
fi

grep -E '^ATGLANCE_VERSION=' .env >/dev/null && sed -i 's/^ATGLANCE_VERSION=.*/ATGLANCE_VERSION=latest/' .env || echo "ATGLANCE_VERSION=latest" >> .env

"${COMPOSE_CMD[@]}" pull --quiet
"${COMPOSE_CMD[@]}" up -d --remove-orphans
ok "Containers recreated (database migrations run when the app starts)"

# ---------------------------------------------------------------------------
step "5. Health checks"

if wait_healthy ce-atglance-app; then ok "ce-atglance-app healthy"; else
    docker logs --tail 30 ce-atglance-app 2>&1 | sed 's/^/    /'
    fail "ce-atglance-app did not become healthy within $((HEALTH_TIMEOUT_S / 60)) minutes (last lines above)"
fi
if wait_healthy ce-atglance-gateway; then ok "ce-atglance-gateway healthy"; else fail "ce-atglance-gateway did not become healthy"; fi

for c in ce-atglance-db ce-atglance-redis; do
    [ "$(container_health "$c")" = "healthy" ] && ok "$c healthy" || fail "$c is $(container_health "$c")"
done
for c in ce-atglance-worker ce-atglance-scheduler ce-atglance-controller; do
    container_running "$c" && ok "$c running" || fail "$c is not running"
done

declare -A RESTARTS
for c in ce-atglance-app ce-atglance-worker ce-atglance-scheduler ce-atglance-gateway; do
    RESTARTS[$c]=$(docker inspect -f '{{.RestartCount}}' "$c" 2>/dev/null || echo 0)
done

for s in $SERVICES; do
    running_id=$(image_id "ce-atglance-$s")
    if [ "$s" = "mcp" ] && [ -z "$running_id" ]; then continue; fi
    [ "$running_id" = "${NEW_ID[$s]}" ] && ok "ce-atglance-$s runs the new image $(short "$running_id")" ||
        fail "ce-atglance-$s runs $(short "$running_id"), expected $(short "${NEW_ID[$s]}")"
done

code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "http://127.0.0.1:$APP_PORT/up" || echo 000)
[ "$code" = "200" ] && ok "Web console answers (GET /up -> 200)" || fail "Web console GET http://127.0.0.1:$APP_PORT/up returned $code"

code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "http://127.0.0.1:$GATEWAY_PORT/" || echo 000)
[ "$code" != "000" ] && ok "API gateway answers on port $GATEWAY_PORT (HTTP $code)" || fail "API gateway on port $GATEWAY_PORT does not answer"

pending=$(docker exec ce-atglance-app php artisan migrate:status 2>/dev/null | grep -c 'Pending' || true)
[ "${pending:-0}" -eq 0 ] && ok "Database migrations: all applied" || fail "Database migrations: $pending pending"

# Read the worker's own process list (no dependency on the host's ps).
docker exec ce-atglance-worker sh -c 'for f in /proc/[0-9]*/cmdline; do tr "\0" " " < "$f"; echo; done' 2>/dev/null | grep 'queue:work' >/dev/null &&
    ok "Queue worker is processing (queue:work)" || fail "Queue worker process not found"
docker exec ce-atglance-app php artisan schedule:list >/dev/null 2>&1 && ok "Scheduler configuration loads (schedule:list)" || fail "php artisan schedule:list failed"

mcp_enabled=$(db_sql -e "SELECT setting_value FROM $DB_NAME.admin_settings WHERE setting_key = 'mcp_enabled'" 2>/dev/null || true)
if [ "$mcp_enabled" = "true" ]; then
    if wait_healthy ce-atglance-mcp; then
        code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 -X POST "http://127.0.0.1:$GATEWAY_PORT/mcp" \
            -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
            -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26","capabilities":{},"clientInfo":{"name":"atglance-update","version":"1"}}}' || echo 000)
        [ "$code" = "200" ] && ok "MCP server answers through the gateway" || warn "MCP server returned HTTP $code (Kong re-resolves the container within 5 seconds; check again shortly)"
    else
        fail "MCP is turned on but ce-atglance-mcp is not healthy"
    fi
else
    ok "MCP is off (the console stops ce-atglance-mcp within a minute)"
fi

sleep 20
for c in "${!RESTARTS[@]}"; do
    now=$(docker inspect -f '{{.RestartCount}}' "$c" 2>/dev/null || echo 0)
    [ "$now" -le "${RESTARTS[$c]}" ] || fail "$c restarted $((now - RESTARTS[$c])) times in the last 20 seconds (crash loop?)"
done
ok "No container restarted during the checks"

errors=$(docker logs --since "$START_TS" ce-atglance-app 2>&1 | grep -c 'production.ERROR' || true)
[ "${errors:-0}" -eq 0 ] && ok "No errors in the app log since the update" ||
    warn "$errors error lines in the app log since the update: docker logs --since $START_TS ce-atglance-app"

free_mb=$(df -Pm "$ATGLANCE_DIR" | awk 'NR==2 {print $4}')
[ "$free_mb" -ge 1024 ] && ok "Disk: ${free_mb} MB free" || warn "Disk: only ${free_mb} MB free"

# ---------------------------------------------------------------------------
step "6. Database check against the backup"

table_counts "$DB_NAME" > "$BACKUP_DIR/row-counts-after.txt"
compare() {  # compare <reference file> <file> <label>: every reference table must exist with at least as many rows
    local ref="$1" cur="$2" label="$3" table n_ref n_cur
    while read -r table n_ref; do
        n_cur=$(awk -v t="$table" '$1 == t {print $2}' "$cur")
        if [ -z "$n_cur" ]; then
            fail "$label: table $table is missing"
        elif [ "$n_cur" -lt "$n_ref" ]; then
            if is_volatile "$table"; then
                ok "$label: $table $n_ref -> $n_cur (changes on its own)"
            else
                fail "$label: table $table has $n_cur rows, backup has $n_ref"
            fi
        fi
    done < "$ref"
}

before_failures=$FAILURES
compare "$BACKUP_DIR/row-counts.txt" "$BACKUP_DIR/row-counts-after.txt" "Live database"
[ "$FAILURES" -eq "$before_failures" ] &&
    ok "Live database: all $(wc -l < "$BACKUP_DIR/row-counts.txt") tables present, no rows lost ($(( $(wc -l < "$BACKUP_DIR/row-counts-after.txt") - $(wc -l < "$BACKUP_DIR/row-counts.txt") )) new tables)"

if [ "$VERIFY_DB" -eq 1 ]; then
    # Restore the backup into a scratch database: proves it is complete and restorable.
    db_sql -e "DROP DATABASE IF EXISTS \`$SCRATCH_DB\`; CREATE DATABASE \`$SCRATCH_DB\`"
    if gunzip -c "$BACKUP_DIR/$DB_NAME.sql.gz" | db_sql "$SCRATCH_DB"; then
        table_counts "$SCRATCH_DB" > "$BACKUP_DIR/row-counts-restored.txt"
        if cmp -s "$BACKUP_DIR/row-counts.txt" "$BACKUP_DIR/row-counts-restored.txt"; then
            ok "Backup restores completely: every table and row count matches the database before the update"
        else
            before_failures=$FAILURES
            compare "$BACKUP_DIR/row-counts.txt" "$BACKUP_DIR/row-counts-restored.txt" "Restored backup"
            [ "$FAILURES" -eq "$before_failures" ] && ok "Backup restores: differences only in tables that change on their own"
        fi
    else
        fail "The backup could not be restored into a scratch database"
    fi
    db_sql -e "DROP DATABASE IF EXISTS \`$SCRATCH_DB\`"
fi

# ---------------------------------------------------------------------------
echo
if [ "$FAILURES" -gt 0 ]; then
    echo "${C_ERR}${C_B}Update finished with $FAILURES failed check(s) and $WARNINGS warning(s).${C_0}"
    print_rollback
    exit 2
fi

summary="AtGlance is updated and healthy."
[ "$WARNINGS" -gt 0 ] && summary="$summary ($WARNINGS warning(s) above)"
echo "${C_B}$summary${C_0}"
for s in $SERVICES; do
    [ "${OLD_ID[$s]}" = "${NEW_ID[$s]}" ] || echo "  ce-atglance-$s  $(short "${OLD_ID[$s]:-none}") -> $(short "${NEW_ID[$s]}")"
done
echo "  Backup:   $BACKUP_DIR"
echo "  Rollback: $BACKUP_DIR/ROLLBACK.txt (previous images are tagged :$ROLLBACK_TAG)"

remove_clone
