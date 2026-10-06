#!/usr/bin/env bash
# AtGlance Community Edition installer.
#
#   curl -fsSL https://raw.githubusercontent.com/niketchandra/atGlance-managementGUI/main/scripts/atglance-installer.sh | sudo bash
#
# Options (pass after "bash -s --", or as environment variables):
#   --dir DIR          install directory              (ATGLANCE_DIR, default /opt/atglance)
#   --registry PREFIX  image registry/namespace       (ATGLANCE_REGISTRY, default atglance = Docker Hub)
#   --port PORT        web console port               (APP_PORT, default 8000)
#   --keep-clone       keep the git clone this script runs from (ATGLANCE_KEEP_CLONE=1);
#                      by default a clean clone is removed after a successful run
#
# The Kong API gateway always runs on port 8002 (GATEWAY_PORT). The atglance
# CLI talks to the gateway, so keep 8000 and 8002 unless you know you need
# other ports.
#
# Re-running the installer upgrades an existing install and keeps its data
# and passwords.
set -euo pipefail
# Note: no "grep -q" after a pipe: it exits early, the writer gets SIGPIPE and pipefail fails the check.

ATGLANCE_DIR="${ATGLANCE_DIR:-/opt/atglance}"
# Always the newest release: atglance/ce-atglance-app, -gateway and -mcp at "latest".
[ -n "${ATGLANCE_VERSION:-}" ] && [ "$ATGLANCE_VERSION" != latest ] && echo "  ! ATGLANCE_VERSION is no longer supported; installing latest." >&2
ATGLANCE_VERSION=latest
ATGLANCE_REGISTRY="${ATGLANCE_REGISTRY:-atglance}"
ATGLANCE_REF="${ATGLANCE_REF:-main}"
APP_PORT="${APP_PORT:-8000}"
GATEWAY_PORT="${GATEWAY_PORT:-8002}"
REPO_RAW="https://raw.githubusercontent.com/niketchandra/atGlance-managementGUI/${ATGLANCE_REF}"
# Local compose file instead of downloading one (testing a branch).
ATGLANCE_COMPOSE_FILE="${ATGLANCE_COMPOSE_FILE:-}"

MIN_DISK_GB=5
MIN_MEM_MB=1024
# The controller service uses "configs: content:", added in Docker Compose 2.23.
MIN_COMPOSE=2.23.0
# First start on a small host (MySQL init + migrations) can take a few minutes.
HEALTH_TIMEOUT_S=300

# Directory of this script when run from a file (empty when piped from curl); see remove_clone.
SCRIPT_DIR=""
if [ -n "${BASH_SOURCE[0]:-}" ] && [ -f "${BASH_SOURCE[0]}" ]; then
    SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)
fi
KEEP_CLONE="${ATGLANCE_KEEP_CLONE:-0}"

while [ $# -gt 0 ]; do
    case "$1" in
        --dir) ATGLANCE_DIR="$2"; shift 2 ;;
        --version) echo "  ! --version is no longer supported; installing latest." >&2; shift 2 ;;
        --registry) ATGLANCE_REGISTRY="$2"; shift 2 ;;
        --port) APP_PORT="$2"; shift 2 ;;
        --with-gateway) shift ;;  # accepted for older docs; the gateway always runs
        --keep-clone) KEEP_CLONE=1; shift ;;
        -h|--help) sed -n '2,18p' "$0" 2>/dev/null || true; exit 0 ;;
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
warn() { echo "  ${C_WARN}!${C_0} $*"; }
die()  { echo "  ${C_ERR}✗ $*${C_0}" >&2; exit 1; }

port_in_use() {
    if command -v ss >/dev/null 2>&1; then
        ss -ltnH "sport = :$1" 2>/dev/null | grep . >/dev/null
    elif command -v netstat >/dev/null 2>&1; then
        netstat -ltn 2>/dev/null | awk '{print $4}' | grep -E "[:.]$1\$" >/dev/null
    else
        return 1
    fi
}

