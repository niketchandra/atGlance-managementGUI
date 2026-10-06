# Installing and updating AtGlance Community Edition

AtGlance CE runs as Docker containers. This guide covers the first install and every later update.

| I want to | Linux | Windows | Container service |
|---|---|---|---|
| Install | [Part A, Step 1](#step-1-run-the-installer) | [Deploy on Windows](#deploy-on-windows-powershell) | [Part B](#part-b-deploy-on-a-container-service) |
| Update to the newest release | [Updating](#updating) | [Update on Windows](#update-on-windows) | [Upgrade on ECS](#upgrade-on-ecs) |
| Update a manual (git clone) install | [Manual install](#manual-install-clone-the-repository-and-run-docker-compose) | [Manual install on Windows](#manual-install-on-windows-clone-the-repository-and-run-docker-compose) | |

The installer and update scripts are in the [`scripts/`](scripts/) folder of this repository.

Choose one of two ways to deploy:

- **[Part A: on a VM](#part-a-deploy-on-a-vm)**: a Linux server (EC2, Azure VM, a VPS, bare metal). One command installs everything, including MySQL and Redis. Use this for most installs.
- **[Part B: on a container service](#part-b-deploy-on-a-container-service)**: AWS ECS (Fargate or EC2), Kubernetes, Azure Container Apps and similar platforms. You use managed MySQL and Redis and run the AtGlance images as services.

Both ways use the same images from Docker Hub. After either part, finish with [After the install](#after-the-install).

## What you deploy

| Component | Image | Runs | Notes |
|---|---|---|---|
| App (web console + API) | `atglance/ce-atglance-app` | default command | Port 8000, health check `GET /up` |
| Worker | `atglance/ce-atglance-app` | `php artisan queue:work redis --tries=5 --backoff=30,60,120,300,600 --timeout=60 --sleep=3 --max-jobs=1000` | No port |
| Scheduler | `atglance/ce-atglance-app` | `php artisan schedule:work` | No port. Run exactly one. |
| Database | `mysql:8.0` | | Or a managed MySQL 8.0 |
| Cache and queue | `redis:7-alpine` | | Or a managed Redis 7 |
| API gateway | `atglance/ce-atglance-gateway` | default command | Kong, port 8002. Forwards to `http://app:8000`. The `atglance` CLI talks to it. |
| MCP server | `atglance/ce-atglance-mcp` | default command | No published port; reached at `<host>:8002/mcp` through the gateway. Off by default (Site Setting > Plugins > MCP Server). |
| Controller | `nginx:1.27-alpine` | config in `docker-compose.yml` | Container `ce-atglance-controller`. Lets the console start and stop the MCP server; mounts the Docker socket but allows only start/stop/status of `ce-atglance-mcp`. No port. |

Images are published for `linux/amd64` only. The installers always deploy the `latest` tag; release tags such as `2.0.0` also exist for manual deployments.

> [!IMPORTANT]
> The `atglance` CLI sends its API calls to the Kong gateway on port **8002** of the management host, and logs out through the app on port **8000** of the same host. Both ports must be reachable from your servers on the same host name. Set the CLI's management URL to `http://<host>:8002` (or `https://<host>:8002` behind TLS).

---

## Part A: Deploy on a VM

Linux server: follow Steps 1 to 3 below. Windows machine: jump to [Deploy on Windows (PowerShell)](#deploy-on-windows-powershell).

### Requirements

| | Minimum |
|---|---|
| OS | Linux: Ubuntu 20.04+, Debian 11+, RHEL / Rocky / Alma 8+, Amazon Linux 2023, Fedora |
| CPU | 2 vCPU, amd64 (arm64 images are not published yet) |
| Memory | 2 GB (1 GB works for small installs) |
| Disk | 5 GB free, more for stored configuration files |
| Access | root or sudo, outbound internet to Docker Hub and GitHub |
| Ports | 8000 (web console) open to users and servers; 8002 (gateway: atglance CLI and the `/mcp` MCP server) open to servers and AI clients |

You do not need Docker installed. The installer installs it.

### Step 1: Run the installer

One command downloads the installer and deploys everything:

```bash
curl -fsSL https://app.atglance.live/console/atglance-installer.sh | sudo bash
```

Or download the file first, read it, then run it:

```bash
curl -fsSL https://app.atglance.live/console/atglance-installer.sh -o atglance-installer.sh
sudo bash atglance-installer.sh
```

Or run it from a clone of this repository. The scripts are in the `scripts/` folder. The clone is removed after a successful install (see step 6 below):

```bash
git clone https://github.com/niketchandra/atGlance-managementGUI.git
sudo bash atGlance-managementGUI/scripts/atglance-installer.sh
```

The installer:

1. Checks the server: root, Linux, CPU architecture, free disk, memory and free ports.
2. Installs Docker and the Docker Compose plugin if they are missing, and starts Docker.
3. Creates `/opt/atglance` with `docker-compose.yml` and `.env`. The `.env` file gets random database passwords.
4. Pulls the images, starts the containers and waits until the app is healthy.
5. Prints the URL of the setup wizard.
6. If you ran it from a `git clone` of this repository, removes the clone. Everything AtGlance needs is in the install directory and the Docker volumes. The clone is kept when it has uncommitted, untracked or unpushed work, when the install directory is inside it, or with `--keep-clone` (`ATGLANCE_KEEP_CLONE=1`). The updater (`atglance_update.sh`) does the same after a successful update.

To change the defaults, pass options after `bash -s --`:

```bash
curl -fsSL https://app.atglance.live/console/atglance-installer.sh \
  | sudo bash -s -- --dir /opt/atglance
```

| Option | Default | What it does |
|---|---|---|
| `--port` | `8000` | Port for the web console. Keep 8000: the CLI expects it. |
| `--dir` | `/opt/atglance` | Install directory |
| `--registry` | `atglance` | Image registry or namespace (Docker Hub by default) |
| `--keep-clone` | off | Keep the git clone the installer ran from |

The installer always deploys the newest release (`latest` images). It needs Docker Compose 2.23 or newer and upgrades an older Compose plugin itself.

### Step 2: Check the containers

```bash
cd /opt/atglance
docker compose ps
```

You see these containers, all `Up` and the app `healthy`:

```
ce-atglance-app        ce-atglance-worker     ce-atglance-scheduler
ce-atglance-db         ce-atglance-redis      ce-atglance-gateway
ce-atglance-mcp        ce-atglance-controller
```

### Step 3: Open the firewall and add HTTPS

- Allow inbound TCP 8000 and 8002 in the security group or firewall. The `atglance` CLI on your servers needs both.
- Do not open 3306 or 6379. MySQL and Redis are reachable only inside the Docker network.
- For a custom domain and HTTPS, use the built-in proxy after install: see [Custom domain and HTTPS](docs/custom-domain.md). The install folder also gets `docker-compose.domain.yml`, an opt-in override that the install itself does not use. Any other reverse proxy (nginx, Traefik, a cloud load balancer) in front of port 8000 works too.

Continue with [After the install](#after-the-install).

### Manual install: clone the repository and run Docker Compose

Use this when you cannot run the installer. You need Docker Engine with the Compose plugin and `git`.

```bash
# 1. Clone the repository
sudo git clone https://github.com/niketchandra/atGlance-managementGUI.git /opt/atglance
cd /opt/atglance

# 2. Create the settings file and set two long random passwords
sudo cp .env.example .env
sudo nano .env        # set DB_PASSWORD and DB_ROOT_PASSWORD

# 3. Pull the images and start everything
sudo docker compose pull
sudo docker compose up -d

# 4. Check: eight containers Up, app healthy
sudo docker compose ps
```

Then open `http://<server-ip>:8000` for the setup wizard.

Here the clone is the install directory, so the scripts never remove it. To upgrade a manual install:

```bash
cd /opt/atglance
sudo git pull
sudo docker compose pull
sudo docker compose up -d --remove-orphans
```

### Updating

`scripts/atglance_update.sh` updates an existing Linux install to the newest release (`latest`) with checks before and after. Your data, passwords and settings are kept.

1. Run the updater as root. Pick one way:

   ```bash
   # One command, no questions
   curl -fsSL https://raw.githubusercontent.com/niketchandra/atGlance-managementGUI/main/scripts/atglance_update.sh | sudo bash -s -- --yes

   # Or download, read, then run (asks for confirmation)
   curl -fsSL https://raw.githubusercontent.com/niketchandra/atGlance-managementGUI/main/scripts/atglance_update.sh -o atglance_update.sh
   sudo bash atglance_update.sh

   # Or from a clone of this repository (the clone is removed after a successful update)
   git clone https://github.com/niketchandra/atGlance-managementGUI.git
   sudo bash atGlance-managementGUI/scripts/atglance_update.sh
   ```

   Add `--dir DIR` if AtGlance is not in `/opt/atglance`.
2. Wait for the summary. "AtGlance is updated and healthy" (exit code `0`) means you are done.
3. If it says a check failed (exit code `2`), read the lines marked `✗`, then follow `ROLLBACK.txt` in the backup folder it prints.

What the updater does:

| Step | What it does |
|---|---|
| 1. Database backup | `mysqldump` (consistent, no lock) to `<dir>/backups/update-<time>/atglance.sql.gz`, checked for corruption and completeness, plus exact row counts of every table, the app's `.env` (APP_KEY) and the compose files. The last 5 backups are kept (`--keep N`). |
| 2. Current checksums | Image ID and registry digest of the running app, gateway and MCP containers. They are tagged `:rollback-<time>`. |
| 3. New checksums | Pulls `latest` and compares. If nothing changed: "already up to date" and exit (redeploy anyway with `--force`). |
| 4. Update | Refreshes `docker-compose.yml`, keeps a custom domain / HTTPS proxy, recreates all containers. Migrations run when the app starts. |
| 5. Health checks | Every container running and healthy and on the new image; `GET /up` 200; gateway answering; MCP (if on); no pending migrations; queue worker process; scheduler config; no restart loop; new errors in the app log; disk space. |
| 6. Database check | Live database against the backup: every table present, no rows lost (cache, queue, session and log tables may change on their own). The backup is restored into a scratch database (`atglance_update_verify`, dropped afterwards) to prove it is complete (`--skip-db-verify` skips this). |

Exit code `0` = updated (or already up to date), `1` = stopped before changing anything, `2` = updated but a check failed.
Each backup folder has a `ROLLBACK.txt` with the exact commands: start the previous images again
(`ATGLANCE_VERSION=rollback-<time>`), and only if data is wrong, restore the database dump.

Options: `--dir DIR` (default `/opt/atglance`), `--force`, `--yes` (no confirmation prompt), `--skip-db-verify`, `--keep N`, `--keep-clone`.

On Windows, use `atglance_update.ps1`: see [Update on Windows](#update-on-windows).

### Upgrade, manage, uninstall

| Task | Command |
|---|---|
| Upgrade (recommended) | `curl -fsSL https://raw.githubusercontent.com/niketchandra/atGlance-managementGUI/main/scripts/atglance_update.sh \| sudo bash -s -- --yes` (see [Updating](#updating)) |
| Upgrade (quick) | Run the installer again. It keeps `.env`, passwords and data. Database migrations run when the app starts. It takes no backup and runs no checks. |
| Upgrade a manual (clone) install | `cd /opt/atglance && sudo git pull && sudo docker compose pull && sudo docker compose up -d --remove-orphans` |
| Status | `cd /opt/atglance && docker compose ps` |
| Logs | `docker compose logs -f app` (or `worker`, `scheduler`) |
| Restart | `docker compose restart` |
| Stop | `docker compose down` (data is kept in volumes) |
| Uninstall and delete all data | `docker compose down -v`. **This deletes the database and all stored files.** |

Data lives in three Docker volumes: `atglance_db-data` (MySQL), `atglance_app-storage` (app `.env`, `APP_KEY` and stored files) and `atglance_redis-data`.

### Deploy on Windows (PowerShell)

Use `atglance-installer.ps1` on Windows 10/11 or Windows Server. It does the same steps as `atglance-installer.sh`, with Docker Desktop in place of the Linux Docker engine.

#### Requirements

| | Minimum |
|---|---|
| OS | Windows 10/11 (64-bit) or Windows Server 2019+ |
| CPU | 2 vCPU, amd64 (arm64 images are not published yet) |
| Memory | 2 GB free for Docker (1 GB works for small installs) |
| Disk | 5 GB free, more for stored configuration files |
| Software | [Docker Desktop](https://www.docker.com/products/docker-desktop/) running in **Linux containers** mode (the default) |
| Access | An elevated PowerShell (Run as administrator), outbound internet to Docker Hub and GitHub |
| Ports | 8000 (web console) open to users and servers; 8002 (gateway: atglance CLI and the `/mcp` MCP server) open to servers and AI clients |

If Docker is missing, the script tries `winget install Docker.DockerDesktop`, then asks you to start Docker Desktop and run it again.

#### Step 1: Run the installer

Open PowerShell as administrator (Start menu, search PowerShell, right-click, **Run as administrator**). One command downloads the installer and deploys everything:

```powershell
irm https://app.atglance.live/console/atglance-installer.ps1 | iex
```

Or download the file first, then run it:

```powershell
irm https://app.atglance.live/console/atglance-installer.ps1 -OutFile atglance-installer.ps1
powershell -ExecutionPolicy Bypass -File .\atglance-installer.ps1
```

Or run it from a clone of this repository (needs Git for Windows). The clone is removed after a successful install (see step 6 below):

```powershell
git clone https://github.com/niketchandra/atGlance-managementGUI.git
powershell -ExecutionPolicy Bypass -File .\atGlance-managementGUI\scripts\atglance-installer.ps1
```

The installer:

1. Checks the machine: administrator rights, CPU architecture, free disk, memory and free ports.
2. Checks that Docker Desktop is running in Linux containers mode and that Docker Compose is available.
3. Creates `C:\ProgramData\AtGlance` with `docker-compose.yml` and `.env`. The `.env` file gets random database passwords and is limited to Administrators and SYSTEM.
4. Pulls `atglance/ce-atglance-app`, `-gateway` and `-mcp` at `latest`, starts the containers and waits until the app and gateway are healthy.
5. Prints the URL of the setup wizard.
6. If you ran it from a `git clone` of this repository, removes the clone. Everything AtGlance needs is in the install directory and the Docker volumes. The clone is kept when it has uncommitted, untracked or unpushed work, when the install directory is inside it, or with `-KeepClone` (`ATGLANCE_KEEP_CLONE=1`). The updater (`atglance_update.ps1`) does the same after a successful update.

#### If PowerShell blocks the script

If you see "running scripts is disabled on this system", the execution policy is too strict. Use one of these:

| Option | Command | Scope |
|---|---|---|
| Run once with a bypass (recommended) | `powershell -ExecutionPolicy Bypass -File .\atglance-installer.ps1` | That one run only |
| Allow it in this window | `Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass` then `.\atglance-installer.ps1` | Reverts when the window closes |
| Allow local scripts for your user | `Set-ExecutionPolicy -Scope CurrentUser -ExecutionPolicy RemoteSigned` | Permanent for your user |
| File downloaded in a browser is still blocked | `Unblock-File .\atglance-installer.ps1` | That file |

`Get-ExecutionPolicy -List` shows the current policy for each scope. A Group Policy set by your organization overrides these commands. In that case ask your administrator, or use the bypass option.

#### Options

Pass options to the script, or set the environment variable when you pipe it to `iex` (parameters cannot be passed that way):

```powershell
.\atglance-installer.ps1 -Dir D:\atglance
```

```powershell
$env:ATGLANCE_DIR = "D:\atglance"
irm https://app.atglance.live/console/atglance-installer.ps1 | iex
```

| Option | Environment variable | Default | What it does |
|---|---|---|---|
| `-Port` | `APP_PORT` | `8000` | Port for the web console. Keep 8000: the CLI expects it. |
| `-Dir` | `ATGLANCE_DIR` | `C:\ProgramData\AtGlance` | Install directory |
| `-Registry` | `ATGLANCE_REGISTRY` | `atglance` | Image registry or namespace |
| `-KeepClone` | `ATGLANCE_KEEP_CLONE=1` | off | Keep the git clone the installer ran from |

#### Manual install on Windows: clone the repository and run Docker Compose

Use this when you cannot run the installer. Needs Docker Desktop (Linux containers) and Git for Windows. In PowerShell as administrator:

```powershell
# 1. Clone the repository
git clone https://github.com/niketchandra/atGlance-managementGUI.git C:\ProgramData\AtGlance
cd C:\ProgramData\AtGlance

# 2. Create the settings file and set DB_PASSWORD and DB_ROOT_PASSWORD
Copy-Item .env.example .env
notepad .env

# 3. Pull the images and start everything
docker compose pull
docker compose up -d

# 4. Check: eight containers Up, app healthy
docker compose ps
```

Then open `http://localhost:8000` for the setup wizard. Here the clone is the install directory, so the scripts never remove it. To upgrade: `git pull`, `docker compose pull`, then `docker compose up -d --remove-orphans`.

#### Check and firewall

```powershell
cd C:\ProgramData\AtGlance
docker compose ps
```

You see the same eight containers as on Linux. Allow inbound TCP 8000 and 8002 in Windows Firewall. For a custom domain and HTTPS, see [Custom domain and HTTPS](docs/custom-domain.md).

#### Update on Windows

`scripts/atglance_update.ps1` runs the same steps as the [Linux updater](#updating): database backup, image checksums, update of all containers, health checks and a database check against the backup. Your data, passwords and settings are kept.

1. Open PowerShell as administrator.
2. Download and run the updater. Pick one way:

   ```powershell
   # Download, then run (asks for confirmation; add -Yes to skip it)
   irm https://raw.githubusercontent.com/niketchandra/atGlance-managementGUI/main/scripts/atglance_update.ps1 -OutFile atglance_update.ps1
   powershell -ExecutionPolicy Bypass -File .\atglance_update.ps1

   # Or from a clone of this repository (the clone is removed after a successful update)
   git clone https://github.com/niketchandra/atGlance-managementGUI.git
   powershell -ExecutionPolicy Bypass -File .\atGlance-managementGUI\scripts\atglance_update.ps1
   ```

   Add `-Dir DIR` (or `$env:ATGLANCE_DIR`) if AtGlance is not in `C:\ProgramData\AtGlance`.
3. Wait for the summary. "AtGlance is updated and healthy" (exit code `0`) means you are done.
4. If it says a check failed (exit code `2`), follow `ROLLBACK.txt` in the backup folder it prints. It holds the PowerShell rollback commands.

Options: `-Dir DIR`, `-Force` (redeploy even when up to date), `-Yes`, `-SkipDbVerify`, `-Keep N` (backups to keep, default 5), `-KeepClone`.

#### Manage

| Task | Command |
|---|---|
| Upgrade (recommended) | `scripts/atglance_update.ps1` (see [Update on Windows](#update-on-windows)) |
| Upgrade (quick) | Run the installer again. It keeps `.env`, passwords and data, but takes no backup and runs no checks. |
| Logs | `docker compose logs -f app` (or `worker`, `scheduler`) |
| Restart | `docker compose restart` |
| Stop | `docker compose down` (data is kept in volumes) |
| Uninstall and delete all data | `docker compose down -v`. **This deletes the database and all stored files.** |

Continue with [After the install](#after-the-install).

---

## Part B: Deploy on a container service

Use this for AWS ECS, Kubernetes (EKS, AKS, GKE), Azure Container Apps and similar. The steps below use **AWS ECS on Fargate** as the example. [Other platforms](#other-container-platforms) lists what changes.

### How it fits together

```
Users / atglance CLI ──HTTPS──▶ Load balancer ──▶ app service (port 8000)
                                                        │
                        worker service ─────────────────┤
                        scheduler service ──────────────┤
                                                        ▼
                         Managed MySQL 8.0   Managed Redis 7   Shared file system
                         (RDS)               (ElastiCache)     (EFS at /app/storage)
```

### Rules every platform must follow

1. **Shared storage.** Mount one shared, persistent file system at `/app/storage` in the app, worker and scheduler. The app keeps its `.env` and `APP_KEY` there (`/app/storage/.env`), plus uploaded configuration files. On first start the app container creates `.env` and a new `APP_KEY`. The worker and scheduler wait up to 4 minutes for that file.
2. **One scheduler.** Run exactly one scheduler task. Two schedulers run every scheduled backup twice.
3. **One app task during install.** Every app task runs database migrations when it starts. Keep the app at 1 task for the first install and for upgrades. Scale it out after the upgrade finishes. You can also set `ATGLANCE_AUTO_MIGRATE=false` on extra app tasks.
4. **Environment variables.** Set these on the app, worker and scheduler:

   | Variable | Value |
   |---|---|
   | `ATGLANCE_ROLE` | `app`, `worker` or `scheduler` |
   | `DB_CONNECTION` | `mysql` |
   | `DB_HOST` | MySQL host name |
   | `DB_PORT` | `3306` |
   | `DB_DATABASE` | `atglance` |
   | `DB_USERNAME` | `atglance` |
   | `DB_PASSWORD` | from a secret store |
   | `REDIS_HOST` | Redis host name |
   | `REDIS_PORT` | `6379` |
   | `CACHE_STORE` | `redis` |
   | `QUEUE_CONNECTION` | `redis` |

   Do not set `APP_KEY` or `APP_URL` as environment variables. The app keeps them in `/app/storage/.env`, and the setup wizard writes `APP_URL`. An environment variable would override the wizard.

5. **Health check.** HTTP `GET /up` on port 8000 of the app. Allow a start period of at least 60 seconds for the first migration.
6. **Gateway.** Run the gateway. The `atglance` CLI talks to it. The gateway image forwards to `http://app:8000`, so the app must be reachable by the host name `app` from the gateway, for example through ECS Service Connect or a Kubernetes Service named `app`.
7. **Ports on one host name.** Expose the app on port 8000 and the gateway on port 8002 of the same public host name, for example `atglance.example.com:8000` and `atglance.example.com:8002`. The CLI builds both addresses from one management URL.

### ECS on Fargate, step by step

#### Step 1: Create the network and the data services

1. Use a VPC with private subnets for the tasks and the databases, and public subnets for the load balancer.
2. Create security groups:
   - `atglance-alb`: inbound 443 and 80 from the internet; inbound 8000 and 8002 from your servers (or the internet).
   - `atglance-tasks`: inbound 8000 and 8002 from `atglance-alb`; inbound 8000 from itself (gateway to app).
   - `atglance-data`: inbound 3306, 6379 and 2049 (NFS) from `atglance-tasks`.
3. Create an **RDS for MySQL 8.0** instance in the private subnets with security group `atglance-data`. Create the database and user:

   ```sql
   CREATE DATABASE atglance CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'atglance'@'%' IDENTIFIED BY '<strong-password>';
   GRANT ALL PRIVILEGES ON atglance.* TO 'atglance'@'%';
   ```

4. Create an **ElastiCache for Redis 7** cluster (cluster mode disabled, no AUTH token) in the private subnets with security group `atglance-data`.
5. Create an **EFS file system** with mount targets in the private subnets and security group `atglance-data`. Create an access point with path `/atglance-storage`, POSIX user `0:0`, and root directory permissions `0:0` and mode `0775`.
6. Store the database password in **Secrets Manager** as `atglance/db-password`.

#### Step 2: Create the ECS cluster and roles

1. Create an ECS cluster, for example `atglance`, with a Service Connect namespace such as `atglance.local`.
2. Create a task execution role with `AmazonECSTaskExecutionRolePolicy` and `secretsmanager:GetSecretValue` on `atglance/db-password`.
3. Create a task role with EFS access (`elasticfilesystem:ClientMount`, `ClientWrite`) on the file system.

#### Step 3: Register the app task definition

Replace the values in `<...>`.

```json
{
  "family": "ce-atglance-app",
  "requiresCompatibilities": ["FARGATE"],
  "networkMode": "awsvpc",
  "cpu": "1024",
  "memory": "2048",
  "runtimePlatform": { "cpuArchitecture": "X86_64", "operatingSystemFamily": "LINUX" },
  "executionRoleArn": "<task-execution-role-arn>",
  "taskRoleArn": "<task-role-arn>",
  "volumes": [
    {
      "name": "app-storage",
      "efsVolumeConfiguration": {
        "fileSystemId": "<efs-id>",
        "transitEncryption": "ENABLED",
        "authorizationConfig": { "accessPointId": "<efs-access-point-id>", "iam": "ENABLED" }
      }
    }
  ],
  "containerDefinitions": [
    {
      "name": "app",
      "image": "atglance/ce-atglance-app:2.0.0",
      "essential": true,
      "portMappings": [{ "name": "app", "containerPort": 8000, "protocol": "tcp" }],
      "mountPoints": [{ "sourceVolume": "app-storage", "containerPath": "/app/storage" }],
      "environment": [
        { "name": "ATGLANCE_ROLE", "value": "app" },
        { "name": "DB_CONNECTION", "value": "mysql" },
        { "name": "DB_HOST", "value": "<rds-endpoint>" },
        { "name": "DB_PORT", "value": "3306" },
        { "name": "DB_DATABASE", "value": "atglance" },
        { "name": "DB_USERNAME", "value": "atglance" },
        { "name": "REDIS_HOST", "value": "<elasticache-primary-endpoint>" },
        { "name": "REDIS_PORT", "value": "6379" },
        { "name": "CACHE_STORE", "value": "redis" },
        { "name": "QUEUE_CONNECTION", "value": "redis" }
      ],
      "secrets": [
        { "name": "DB_PASSWORD", "valueFrom": "<secrets-manager-arn-of-atglance/db-password>" }
      ],
      "healthCheck": {
        "command": ["CMD-SHELL", "curl -fsS http://127.0.0.1:8000/up || exit 1"],
        "interval": 15, "timeout": 5, "retries": 5, "startPeriod": 90
      },
      "logConfiguration": {
        "logDriver": "awslogs",
        "options": {
          "awslogs-group": "/ecs/atglance",
          "awslogs-region": "<region>",
          "awslogs-stream-prefix": "app",
          "awslogs-create-group": "true"
        }
      }
    }
  ]
}
```

Graviton (arm64) tasks need arm64 images, which are not published yet; use x86_64 (`"cpuArchitecture": "X86_64"`).

#### Step 4: Register the worker and scheduler task definitions

Copy the app task definition twice and change only these fields:

| Field | Worker | Scheduler |
|---|---|---|
| `family` | `ce-atglance-worker` | `ce-atglance-scheduler` |
| container `name` | `worker` | `scheduler` |
| `cpu` / `memory` | `512` / `1024` | `256` / `512` |
| `ATGLANCE_ROLE` | `worker` | `scheduler` |
| `command` | `["php","artisan","queue:work","redis","--tries=5","--backoff=30,60,120,300,600","--timeout=60","--sleep=3","--max-jobs=1000"]` | `["php","artisan","schedule:work"]` |
| `portMappings` | remove | remove |
| `healthCheck` | remove | remove |
| `awslogs-stream-prefix` | `worker` | `scheduler` |

Keep the same EFS volume and mount point. All three must share `/app/storage`.

#### Step 5: Create the load balancer

1. Create an Application Load Balancer in the public subnets with security group `atglance-alb`.
2. Create two target groups, both type `IP` and protocol HTTP:
   - `atglance-app`: port 8000, health check path `/up`, success code `200`.
   - `atglance-gateway`: port 8002, health check path `/`, success codes `200-404`.
3. Add HTTPS listeners with an ACM certificate for your domain:

   | Listener | Forwards to | Used by |
   |---|---|---|
   | HTTPS 443 | `atglance-app` | Browsers (web console) |
   | HTTPS 8000 | `atglance-app` | `atglance` CLI logout |
   | HTTPS 8002 | `atglance-gateway` | `atglance` CLI API calls |

   Redirect HTTP 80 to 443.
4. Point your domain (Route 53 or other DNS) at the load balancer.
5. Add `ATGLANCE_TRUSTED_PROXIES=127.0.0.1,::1,<VPC CIDR>` to the app task definition, so the console trusts the load balancer's `X-Forwarded-*` headers. After the install, set the domain in Admin Settings > Plugins > Custom Domain & HTTPS, then on the Site tab with HTTPS "Handled by my platform". See [Custom domain and HTTPS](docs/custom-domain.md).

#### Step 6: Create the services in this order

1. **app**: task definition `ce-atglance-app`, desired count **1**, private subnets, security group `atglance-tasks`, attached to the target group on container port 8000, health check grace period 120 seconds. Turn on Service Connect as a client and server, port name `app`, discovery name `app`, so other tasks reach it at `app:8000`.
2. Wait until the app task is healthy. It has now created `/app/storage/.env` with the `APP_KEY` and run the migrations.
3. **worker**: task definition `ce-atglance-worker`, desired count 1 or more.
4. **scheduler**: task definition `ce-atglance-scheduler`, desired count **exactly 1**. Set maximum percent to 100 and minimum healthy percent to 0 so a deploy never runs two schedulers.
5. **gateway**: see Step 7.

#### Step 7: API gateway

1. Create a task definition `ce-atglance-gateway` with image `atglance/ce-atglance-gateway:2.0.0`, port 8002, 256 CPU / 512 MB. It needs no volume and no environment variables. Health check: `["CMD", "kong", "health"]`.
2. Create a service, desired count 1 or more, with Service Connect as a client, so `app` resolves to the app service. Attach it to the `atglance-gateway` target group on container port 8002.

Your servers set the CLI management URL to `https://<your-domain>:8002`.

Continue with [After the install](#after-the-install). After the install, set your public domain in Admin Settings > Plugins > Custom Domain & HTTPS.

#### Upgrade on ECS

The update scripts are for VM installs only. On a container service:

1. Take an RDS snapshot and back up the EFS file system (it holds `APP_KEY`).
2. Scale the app service to 1 task.
3. Register new revisions of all task definitions with the new image tag.
4. Update the app service. Wait until it is healthy. It runs the new migrations.
5. Update the worker, scheduler and gateway services.
6. Scale the app service back out if needed.

#### Back up on ECS

- **Database**: RDS automated backups and snapshots.
- **Files and `APP_KEY`**: AWS Backup for the EFS file system. The `APP_KEY` in `/app/storage/.env` encrypts stored secrets. If you lose it, the stored S3, SMTP and SSO passwords and API keys cannot be read.

### Other container platforms

The [rules above](#rules-every-platform-must-follow) apply everywhere. This table maps them to each platform.

| Need | Kubernetes (EKS, AKS, GKE) | Azure Container Apps | Google Cloud Run |
|---|---|---|---|
| MySQL 8.0 | RDS, Azure Database for MySQL, Cloud SQL, or a StatefulSet | Azure Database for MySQL Flexible Server | Cloud SQL for MySQL |
| Redis 7 | ElastiCache, Azure Cache for Redis, Memorystore, or a Deployment | Azure Cache for Redis | Memorystore |
| Shared `/app/storage` | `ReadWriteMany` PVC (EFS CSI, Azure Files, Filestore) | Azure Files volume mount | Filestore (NFS) volume mount |
| App | Deployment, 1 replica during install, Service named `app` on 8000, Ingress with TLS | Container app, external ingress on 8000, min replicas 1 | Service on port 8000, min instances 1, CPU always allocated |
| Worker | Deployment with the worker `command` | Container app, no ingress, min replicas 1 | Worker pool or service with CPU always allocated and min instances 1 |
| Scheduler | Deployment, `replicas: 1`, strategy `Recreate` | Container app, no ingress, min and max replicas 1 | Service with CPU always allocated, min and max instances 1 |
| Gateway | Deployment with the gateway image, Service on 8002, exposed on the same host name as the app | Container app, internal ingress on 8002. Put Azure Application Gateway in front so `<host>:8000` reaches the app and `<host>:8002` the gateway | Service on 8002. Put an external HTTPS load balancer in front so `<host>:8000` reaches the app and `<host>:8002` the gateway |
| Secrets | Kubernetes Secret for `DB_PASSWORD` | Container Apps secret | Secret Manager |
| Health check | `readinessProbe` and `livenessProbe` on `GET /up`, port 8000 | Health probe on `/up` | Startup probe on `/up` |

A minimal Kubernetes app Deployment:

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: app
spec:
  replicas: 1
  selector: { matchLabels: { app: ce-atglance-app } }
  template:
    metadata: { labels: { app: ce-atglance-app } }
    spec:
      containers:
        - name: app
          image: atglance/ce-atglance-app:2.0.0
          ports: [{ containerPort: 8000 }]
          env:
            - { name: ATGLANCE_ROLE, value: app }
            - { name: DB_CONNECTION, value: mysql }
            - { name: DB_HOST, value: <mysql-host> }
            - { name: DB_PORT, value: "3306" }
            - { name: DB_DATABASE, value: atglance }
            - { name: DB_USERNAME, value: atglance }
            - name: DB_PASSWORD
              valueFrom: { secretKeyRef: { name: atglance-db, key: password } }
            - { name: REDIS_HOST, value: <redis-host> }
            - { name: REDIS_PORT, value: "6379" }
            - { name: CACHE_STORE, value: redis }
            - { name: QUEUE_CONNECTION, value: redis }
          volumeMounts: [{ name: storage, mountPath: /app/storage }]
          readinessProbe:
            httpGet: { path: /up, port: 8000 }
            initialDelaySeconds: 30
      volumes:
        - name: storage
          persistentVolumeClaim: { claimName: atglance-storage }   # ReadWriteMany
---
apiVersion: v1
kind: Service
metadata:
  name: app
spec:
  selector: { app: ce-atglance-app }
  ports: [{ port: 8000, targetPort: 8000 }]
```

For the worker and scheduler, copy the Deployment, set `ATGLANCE_ROLE`, add the `command`, remove the ports and probe, and use `strategy: { type: Recreate }` for the scheduler. For the gateway, run `atglance/ce-atglance-gateway` with port 8002 and no volume, and expose it with a LoadBalancer Service or Ingress so `<host>:8000` reaches the app and `<host>:8002` reaches the gateway.

---

## After the install

### Run the setup wizard

Open the app URL in a browser: `http://<server-ip>:8000` on a VM, or `https://<your-domain>` behind a load balancer. The wizard asks for:

- the organization name
- your super admin account (email and password)
- the IP address
- a licence key, or "add later". A key that atglance.live shows as "In Use" is refused, even on a reinstall of the same server: deactivate it there first, or create a new key.

It ends on a summary page (`/install/info`). Save the details it shows: the page is only available until the first login. After that it redirects to the login page, and the stored super admin password is removed. Log in with the super admin account you created.

A custom domain and HTTPS are set up after the install: see [Custom domain and HTTPS](docs/custom-domain.md).

### Back up the app key

The app creates `APP_KEY` on first start and stores it in `/app/storage/.env`. It encrypts stored secrets, such as S3, SMTP and SSO passwords and API keys. Save a copy somewhere safe:

```bash
docker exec ce-atglance-app grep APP_KEY /app/storage/.env      # VM
```

On a container service, read it from the shared file system or with `aws ecs execute-command` / `kubectl exec`.

### Connect a server

1. In the console, open **Settings** and create an API key. It starts with `atgla-`.
2. On the Linux server, install the `atglance` CLI.
3. Run `sudo atglance --configure`. Enter the gateway address as the management URL, `http://<server-ip>:8002` on a VM or `https://<your-domain>:8002` behind a load balancer, and paste the key.
4. Run `atglance --validate`, then `sudo atglance --system-register`.

The gateway forwards `/<path>` to the app's `/api/<path>`, see [api-gateway.md](docs/api-gateway.md).

## Troubleshooting

| Symptom | Check |
|---|---|
| Installer or updater says Docker Compose is too old | Version 2.23 or newer is needed. The Linux installer upgrades the plugin; on Windows update Docker Desktop. |
| Updater ends with "a check failed" (exit code 2) | Read the lines marked `✗`. The update is applied; `ROLLBACK.txt` in the printed backup folder has the commands to go back. |
| Updater says "No AtGlance install in /opt/atglance" | Pass the install directory: `--dir DIR` (Linux) or `-Dir DIR` (Windows). |
| Installer says a port is in use | Another program uses 8000 or 8002. Stop it. The CLI expects these two ports. |
| CLI says `Connection refused` or times out | Port 8002 (or 8000 for logout) is closed between the server and the management host. Open it in the firewall or security group. |
| CLI gets 404 | The management URL points at the app (8000) or ends in `/api`. Use `http://<host>:8002`. |
| App never becomes healthy | `docker compose logs app`. `migrations failed` means the app cannot reach MySQL: check `DB_HOST`, `DB_PASSWORD` and the security group. |
| Worker or scheduler exits with `/app/storage/.env not found` | The app has not started yet, or the three do not share the same `/app/storage` volume. |
| HTTP 500 on every page after a restore or key change | `APP_KEY` changed. Restore the saved `/app/storage/.env`. |
| Scheduled backups run twice | More than one scheduler is running. Scale it to exactly 1. |
| Gateway returns 502 or `name resolution failed` | The gateway cannot resolve `app`. Check Service Connect, or that a Kubernetes Service named `app` exists. |
