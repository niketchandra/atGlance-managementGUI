#!/usr/bin/env bash
# AtGlance Community Edition installer.
#
#   curl -fsSL https://raw.githubusercontent.com/niketchandra/atGlance-managementGUI/main/scripts/atglance-installer.sh | sudo bash
#
# Prepares the host (root, CPU, memory, Docker, Docker Compose), then runs atglance_update.sh,
# which deploys the newest release from docker-compose.yml: a new console, or an update of an
# existing one (data and passwords are kept). Neither script changes between releases.
#
# Options (pass after "bash -s --", or as environment variables):
#   --dir DIR          install directory              (ATGLANCE_DIR, default /opt/atglance)
#   --registry PREFIX  image registry/namespace       (ATGLANCE_REGISTRY, default atglance = Docker Hub)
#   --port PORT        web console port               (APP_PORT, default 8000)
#   --keep-clone       keep the git clone this script runs from (ATGLANCE_KEEP_CLONE=1);
#                      by default a clean clone is removed after a successful run
#
# The Kong API gateway runs on port 8002 (GATEWAY_PORT). The atglance CLI talks to the
# gateway, so keep 8000 and 8002 unless you know you need other ports.
set -euo pipefail
# Note: no "grep -q" after a pipe: it exits early, the writer gets SIGPIPE and pipefail fails the check.

# Empty values are left to the updater: the install's current value, else the compose default.
ATGLANCE_DIR="${ATGLANCE_DIR:-}"
[ -n "${ATGLANCE_VERSION:-}" ] && [ "$ATGLANCE_VERSION" != latest ] && echo "  ! ATGLANCE_VERSION is no longer supported; installing latest." >&2
ATGLANCE_REGISTRY="${ATGLANCE_REGISTRY:-}"
ATGLANCE_REF="${ATGLANCE_REF:-main}"
APP_PORT="${APP_PORT:-}"
REPO_RAW="https://raw.githubusercontent.com/niketchandra/atGlance-managementGUI/${ATGLANCE_REF}"
# Local compose file instead of downloading one (testing a branch).
ATGLANCE_COMPOSE_FILE="${ATGLANCE_COMPOSE_FILE:-}"

MIN_MEM_MB=1024
# The controller service uses "configs: content:", added in Docker Compose 2.23.
MIN_COMPOSE=2.23.0

# Directory of this script when run from a file (empty when piped from curl).
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

# version_ge 2.24.1 2.23.0 -> true
version_ge() {
    [ "$(printf '%s\n%s\n' "$2" "$1" | sort -V | head -n1)" = "$2" ]
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

mem_mb=$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo)
if [ "$mem_mb" -lt "$MIN_MEM_MB" ]; then
    warn "Memory: ${mem_mb} MB. ${MIN_MEM_MB} MB or more is recommended."
else
    ok "Memory: ${mem_mb} MB"
fi

if [ -n "$APP_PORT" ] && [ "$APP_PORT" != "8000" ]; then
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
# Deploying is the updater's job: it reads everything from docker-compose.yml, so neither
# script changes between releases.
step "Deploying AtGlance CE (latest) with atglance_update.sh"

if [ -n "$SCRIPT_DIR" ] && [ -f "$SCRIPT_DIR/atglance_update.sh" ]; then
    UPDATER="$SCRIPT_DIR/atglance_update.sh"
else
    UPDATER_DIR=$(mktemp -d)
    curl -fsSL "$REPO_RAW/scripts/atglance_update.sh" -o "$UPDATER_DIR/atglance_update.sh" ||
        die "Could not download $REPO_RAW/scripts/atglance_update.sh. Check the internet connection."
    UPDATER="$UPDATER_DIR/atglance_update.sh"
fi
ok "Updater: $UPDATER"

# Installer options become the settings the updater reads.
if [ -n "$ATGLANCE_DIR" ]; then export ATGLANCE_DIR; fi
if [ -n "$ATGLANCE_REGISTRY" ]; then export ATGLANCE_REGISTRY; fi
if [ -n "$APP_PORT" ]; then export APP_PORT; fi
if [ "$DOCKER_SOCKET" != /var/run/docker.sock ]; then export DOCKER_SOCKET; ok "Docker socket: $DOCKER_SOCKET"; fi
export ATGLANCE_REF ATGLANCE_COMPOSE_FILE ATGLANCE_KEEP_CLONE="$KEEP_CLONE" ATGLANCE_YES=1
# The updater removes a clean git clone this script runs from (it is the updater's own clone).
exec bash "$UPDATER"