# Our own containers hold the ports on an upgrade; that is fine.
port_owned_by_atglance() {
    command -v docker >/dev/null 2>&1 &&
        docker ps --format '{{.Names}} {{.Ports}}' 2>/dev/null | grep '^ce-atglance-' | grep ":$1->" >/dev/null
}

random_secret() {
    head -c 48 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 32
}

# version_ge 2.24.1 2.23.0 -> true
version_ge() {
    [ "$(printf '%s\n%s\n' "$2" "$1" | sort -V | head -n1)" = "$2" ]
}

set_env() {
    if grep -q "^$1=" .env; then sed -i "s|^$1=.*|$1=$2|" .env; else echo "$1=$2" >> .env; fi
}

# ---------------------------------------------------------------------------
# Clone clean-up. When this script runs from a git clone of the AtGlance repository
# (git clone ... && sudo ./scripts/atglance-installer.sh), the clone is not needed once AtGlance is running:
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
step "Checking prerequisites"

[ "$(id -u)" -eq 0 ] || die "Run as root: curl -fsSL $REPO_RAW/scripts/atglance-installer.sh | sudo bash"
[ "$(uname -s)" = "Linux" ] || die "Linux is required (found $(uname -s))."
ok "Running as root on Linux"

case "$(uname -m)" in
    x86_64|amd64) ARCH=amd64 ;;
    aarch64|arm64) ARCH=arm64 ;;
    *) die "Unsupported CPU architecture: $(uname -m). Use amd64 or arm64." ;;
esac
ok "Architecture $ARCH"

if [ -r /etc/os-release ]; then
    . /etc/os-release
    ok "OS: ${PRETTY_NAME:-$ID}"
fi

command -v curl >/dev/null 2>&1 || die "curl is required."
ok "curl found"

mkdir -p "$ATGLANCE_DIR"
disk_gb=$(df -Pk "$ATGLANCE_DIR" | awk 'NR==2 {print int($4/1024/1024)}')
[ "$disk_gb" -ge "$MIN_DISK_GB" ] || die "Need ${MIN_DISK_GB} GB free in $ATGLANCE_DIR, found ${disk_gb} GB."
ok "Disk: ${disk_gb} GB free"

mem_mb=$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo)
if [ "$mem_mb" -lt "$MIN_MEM_MB" ]; then
    warn "Memory: ${mem_mb} MB. ${MIN_MEM_MB} MB or more is recommended."
else
    ok "Memory: ${mem_mb} MB"
fi

ports="$APP_PORT $GATEWAY_PORT"
for p in $ports; do
    if port_in_use "$p" && ! port_owned_by_atglance "$p"; then
        die "Port $p is in use. Free it or pick another with --port."
    fi
done
ok "Ports free: $ports"
if [ "$APP_PORT" != "8000" ] || [ "$GATEWAY_PORT" != "8002" ]; then
    warn "The atglance CLI expects ports 8000 and 8002. Other ports need a proxy in front."
fi

# ---------------------------------------------------------------------------
step "Checking Docker"

if ! command -v docker >/dev/null 2>&1; then
    warn "Docker not found. Installing with get.docker.com"
    curl -fsSL https://get.docker.com | sh
fi

if command -v systemctl >/dev/null 2>&1 && [ -d /run/systemd/system ]; then
    systemctl enable --now docker >/dev/null 2>&1 || true
fi

docker info >/dev/null 2>&1 || die "Docker is installed but the daemon is not running."
ok "Docker $(docker version --format '{{.Server.Version}}')"

if ! docker compose version >/dev/null 2>&1; then
    warn "Docker Compose plugin not found. Installing"
    if command -v apt-get >/dev/null 2>&1; then
        apt-get update -qq && apt-get install -y -qq docker-compose-plugin
    elif command -v dnf >/dev/null 2>&1; then
        dnf install -y -q docker-compose-plugin
    elif command -v yum >/dev/null 2>&1; then
        yum install -y -q docker-compose-plugin
    fi
    docker compose version >/dev/null 2>&1 || die "Install the Docker Compose plugin, then re-run."
fi

