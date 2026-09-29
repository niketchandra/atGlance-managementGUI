#!/usr/bin/env bash
# AtGlance Community Edition installer.
#
#   curl -fsSL https://raw.githubusercontent.com/niketchandra/atGlance-managementGUI/main/install.sh | sudo bash
#
# Options (pass after "bash -s --", or as environment variables):
#   --dir DIR          install directory              (ATGLANCE_DIR, default /opt/atglance)
#   --version TAG      image tag                      (ATGLANCE_VERSION, default latest)
#   --port PORT        console / API port             (APP_PORT, default 8000)
#   --with-gateway     also run the Kong API gateway  (ATGLANCE_GATEWAY=1, port 8002)
#
# Re-running the installer upgrades an existing install and keeps its data
# and passwords.
set -euo pipefail

ATGLANCE_DIR="${ATGLANCE_DIR:-/opt/atglance}"
ATGLANCE_VERSION="${ATGLANCE_VERSION:-latest}"
ATGLANCE_REF="${ATGLANCE_REF:-main}"
ATGLANCE_GATEWAY="${ATGLANCE_GATEWAY:-0}"
APP_PORT="${APP_PORT:-8000}"
GATEWAY_PORT="${GATEWAY_PORT:-8002}"
REPO_RAW="https://raw.githubusercontent.com/niketchandra/atGlance-managementGUI/${ATGLANCE_REF}"
# Local compose file instead of downloading one (testing a branch).
ATGLANCE_COMPOSE_FILE="${ATGLANCE_COMPOSE_FILE:-}"

MIN_DISK_GB=5
MIN_MEM_MB=1024

while [ $# -gt 0 ]; do
    case "$1" in
        --dir) ATGLANCE_DIR="$2"; shift 2 ;;
        --version) ATGLANCE_VERSION="$2"; shift 2 ;;
        --port) APP_PORT="$2"; shift 2 ;;
        --with-gateway) ATGLANCE_GATEWAY=1; shift ;;
        -h|--help) sed -n '2,14p' "$0" 2>/dev/null || true; exit 0 ;;
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
        ss -ltnH "sport = :$1" 2>/dev/null | grep -q .
    elif command -v netstat >/dev/null 2>&1; then
        netstat -ltn 2>/dev/null | awk '{print $4}' | grep -Eq "[:.]$1\$"
    else
        return 1
    fi
}

# Our own containers hold the ports on an upgrade; that is fine.
port_owned_by_atglance() {
    command -v docker >/dev/null 2>&1 &&
        docker ps --format '{{.Names}} {{.Ports}}' 2>/dev/null | grep '^ce-atglance-' | grep -q ":$1->"
}

random_secret() {
    head -c 48 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 32
}

# ---------------------------------------------------------------------------
step "Checking prerequisites"

[ "$(id -u)" -eq 0 ] || die "Run as root: curl -fsSL $REPO_RAW/install.sh | sudo bash"
[ "$(uname -s)" = "Linux" ] || die "Linux is required (found $(uname -s))."
ok "Running as root on Linux"

case "$(uname -m)" in
    x86_64|amd64) ok "Architecture amd64" ;;
    aarch64|arm64) ok "Architecture arm64" ;;
    *) die "Unsupported CPU architecture: $(uname -m). Use amd64 or arm64." ;;
esac

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

ports="$APP_PORT"
[ "$ATGLANCE_GATEWAY" = "1" ] && ports="$ports $GATEWAY_PORT"
for p in $ports; do
    if port_in_use "$p" && ! port_owned_by_atglance "$p"; then
        die "Port $p is in use. Free it or pick another with --port."
    fi
done
ok "Ports free: $ports"

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
ok "Docker Compose $(docker compose version --short)"

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

if [ -f .env ]; then
    ok "Keeping existing .env (passwords unchanged)"
    set_env() {
        if grep -q "^$1=" .env; then sed -i "s|^$1=.*|$1=$2|" .env; else echo "$1=$2" >> .env; fi
    }
    set_env ATGLANCE_VERSION "$ATGLANCE_VERSION"
    set_env APP_PORT "$APP_PORT"
    set_env GATEWAY_PORT "$GATEWAY_PORT"
else
    umask 077
    cat > .env <<EOF
# AtGlance CE settings, created by install.sh on $(date -u +%Y-%m-%dT%H:%M:%SZ).
ATGLANCE_VERSION=$ATGLANCE_VERSION
APP_PORT=$APP_PORT
GATEWAY_PORT=$GATEWAY_PORT
DB_PASSWORD=$(random_secret)
DB_ROOT_PASSWORD=$(random_secret)
EOF
    umask 022
    ok "Created .env with random database passwords"
fi

profiles=()
[ "$ATGLANCE_GATEWAY" = "1" ] && profiles=(--profile gateway)

# ---------------------------------------------------------------------------
step "Deploying AtGlance CE ($ATGLANCE_VERSION)"

docker compose "${profiles[@]}" pull
docker compose "${profiles[@]}" up -d --remove-orphans

printf "  Waiting for the console to start"
for _ in $(seq 1 90); do
    status=$(docker inspect -f '{{.State.Health.Status}}' ce-atglance-app 2>/dev/null || echo starting)
    [ "$status" = "healthy" ] && break
    printf "."
    sleep 2
done
echo
[ "$status" = "healthy" ] || die "The console did not become healthy. Check: docker compose -f $ATGLANCE_DIR/docker-compose.yml logs app"
ok "All containers running"

# ---------------------------------------------------------------------------
host_ip=$(hostname -I 2>/dev/null | awk '{print $1}')
host_ip="${host_ip:-localhost}"

echo
echo "${C_B}AtGlance CE is running.${C_0}"
echo
echo "  Setup wizard:  http://$host_ip:$APP_PORT"
[ "$ATGLANCE_GATEWAY" = "1" ] && echo "  API gateway:   http://$host_ip:$GATEWAY_PORT"
echo "  Install dir:   $ATGLANCE_DIR"
echo
echo "  Manage:  cd $ATGLANCE_DIR && docker compose ps | logs -f | restart | down"
echo "  Upgrade: re-run this installer"
echo
echo "  Back up the app key. It encrypts stored secrets and lives in the"
echo "  atglance_app-storage volume, file .env:"
echo "    docker exec ce-atglance-app grep APP_KEY /app/storage/.env"
