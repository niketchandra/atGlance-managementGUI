#!/usr/bin/env bash
# AtGlance Community Edition updater: deploys the newest release ("latest"). Updates an existing
# install, or deploys a new console when there is none.
#
#   curl -fsSL https://raw.githubusercontent.com/niketchandra/atGlance-managementGUI/main/scripts/atglance_update.sh | sudo bash
#   sudo bash atglance_update.sh [options]
#
# No .env file is needed, and this script names no service, image or setting of a release:
# everything comes from docker-compose.yml (see "Release contract" in INSTALLATION-UPDATE.md).
# Each compose variable is read from, in order: the environment, the install's .env, the
# containers of the install. Secrets (variables without a default, and *PASSWORD, *SECRET,
# *TOKEN, *_KEY variables) get a new random value on a new install. The values are written
# to .env, so plain "docker compose" commands keep working.
#
# Existing install: pre-checks -> database backup -> current image checksums -> new image
#     checksums -> update all containers -> health checks -> database check against the backup.
# New install (no Docker volume of the compose project yet): pre-checks -> deploy -> health checks.
#
# Options (or environment variables):
#   --dir DIR          install directory (ATGLANCE_DIR; default: the folder the running
#                      containers were deployed from, else /opt/atglance)
#   --force            redeploy even when the images are already the newest
#   --yes              do not ask for confirmation (ATGLANCE_YES=1)
#   --skip-db-verify   skip restoring the backup into a scratch database for the comparison
#   --keep N           backups to keep (default 5)
#   --keep-clone       keep the git clone this script runs from (ATGLANCE_KEEP_CLONE=1);
#                      by default a clean clone is removed after a successful run
#
# Exit codes: 0 updated, installed or already up to date, 1 stopped before changing anything,
#             2 updated or installed but a check failed (rollback steps are printed on an update).
set -euo pipefail
# Note: no "grep -q" after a pipe: it exits early, the writer gets SIGPIPE and pipefail fails the check.

ATGLANCE_DIR="${ATGLANCE_DIR:-}"
ATGLANCE_REF="${ATGLANCE_REF:-main}"
REPO_RAW="https://raw.githubusercontent.com/niketchandra/atGlance-managementGUI/${ATGLANCE_REF}"
# Local compose file instead of downloading one (testing a branch).
ATGLANCE_COMPOSE_FILE="${ATGLANCE_COMPOSE_FILE:-}"
FORCE=0
ASSUME_YES="${ATGLANCE_YES:-0}"
VERIFY_DB=1
KEEP_BACKUPS=5
MIN_COMPOSE=2.23.0
MIN_DISK_GB=5
HEALTH_TIMEOUT_S=300
SCRATCH_DB=atglance_update_verify
# Compose files published in the repository: the main file, then the opt-in overlays.
COMPOSE_NAMES="docker-compose.yml docker-compose.domain.yml"
# Image tag variable of the AtGlance images (rollback re-tags them).
VERSION_VAR=ATGLANCE_VERSION
SECRET_RE='(PASSWORD|SECRET|TOKEN|_KEY)$'
# Containers with this label may be stopped (the console starts and stops them).
OPTIONAL_LABEL=com.atglance.optional
DB_IMAGE_RE='(^|/)(mysql|mariadb|percona[^:/]*)(:|$)'
# Tables that change on their own (cache, queues, sessions, logs): fewer rows is not data loss.
VOLATILE_RE='^(cache|cache_locks|jobs|job_batches|failed_jobs|sessions|session_tokens)$|_(cache|locks|jobs|sessions|logs)$'
PROBE=__atglance_probe_

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
        -h|--help) sed -n '2,30p' "$0" 2>/dev/null || true; exit 0 ;;
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

version_ge() { [ "$(printf '%s\n%s\n' "$2" "$1" | sort -V | head -n1)" = "$2" ]; }
short() { local v="${1#sha256:}"; echo "${v:0:12}"; }
random_secret() { head -c 48 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 32; }
in_list() { case " $2 " in *" $1 "*) return 0 ;; *) return 1 ;; esac; }

# ---------------------------------------------------------------------------
# Compose model. Everything about a release is read from its compose files.

# "NAME HAS_DEFAULT" for every ${NAME...} in the files ($${...} is an escaped literal, not a variable).
compose_vars() {
    { sed -E 's/(^|[[:space:]])#.*$//' "$@" | grep -oE '(^|[^$])\$\{[A-Za-z_][A-Za-z0-9_]*[^}]*\}' || true; } |
        sed -E 's/^[^$]?\$\{//; s/\}$//' |
        awk '{ name = $0; sub(/[^A-Za-z0-9_].*/, "", name); d = (substr($0, length(name) + 1) ~ /^:?-/) ? 1 : 0
               if (!(name in seen) || d == 0) seen[name] = d }
             END { for (n in seen) print n, seen[n] }' | sort
}
is_secret() { [ "$2" = 0 ] || [[ "$1" =~ $SECRET_RE ]]; }