compose_version=$(docker compose version --short 2>/dev/null | sed 's/^v//')
if ! version_ge "$compose_version" "$MIN_COMPOSE"; then
    warn "Docker Compose $compose_version is too old (need $MIN_COMPOSE or newer). Upgrading"
    if command -v apt-get >/dev/null 2>&1; then
        apt-get update -qq && apt-get install -y -qq --only-upgrade docker-compose-plugin || true
    elif command -v dnf >/dev/null 2>&1; then
        dnf upgrade -y -q docker-compose-plugin || true
    elif command -v yum >/dev/null 2>&1; then
        yum update -y -q docker-compose-plugin || true
    fi
    compose_version=$(docker compose version --short 2>/dev/null | sed 's/^v//')
    version_ge "$compose_version" "$MIN_COMPOSE" ||
        die "Docker Compose $compose_version is too old. Upgrade the docker-compose-plugin package to $MIN_COMPOSE or newer (Docker's own repository: https://docs.docker.com/engine/install/), then re-run."
fi
ok "Docker Compose $compose_version"

# The controller container talks to this socket to start and stop the MCP server.
docker_host=$(docker context inspect --format '{{.Endpoints.docker.Host}}' 2>/dev/null || true)
DOCKER_SOCKET=/var/run/docker.sock
case "$docker_host" in
    unix://*) [ -S "${docker_host#unix://}" ] && DOCKER_SOCKET="${docker_host#unix://}" ;;
esac
[ -S "$DOCKER_SOCKET" ] || warn "Docker socket $DOCKER_SOCKET not found: turning MCP on and off from the console will not work."

# ---------------------------------------------------------------------------
step "Preparing $ATGLANCE_DIR"

cd "$ATGLANCE_DIR"

if [ -n "$ATGLANCE_COMPOSE_FILE" ]; then
    cp "$ATGLANCE_COMPOSE_FILE" docker-compose.yml
else
    curl -fsSL "$REPO_RAW/docker-compose.yml" -o docker-compose.yml.new
    mv docker-compose.yml.new docker-compose.yml
fi
ok "docker-compose.yml ready"

# Opt-in override for custom domains (not used by the install).
if curl -fsSL "$REPO_RAW/docker-compose.domain.yml" -o docker-compose.domain.yml.new; then
    mv docker-compose.domain.yml.new docker-compose.domain.yml
else
    rm -f docker-compose.domain.yml.new
    warn "Could not download docker-compose.domain.yml (only needed for a custom domain later)."
fi

if [ -f .env ]; then
    ok "Keeping existing .env (passwords unchanged)"
    set_env ATGLANCE_VERSION "$ATGLANCE_VERSION"
    set_env ATGLANCE_REGISTRY "$ATGLANCE_REGISTRY"
    set_env APP_PORT "$APP_PORT"
    set_env GATEWAY_PORT "$GATEWAY_PORT"
else
    # A database from an earlier install keeps its old password; new random ones would lock the app out.
    if docker volume inspect atglance_db-data >/dev/null 2>&1; then
        die "Found an existing AtGlance database (Docker volume atglance_db-data) but no $ATGLANCE_DIR/.env with its passwords.
    Re-run with --dir pointing at the original install folder, or restore its .env here.
    To start over instead (deletes ALL AtGlance data): docker volume rm atglance_db-data atglance_app-storage atglance_redis-data"
    fi
    umask 077
    cat > .env <<EOF
# AtGlance CE settings, created by atglance-installer.sh on $(date -u +%Y-%m-%dT%H:%M:%SZ).
ATGLANCE_VERSION=$ATGLANCE_VERSION
ATGLANCE_REGISTRY=$ATGLANCE_REGISTRY
APP_PORT=$APP_PORT
GATEWAY_PORT=$GATEWAY_PORT
DB_PASSWORD=$(random_secret)
DB_ROOT_PASSWORD=$(random_secret)
EOF
    umask 022
    ok "Created .env with random database passwords"
fi
if [ "$DOCKER_SOCKET" != /var/run/docker.sock ]; then
    set_env DOCKER_SOCKET "$DOCKER_SOCKET"
    ok "Docker socket: $DOCKER_SOCKET"
fi

# An install that already serves a custom domain through the built-in proxy keeps it on upgrade.
COMPOSE=(docker compose -f docker-compose.yml)
if [ -f docker-compose.domain.yml ] &&
    docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' ce-atglance-app 2>/dev/null | grep -x 'ATGLANCE_PROXY=builtin' >/dev/null; then
    COMPOSE+=(-f docker-compose.domain.yml)
    ok "Keeping the custom domain and HTTPS proxy (ports 80 and 443)"
fi

# ---------------------------------------------------------------------------
step "Deploying AtGlance CE (latest)"

for image in app gateway mcp; do
    ref="$ATGLANCE_REGISTRY/ce-atglance-$image:$ATGLANCE_VERSION"
    if ! out=$(docker pull --platform "linux/$ARCH" "$ref" 2>&1); then
        echo "$out" | tail -n 3 >&2
        case "$out" in
            *"no matching manifest"*|*"does not match the specified platform"*)
                die "$ref has no linux/$ARCH build. Use an amd64 server, or a version published for $ARCH." ;;
            *"not found"*|*"manifest unknown"*)
                die "$ref does not exist. Check --registry (see https://hub.docker.com/r/$ATGLANCE_REGISTRY/ce-atglance-app/tags)." ;;
            *) die "Could not pull $ref. Check the internet connection and Docker Hub access." ;;
        esac
    fi
    ok "Pulled $ref"
done
"${COMPOSE[@]}" pull --quiet
"${COMPOSE[@]}" up -d --remove-orphans

wait_healthy() {
    local name="$1" status=starting
    printf "  Waiting for %s" "$name"
    for _ in $(seq 1 $((HEALTH_TIMEOUT_S / 3))); do
        status=$(docker inspect -f '{{.State.Health.Status}}' "$name" 2>/dev/null || echo starting)
        [ "$status" = "healthy" ] && break
        printf "."
        sleep 3
    done
    echo
    if [ "$status" != "healthy" ]; then
        docker logs --tail 30 "$name" 2>&1 | sed 's/^/    /' >&2
        die "$name did not become healthy within $((HEALTH_TIMEOUT_S / 60)) minutes (last lines above). Full logs: cd $ATGLANCE_DIR && docker compose logs"
    fi
}
wait_healthy ce-atglance-app
wait_healthy ce-atglance-gateway
ok "All containers running"

# ---------------------------------------------------------------------------
# hostname -I is missing on some distros (Alpine, minimal images); fall back to the default route's source IP.
host_ip=$(hostname -I 2>/dev/null | awk '{print $1}' || true)
if [ -z "$host_ip" ] && command -v ip >/dev/null 2>&1; then
    host_ip=$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for (i = 1; i < NF; i++) if ($i == "src") {print $(i + 1); exit}}' || true)
fi
host_ip="${host_ip:-localhost}"

echo
echo "${C_B}AtGlance CE is running.${C_0}"
echo
echo "  Setup wizard:  http://$host_ip:$APP_PORT"
echo "  API gateway:   http://$host_ip:$GATEWAY_PORT"
echo "  MCP server:    off by default. Turn on: Site Setting > Plugins > MCP Server (then http://$host_ip:$GATEWAY_PORT/mcp)"
echo "  CLI setup:     atglance --configure   (management URL: http://$host_ip:$GATEWAY_PORT)"
echo "  Install dir:   $ATGLANCE_DIR"
echo
echo "  Open ports $APP_PORT and $GATEWAY_PORT in the firewall for users and servers."
echo
echo "  Manage:  cd $ATGLANCE_DIR && docker compose ps | logs -f | restart | down"
echo "  Upgrade: re-run this installer"
echo
echo "  Back up the app key. It encrypts stored secrets and lives in the"
echo "  atglance_app-storage volume, file .env:"
echo "    docker exec ce-atglance-app grep APP_KEY /app/storage/.env"

remove_clone