# One fact per line from "docker compose config" output:
#   S service image | P service published-port | O service (optional)
parse_model() {
    awk -v opt="$OPTIONAL_LABEL:" '
        /^[^ ]/ { ins = ($0 == "services:"); next }
        !ins { next }
        /^  [^ ]/ { svc = $1; sub(/:$/, "", svc); inl = 0; next }
        /^    [^ ]/ { inl = 0 }
        /^    labels:/ { inl = 1; next }
        /^    image: / { print "S", svc, $2; next }
        inl && $1 == opt { v = $2; gsub(/"/, "", v); if (v == "true") print "O", svc; next }
        /^      (- |  )published: / { p = $NF; gsub(/"/, "", p); print "P", svc, p; next }'
}
model_get() { printf '%s\n' "$1" | awk -v t="$2" '$1 == t { $1 = ""; sub(/^ /, ""); print }'; }

svc_container() {
    docker ps -a --filter "label=com.docker.compose.project=$PROJECT" --filter "label=com.docker.compose.service=$1" \
        --format '{{.Names}}' 2>/dev/null | head -n1 || true
}
container_env() {
    docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' "$1" 2>/dev/null |
        awk -v k="$2=" 'index($0, k) == 1 { print substr($0, length(k) + 1); exit }' || true
}
container_running() { [ "$(docker inspect -f '{{.State.Running}}' "$1" 2>/dev/null || echo false)" = "true" ]; }
container_health() { docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$1" 2>/dev/null || echo missing; }
image_id() { docker inspect -f '{{.Image}}' "$1" 2>/dev/null || true; }
image_digest() {
    local digest
    digest=$(docker image inspect -f '{{range .RepoDigests}}{{println .}}{{end}}' "$1" 2>/dev/null | head -n1 || true)
    echo "${digest#*@}"
}

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

# The app container: the one started with ATGLANCE_ROLE=app (holds APP_KEY and runs migrations).
find_app_container() {
    local svc c
    for svc in $SERVICES; do
        c=$(svc_container "$svc")
        [ -n "$c" ] && [ "$(container_env "$c" ATGLANCE_ROLE)" = app ] && { echo "$c"; return; }
    done
    return 0
}

# SQL as root inside the database container; the password stays in the container (MYSQL_PWD).
db_sql() {
    docker exec -i "$DB_CONTAINER" sh -c 'MYSQL_PWD="${MYSQL_ROOT_PASSWORD:-$MARIADB_ROOT_PASSWORD}" exec mysql -uroot -N -B "$@"' sh "$@"
}
# "table count" lines for every base table of a database, sorted by table name.
table_counts() {
    local db="$1" table
    for table in $(db_sql -e "SELECT table_name FROM information_schema.tables WHERE table_schema = '$db' AND table_type = 'BASE TABLE' ORDER BY table_name"); do
        echo "$table $(db_sql -e "SELECT COUNT(*) FROM \`$db\`.\`$table\`")"
    done
}

# .env is optional; these read and write single lines of it.
env_file_get() {
    [ -f .env ] || return 0
    awk -v k="$1=" 'index($0, k) == 1 { v = substr($0, length(k) + 1) }
        END { if (v ~ /^".*"$/ || v ~ /^\x27.*\x27$/) v = substr(v, 2, length(v) - 2); printf "%s", v }' .env
}
env_file_set() {
    [ -f .env ] || (umask 077; echo "# AtGlance CE settings, written by atglance_update.sh on $(date -u +%Y-%m-%dT%H:%M:%SZ)." > .env)
    (umask 077; K="$1" V="$2" awk 'BEGIN { k = ENVIRON["K"] "="; v = ENVIRON["V"] }
        index($0, k) == 1 { if (!done) print k v; done = 1; next } { print }
        END { if (!done) print k v }' .env > .env.tmp)
    cat .env.tmp > .env && rm -f .env.tmp
}

print_rollback() {
    echo
    echo "${C_B}Rollback${C_0} (the previous images are tagged :$ROLLBACK_TAG, the database backup is in $BACKUP_DIR):"
    echo "  1. Previous containers:"
    echo "       cd $ATGLANCE_DIR && cp $BACKUP_DIR/docker-compose*.yml $BACKUP_DIR/.env ."
    echo "       grep '^$VERSION_VAR=' .env >/dev/null && sed -i 's/^$VERSION_VAR=.*/$VERSION_VAR=$ROLLBACK_TAG/' .env || echo '$VERSION_VAR=$ROLLBACK_TAG' >> .env"
    echo "       docker compose ${DIR_ARGS[*]} up -d --remove-orphans"
    if [ -n "$DB_CONTAINER" ]; then
        echo "  2. Only if data is wrong, restore the database (replaces ALL current data):"
        echo "       gunzip -c $BACKUP_DIR/$DB_NAME.sql.gz | docker exec -i $DB_CONTAINER sh -c 'MYSQL_PWD=\"\$MYSQL_ROOT_PASSWORD\" mysql -uroot $DB_NAME'"
    fi
    echo "  3. Later, return to normal updates: set $VERSION_VAR=latest in $ATGLANCE_DIR/.env"
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
step "Checking Docker"

[ "$(id -u)" -eq 0 ] || die "Run as root: sudo bash $0"
command -v docker >/dev/null 2>&1 || die "Docker is not installed. Run atglance-installer.sh, which installs it."
command -v curl >/dev/null 2>&1 || die "curl is required."
docker info >/dev/null 2>&1 || die "The Docker daemon is not running."
compose_version=$(docker compose version --short 2>/dev/null | sed 's/^v//' || true)
[ -n "$compose_version" ] || die "Docker Compose plugin not found. Run atglance-installer.sh, which installs it."
version_ge "$compose_version" "$MIN_COMPOSE" ||
    die "Docker Compose $compose_version is too old (need $MIN_COMPOSE or newer). Run atglance-installer.sh, which upgrades it."
ok "Docker Compose $compose_version"

# ---------------------------------------------------------------------------
step "Checking the release and the install"

NEW_DIR=$(mktemp -d)
trap 'rm -rf "$NEW_DIR"' EXIT
fetched=1
for f in $COMPOSE_NAMES; do
    if [ "$f" = docker-compose.yml ] && [ -n "$ATGLANCE_COMPOSE_FILE" ]; then
        cp "$ATGLANCE_COMPOSE_FILE" "$NEW_DIR/$f"
    elif ! curl -fsSL "$REPO_RAW/$f" -o "$NEW_DIR/$f" 2>/dev/null; then
        rm -f "$NEW_DIR/$f"
        [ "$f" = docker-compose.yml ] && fetched=0
    fi
done
if [ "$fetched" = 1 ] && ! docker compose -f "$NEW_DIR/docker-compose.yml" config --quiet 2>/dev/null; then
    rm -f "$NEW_DIR/docker-compose.yml"; fetched=0
    warn "The newest docker-compose.yml does not load with Docker Compose $compose_version (too old, or a download problem)."
fi

# Install directory: --dir / ATGLANCE_DIR, else the folder the project's containers were deployed from.
PROJECT=""
[ "$fetched" = 1 ] && PROJECT=$(docker compose -f "$NEW_DIR/docker-compose.yml" config 2>/dev/null | awk '/^name: / { print $2; exit }' || true)
if [ -z "$ATGLANCE_DIR" ] && [ -n "$PROJECT" ]; then
    any=$(docker ps -a --filter "label=com.docker.compose.project=$PROJECT" --format '{{.Names}}' 2>/dev/null | head -n1 || true)
    [ -n "$any" ] && ATGLANCE_DIR=$(docker inspect -f '{{index .Config.Labels "com.docker.compose.project.working_dir"}}' "$any" 2>/dev/null || true)
    [ -d "$ATGLANCE_DIR" ] || ATGLANCE_DIR=""
fi
ATGLANCE_DIR="${ATGLANCE_DIR:-/opt/atglance}"
mkdir -p "$ATGLANCE_DIR"
cd "$ATGLANCE_DIR"
ok "Install directory $ATGLANCE_DIR"

if [ "$fetched" = 0 ]; then
    [ -f docker-compose.yml ] || die "Could not download docker-compose.yml from $REPO_RAW. Check the internet connection."
    warn "Using the current docker-compose.yml (the newest one could not be used)."
    cp docker-compose.yml "$NEW_DIR/docker-compose.yml"
fi
[ -n "$PROJECT" ] || PROJECT=$(docker compose -f "$NEW_DIR/docker-compose.yml" --project-directory "$ATGLANCE_DIR" config 2>/dev/null | awk '/^name: / { print $2; exit }' || true)
[ -n "$PROJECT" ] || die "docker-compose.yml does not load with Docker Compose $compose_version."

# Overlays the install was deployed with (custom domain, ...) stay in use.
FILES=(docker-compose.yml)
any=$(docker ps -a --filter "label=com.docker.compose.project=$PROJECT" --format '{{.Names}}' 2>/dev/null | head -n1 || true)
if [ -n "$any" ]; then
    used=$(docker inspect -f '{{index .Config.Labels "com.docker.compose.project.config_files"}}' "$any" 2>/dev/null || true)
    IFS=, read -r -a used_files <<< "$used"
    for p in "${used_files[@]}"; do
        b=$(basename "$p")
        [ "$b" = docker-compose.yml ] && continue
        [ -f "$NEW_DIR/$b" ] || { [ -f "$b" ] && cp "$b" "$NEW_DIR/$b"; } || continue
        FILES+=("$b"); ok "Compose file $b in use: kept"
    done
fi
NEW_ARGS=(--project-directory "$ATGLANCE_DIR"); DIR_ARGS=()
for f in "${FILES[@]}"; do NEW_ARGS+=(-f "$NEW_DIR/$f"); DIR_ARGS+=(-f "$f"); done

# No Docker volume of the project yet: a new install.
FRESH=0
[ -n "$(docker volume ls -q --filter "label=com.docker.compose.project=$PROJECT" 2>/dev/null || true)" ] || FRESH=1
[ "$FRESH" = 1 ] && ok "No AtGlance install found: a new console will be deployed" || ok "Existing install (compose project $PROJECT)"

# ---------------------------------------------------------------------------
# Settings: every variable of the compose files, without a required .env.
VARS=$(compose_vars "${FILES[@]/#/$NEW_DIR/}")
version_probe=("$VERSION_VAR=${PROBE}${VERSION_VAR}__")
DB_SVC=$(docker compose "${NEW_ARGS[@]}" config 2>/dev/null | parse_model | awk -v re="$DB_IMAGE_RE" '$1 == "S" && $3 ~ re { print $2; exit }' || true)

# Where a variable lands in the rendered compose files, found by rendering it with a marker value
# (a path or a number when a word does not load, as for bind mounts and ports).
# One use per line, database service first:
#   E service ENV_KEY | P service container-port | I service image-template | V service container-path source-template
var_uses() {
    local name="$1" mark out=""
    for mark in "${PROBE}${1}__" "/${PROBE}${1}__" "$((61000 + RANDOM % 4000))"; do
        out=$(env "$name=$mark" docker compose "${NEW_ARGS[@]}" config 2>/dev/null) && break
        out=""
    done
    [ -n "$out" ] || return 0
    printf '%s\n' "$out" | awk -v m="$mark" -v db="$DB_SVC" '
        /^[^ ]/ { ins = ($0 == "services:"); next }
        !ins { next }
        /^  [^ ]/ { svc = $1; sub(/:$/, "", svc); blk = ""; next }
        /^    [^ ]/ { blk = $1; sub(/:$/, "", blk) }
        /^    image: / { if (index($2, m)) print 1, "I", svc, $2, m; next }
        blk == "environment" && /^      [^ ]+: / {
            key = $1; sub(/:$/, "", key); v = $0; sub(/^ *[^ ]+: /, "", v); gsub(/^"|"$/, "", v)
            if (v == m) print (svc == db ? 0 : 1), "E", svc, key; next }
        blk == "ports" && /^      (- |  )target: / { tgt = $NF; next }
        blk == "ports" && /^      (- |  )published: / { p = $NF; gsub(/"/, "", p); if (p == m) print 1, "P", svc, tgt; next }
        blk == "volumes" && /^      (- |  )source: / { src = $NF; next }
        blk == "volumes" && /^      (- |  )target: / { if (index(src, m)) print 1, "V", svc, $NF, src, m; next }
    ' | sort -n -s -k1,1 | cut -d' ' -f2-
}
# The part of ACTUAL that MARK stands for in TEMPLATE ("" when ACTUAL does not fit the template).
template_value() {
    local tmpl="$1" mark="$2" actual="$3" pre suf
    pre="${tmpl%%"$mark"*}"; suf="${tmpl#*"$mark"}"
    case "$actual" in "$pre"*"$suf") actual="${actual#"$pre"}"; printf '%s' "${actual%"$suf"}" ;; esac
}
# Value of a variable as the existing containers got it.
from_containers() {
    local kind svc a b mark c v
    while read -r kind svc a b mark; do
        c=$(svc_container "$svc"); [ -n "$c" ] || continue
        case "$kind" in
            E) v=$(container_env "$c" "$a") ;;
            P) v=$(docker port "$c" "$a" 2>/dev/null | sed -n 's/.*:\([0-9][0-9]*\)$/\1/p' | head -n1 || true) ;;
            I) v=$(template_value "$a" "$b" "$(docker inspect -f '{{.Config.Image}}' "$c" 2>/dev/null || true)") ;;
            V) v=$(template_value "$b" "$mark" "$(docker inspect -f '{{range .Mounts}}{{if eq .Destination "'"$a"'"}}{{.Source}}{{end}}{{end}}' "$c" 2>/dev/null || true)") ;;
            *) v="" ;;
        esac
        [ -n "$v" ] && { printf '%s' "$v"; return 0; }
    done <<< "$2"
    return 0
}
# True when a service that uses the variable already has a container.
used_by_container() {
    local kind svc rest
    while read -r kind svc rest; do
        [ -n "$svc" ] && [ -n "$(svc_container "$svc")" ] && return 0
    done <<< "$1"
    return 1
}

missing=""; generated=0; recovered=0
while read -r name has_default; do
    [ -n "$name" ] && [ "$name" != "$VERSION_VAR" ] || continue
    v="${!name:-}"
    [ -n "$v" ] || v=$(env_file_get "$name")
    if [ -z "$v" ] && [ "$FRESH" = 0 ]; then
        uses=$(var_uses "$name")
        v=$(from_containers "$name" "$uses")
        [ -n "$v" ] && recovered=$((recovered + 1))
    fi
    if [ -z "$v" ] && is_secret "$name" "$has_default"; then
        # A new install, or a variable this release adds (no container uses it yet): a new value.
        # With no containers at all (after "docker compose down") the old value cannot be told.
        if [ "$FRESH" = 1 ] || { [ -n "$any" ] && ! used_by_container "$uses"; }; then v=$(random_secret); generated=$((generated + 1))
        else missing="$missing $name"
        fi
    fi
    # Not set anywhere and not a secret: the compose default applies.
    [ -n "$v" ] || continue
    export "$name=$v"
    [ "$(env_file_get "$name")" = "$v" ] || env_file_set "$name" "$v"
done <<< "$VARS"
[ -z "$missing" ] || die "Found the AtGlance volumes but not the value of:$missing (no .env and no containers to read it from).
    Re-run with --dir pointing at the original install folder, or set those variables for this run."
chmod 600 .env 2>/dev/null || true
export "$VERSION_VAR=latest"
ok "Settings: $ATGLANCE_DIR/.env ($generated generated, $recovered read from the containers)"

MODEL=$(docker compose "${NEW_ARGS[@]}" config 2>/dev/null | parse_model || true)
SERVICES=$(model_get "$MODEL" S | awk '{ print $1 }' | tr '\n' ' ')
OPTIONAL=$(model_get "$MODEL" O | tr '\n' ' ')
[ -n "$SERVICES" ] || die "docker-compose.yml has no services."
declare -A IMAGE
while read -r svc img; do IMAGE[$svc]=$img; done < <(model_get "$MODEL" S)

if [ "$FRESH" = 1 ]; then
    disk_gb=$(df -Pk "$ATGLANCE_DIR" | awk 'NR==2 {print int($4/1024/1024)}')
    [ "$disk_gb" -ge "$MIN_DISK_GB" ] || die "Need ${MIN_DISK_GB} GB free in $ATGLANCE_DIR, found ${disk_gb} GB."
    ok "Disk: ${disk_gb} GB free"
    ports=$(model_get "$MODEL" P | awk '{ print $2 }' | sort -un | tr '\n' ' ')
    for p in $ports; do
        if command -v ss >/dev/null 2>&1 && ss -ltnH "sport = :$p" 2>/dev/null | grep . >/dev/null; then
            die "Port $p is in use. Free it (or set its port variable, see docker-compose.yml) and re-run."
        fi
    done
    ok "Ports free: $ports"
fi

DB_CONTAINER=""; DB_NAME=""
if [ "$FRESH" = 0 ]; then
    if [ -z "$DB_SVC" ]; then
        warn "No MySQL/MariaDB service in docker-compose.yml: the database backup and checks are skipped."
    else
        DB_CONTAINER=$(svc_container "$DB_SVC")
        if [ -z "$DB_CONTAINER" ] || ! container_running "$DB_CONTAINER"; then
            warn "The database is not running: starting it for the backup"
            if [ -n "$DB_CONTAINER" ]; then docker start "$DB_CONTAINER" >/dev/null
            else docker compose "${NEW_ARGS[@]}" up -d --no-deps "$DB_SVC" >/dev/null 2>&1; DB_CONTAINER=$(svc_container "$DB_SVC")
            fi
            [ -n "$DB_CONTAINER" ] && wait_healthy "$DB_CONTAINER" || die "The database did not start. Check: cd $ATGLANCE_DIR && docker compose logs $DB_SVC"
        fi
        DB_NAME=$(container_env "$DB_CONTAINER" MYSQL_DATABASE); DB_NAME="${DB_NAME:-$(container_env "$DB_CONTAINER" MARIADB_DATABASE)}"
        [ -n "$DB_NAME" ] || die "Cannot tell the database name (MYSQL_DATABASE of $DB_CONTAINER)."
        ok "Database $DB_NAME in $DB_CONTAINER"

        db_size_mb=$(db_sql -e "SELECT CEIL(SUM(data_length + index_length) / 1024 / 1024) FROM information_schema.tables WHERE table_schema = '$DB_NAME'")
        db_size_mb="${db_size_mb:-0}"; [ "$db_size_mb" = "NULL" ] && db_size_mb=0
        free_mb=$(df -Pm "$ATGLANCE_DIR" | awk 'NR==2 {print $4}')
        need_mb=$(( db_size_mb * 2 + 2048 ))
        [ "$free_mb" -ge "$need_mb" ] || die "Need about ${need_mb} MB free in $ATGLANCE_DIR (database ${db_size_mb} MB + new images), found ${free_mb} MB."
        ok "Disk: ${free_mb} MB free, database ${db_size_mb} MB"
    fi
fi

declare -A OLD_ID NEW_ID
STAMP=$(date -u +%Y%m%d-%H%M%S)
# Each backup has its own image tag, so an older backup still rolls back to its own version.
ROLLBACK_TAG="rollback-$STAMP"
BACKUP_DIR="$ATGLANCE_DIR/backups/update-$STAMP"

if [ "$FRESH" = 0 ]; then
    # -------------------------------------------------------------------------
    step "1. Backing up"

    umask 077
    mkdir -p "$BACKUP_DIR"
    if [ -n "$DB_CONTAINER" ]; then
        docker exec "$DB_CONTAINER" sh -c 'MYSQL_PWD="${MYSQL_ROOT_PASSWORD:-$MARIADB_ROOT_PASSWORD}" exec mysqldump -uroot --single-transaction --quick --routines --triggers --no-tablespaces --set-gtid-purged=OFF '"$DB_NAME" \
            | gzip > "$BACKUP_DIR/$DB_NAME.sql.gz"
        gzip -t "$BACKUP_DIR/$DB_NAME.sql.gz" || die "The backup file is corrupt. Nothing was changed."
        gunzip -c "$BACKUP_DIR/$DB_NAME.sql.gz" | tail -n 1 | grep 'Dump completed' >/dev/null ||
            die "The backup did not finish (no 'Dump completed' marker). Nothing was changed."
        table_counts "$DB_NAME" > "$BACKUP_DIR/row-counts.txt"
        [ -s "$BACKUP_DIR/row-counts.txt" ] || die "Could not read the database tables. Nothing was changed."
        ok "Database: $BACKUP_DIR/$DB_NAME.sql.gz ($(du -h "$BACKUP_DIR/$DB_NAME.sql.gz" | cut -f1), $(wc -l < "$BACKUP_DIR/row-counts.txt") tables)"
    fi

    # The app key encrypts saved secrets; the backup is useless for those without it.
    APP_CONTAINER=$(find_app_container)
    if [ -n "$APP_CONTAINER" ] && docker cp "$APP_CONTAINER:/app/storage/.env" "$BACKUP_DIR/app.env" >/dev/null 2>&1 && [ -s "$BACKUP_DIR/app.env" ]; then
        ok "App settings (APP_KEY): $BACKUP_DIR/app.env"
    else
        rm -f "$BACKUP_DIR/app.env"
        warn "Could not copy the app's .env (APP_KEY). Back it up by hand: docker cp ${APP_CONTAINER:-ce-atglance-app}:/app/storage/.env ."
    fi
    cp .env "$BACKUP_DIR/"
    for f in docker-compose*.yml; do [ -f "$f" ] && cp "$f" "$BACKUP_DIR/"; done
    print_rollback | sed $'s/\e\\[[0-9;]*m//g' > "$BACKUP_DIR/ROLLBACK.txt"
    umask 022

    # Older update backups beyond --keep are removed, with their rollback image tags.
    ls -1d "$ATGLANCE_DIR"/backups/update-* 2>/dev/null | sort | head -n -"$KEEP_BACKUPS" | while read -r old; do
        docker images --format '{{.Repository}}:{{.Tag}}' 2>/dev/null | awk -v t=":rollback-${old##*/update-}" 'substr($0, length($0) - length(t) + 1) == t' |
            while read -r ref; do docker rmi "$ref" >/dev/null 2>&1 || true; done
        rm -rf "$old"
    done

    # -------------------------------------------------------------------------
    step "2. Checksums of the running application"

    # Rollback needs every image the current compose files tag with $VERSION_VAR.
    ROLLBACK_ARGS=(--project-directory "$ATGLANCE_DIR")
    if [ -f docker-compose.yml ]; then for f in "${FILES[@]}"; do [ -f "$f" ] && ROLLBACK_ARGS+=(-f "$f"); done
    else ROLLBACK_ARGS=("${NEW_ARGS[@]}"); fi
    ROLLBACK_REPOS=$(env "${version_probe[@]}" docker compose "${ROLLBACK_ARGS[@]}" config 2>/dev/null | parse_model |
        awk -v t=":${PROBE}${VERSION_VAR}__" '$1 == "S" && substr($3, length($3) - length(t) + 1) == t { print $2, substr($3, 1, length($3) - length(t)) }' || true)
    while read -r svc repo; do
        [ -n "$svc" ] || continue
        c=$(svc_container "$svc"); id=$( [ -n "$c" ] && image_id "$c" || true )
        # Kept under :rollback-<time> so the previous version can be started again.
        [ -n "$id" ] && docker tag "$id" "$repo:$ROLLBACK_TAG"
    done <<< "$ROLLBACK_REPOS"

    for svc in $SERVICES; do
        c=$(svc_container "$svc")
        OLD_ID[$svc]=$( [ -n "$c" ] && image_id "$c" || true )
        if [ -n "${OLD_ID[$svc]}" ]; then
            digest=$(image_digest "${OLD_ID[$svc]}")
            ok "$c  image $(short "${OLD_ID[$svc]}")  digest ${digest:-unknown}"
        else
            warn "Service $svc has no container (it will be created)"
        fi
    done
fi

# ---------------------------------------------------------------------------
step "3. Checksums of the newest release"

updated=$FRESH
for ref in $(printf '%s\n' "${IMAGE[@]}" | sort -u); do
    if ! out=$(docker pull -q "$ref" 2>&1); then
        echo "$out" | tail -n 3 >&2
        case "$out" in
            *"no matching manifest"*|*"does not match the specified platform"*) die "$ref has no build for this CPU ($(uname -m)). Nothing was changed." ;;
            *"not found"*|*"manifest unknown"*) die "$ref does not exist. Check the registry setting (ATGLANCE_REGISTRY). Nothing was changed." ;;
            *) die "Could not pull $ref. Nothing was changed. Check the internet connection and the registry." ;;
        esac
    fi
done
for svc in $SERVICES; do
    NEW_ID[$svc]=$(docker image inspect -f '{{.Id}}' "${IMAGE[$svc]}")
    digest=$(image_digest "${IMAGE[$svc]}")
    if [ "${NEW_ID[$svc]}" = "${OLD_ID[$svc]:-}" ]; then ok "${IMAGE[$svc]}  digest $digest  (unchanged)"
    else ok "${IMAGE[$svc]}  digest $digest  (new)"; updated=1
    fi
    c=$(svc_container "$svc")
    # Missing or stopped containers (for example after "docker compose down") are deployed too.
    in_list "$svc" "$OPTIONAL" || { [ -n "$c" ] && container_running "$c"; } || updated=1
done
if [ "$FRESH" = 0 ]; then
    # A service without an older image rolls back to the new one.
    while read -r svc repo; do
        [ -n "$svc" ] || continue
        docker image inspect "$repo:$ROLLBACK_TAG" >/dev/null 2>&1 || docker tag "$repo:latest" "$repo:$ROLLBACK_TAG" >/dev/null 2>&1 || true
    done <<< "$ROLLBACK_REPOS"
fi

if [ "$updated" -eq 0 ] && [ "$FORCE" -eq 0 ]; then
    echo
    echo "${C_B}AtGlance is already up to date.${C_0} Nothing changed (backup kept in $BACKUP_DIR). Use --force to redeploy anyway."
    remove_clone
    exit 0
fi

if [ "$FRESH" = 0 ] && [ "$ASSUME_YES" != 1 ] && [ -t 0 ]; then
    printf "\n  Update now? Users may see errors for a minute while containers restart. [y/N] "
    read -r answer
    case "$answer" in y|Y|yes|YES) ;; *) echo "  Cancelled. Nothing was changed."; exit 1 ;; esac
fi

# ---------------------------------------------------------------------------
[ "$FRESH" = 1 ] && step "4. Deploying all containers" || step "4. Updating all containers"

START_TS=$(date -u +%Y-%m-%dT%H:%M:%SZ)
for f in $COMPOSE_NAMES "${FILES[@]}"; do [ -f "$NEW_DIR/$f" ] && cp "$NEW_DIR/$f" "$f"; done
ok "Compose files: ${FILES[*]}"
env_file_set "$VERSION_VAR" latest
docker compose "${DIR_ARGS[@]}" up -d --remove-orphans || fail "docker compose up failed"
ok "Containers recreated (database migrations run when the app starts)"

# ---------------------------------------------------------------------------
step "5. Health checks"

declare -A RESTARTS
for svc in $SERVICES; do
    c=$(svc_container "$svc")
    optional=0; in_list "$svc" "$OPTIONAL" && optional=1
    if [ -z "$c" ]; then
        [ "$optional" = 1 ] && ok "$svc: no container (optional)" || fail "$svc: no container"
        continue
    fi
    if ! container_running "$c" && [ "$optional" = 1 ]; then ok "$c stopped (optional; the console starts it)"; continue; fi
    if [ "$(container_health "$c")" = none ]; then
        container_running "$c" && ok "$c running" || fail "$c is not running"
    elif wait_healthy "$c"; then
        ok "$c healthy"
    else
        docker logs --tail 30 "$c" 2>&1 | sed 's/^/    /'
        if [ "$optional" = 1 ]; then warn "$c did not become healthy (optional)"
        else fail "$c did not become healthy within $((HEALTH_TIMEOUT_S / 60)) minutes (last lines above)"; fi
    fi
    running_id=$(image_id "$c")
    [ "$running_id" = "${NEW_ID[$svc]}" ] && ok "$c runs the new image $(short "$running_id")" ||
        fail "$c runs $(short "$running_id"), expected $(short "${NEW_ID[$svc]}")"
    RESTARTS[$c]=$(docker inspect -f '{{.RestartCount}}' "$c" 2>/dev/null || echo 0)
done

while read -r svc port; do
    [ -n "$port" ] || continue
    code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "http://127.0.0.1:$port/" || true)
    [ -n "$code" ] && [ "$code" != "000" ] && ok "$svc answers on port $port (HTTP $code)" || fail "$svc does not answer on port $port"
done < <(model_get "$MODEL" P)

APP_CONTAINER=$(find_app_container)
if [ -n "$APP_CONTAINER" ]; then
    if status=$(docker exec "$APP_CONTAINER" php artisan migrate:status 2>/dev/null); then
        pending=$(printf '%s\n' "$status" | grep -c 'Pending' || true)
        [ "${pending:-0}" -eq 0 ] && ok "Database migrations: all applied" || fail "Database migrations: $pending pending"
    else
        fail "php artisan migrate:status failed in $APP_CONTAINER"
    fi
fi

sleep 20
for c in "${!RESTARTS[@]}"; do
    now=$(docker inspect -f '{{.RestartCount}}' "$c" 2>/dev/null || echo 0)
    [ "$now" -le "${RESTARTS[$c]}" ] || fail "$c restarted $((now - RESTARTS[$c])) times in the last 20 seconds (crash loop?)"
done
ok "No container restarted during the checks"

if [ -n "$APP_CONTAINER" ]; then
    errors=$(docker logs --since "$START_TS" "$APP_CONTAINER" 2>&1 | grep -c 'production.ERROR' || true)
    [ "${errors:-0}" -eq 0 ] && ok "No errors in the app log since the update" ||
        warn "$errors error lines in the app log since the update: docker logs --since $START_TS $APP_CONTAINER"
fi

free_mb=$(df -Pm "$ATGLANCE_DIR" | awk 'NR==2 {print $4}')
[ "$free_mb" -ge 1024 ] && ok "Disk: ${free_mb} MB free" || warn "Disk: only ${free_mb} MB free"

# ---------------------------------------------------------------------------
if [ "$FRESH" = 0 ] && [ -n "$DB_CONTAINER" ]; then
    step "6. Database check against the backup"

    DB_CONTAINER=$(svc_container "$DB_SVC")
    table_counts "$DB_NAME" > "$BACKUP_DIR/row-counts-after.txt"
    compare() {  # compare <reference file> <file> <label>: every reference table must keep its rows
        local ref="$1" cur="$2" label="$3" table n_ref n_cur
        while read -r table n_ref; do
            n_cur=$(awk -v t="$table" '$1 == t {print $2}' "$cur")
            if [ -z "$n_cur" ]; then
                warn "$label: table $table is gone (dropped by a migration?)"
            elif [ "$n_cur" -lt "$n_ref" ]; then
                if [[ "$table" =~ $VOLATILE_RE ]]; then
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
        ok "Live database: no rows lost in $(wc -l < "$BACKUP_DIR/row-counts.txt") tables ($(( $(wc -l < "$BACKUP_DIR/row-counts-after.txt") - $(wc -l < "$BACKUP_DIR/row-counts.txt") )) new tables)"

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
fi

# ---------------------------------------------------------------------------
echo
if [ "$FAILURES" -gt 0 ]; then
    if [ "$FRESH" = 1 ]; then
        echo "${C_ERR}${C_B}Install finished with $FAILURES failed check(s) and $WARNINGS warning(s).${C_0}"
        echo "  Logs: cd $ATGLANCE_DIR && docker compose logs"
    else
        echo "${C_ERR}${C_B}Update finished with $FAILURES failed check(s) and $WARNINGS warning(s).${C_0}"
        print_rollback
    fi
    exit 2
fi

if [ "$FRESH" = 1 ]; then
    # hostname -I is missing on some distros (Alpine, minimal images); fall back to the default route's source IP.
    host_ip=$(hostname -I 2>/dev/null | awk '{print $1}' || true)
    if [ -z "$host_ip" ] && command -v ip >/dev/null 2>&1; then
        host_ip=$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for (i = 1; i < NF; i++) if ($i == "src") {print $(i + 1); exit}}' || true)
    fi
    host_ip="${host_ip:-localhost}"
    summary="AtGlance CE is installed and healthy."
    [ "$WARNINGS" -gt 0 ] && summary="$summary ($WARNINGS warning(s) above)"
    echo "${C_B}$summary${C_0}"
    echo
    app_svc=$( [ -n "$APP_CONTAINER" ] && docker inspect -f '{{index .Config.Labels "com.docker.compose.service"}}' "$APP_CONTAINER" 2>/dev/null || true )
    while read -r svc port; do
        [ -n "$port" ] || continue
        [ "$svc" = "$app_svc" ] && note="  <- open this to run the setup wizard" || note=""
        printf '  %-12s http://%s:%s%s\n' "$svc" "$host_ip" "$port" "$note"
    done < <(model_get "$MODEL" P)
    echo "  Install dir:   $ATGLANCE_DIR (settings and database passwords in .env)"
    echo
    echo "  Open these ports in the firewall for users and servers."
    echo "  Back up the app key (it encrypts stored secrets):"
    echo "    docker exec ${APP_CONTAINER:-ce-atglance-app} grep APP_KEY /app/storage/.env"
    echo "  Update later: re-run this script."
    remove_clone
    exit 0
fi

summary="AtGlance is updated and healthy."
[ "$WARNINGS" -gt 0 ] && summary="$summary ($WARNINGS warning(s) above)"
echo "${C_B}$summary${C_0}"
for svc in $SERVICES; do
    [ "${OLD_ID[$svc]:-}" = "${NEW_ID[$svc]}" ] || echo "  $svc  $(short "${OLD_ID[$svc]:-none}") -> $(short "${NEW_ID[$svc]}")"
done
echo "  Backup:   $BACKUP_DIR"
echo "  Rollback: $BACKUP_DIR/ROLLBACK.txt (previous images are tagged :$ROLLBACK_TAG)"

remove_clone
