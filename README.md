# AtGlance Management Console

AtGlance keeps copies of the configuration files on your Linux servers and tells you what changed and when.

This repository is the **server side** of AtGlance:

- **API** for the `atglance` CLI that runs on each server. The CLI registers the server and uploads its service configuration files (nginx, ssh, and so on) as versioned backups.
- **Web console** for people:
  - Admins manage users, workspaces, API keys, S3 storage, backups and restore, notifications, and branding.
  - Users browse their servers and every version of their configuration files.

```
Linux servers (atglance CLI) ──HTTPS──▶ API ─┐
                                              ├─ Laravel app ── MySQL + Redis
Browser (admins, users) ─────────────▶ Web ──┘        │
                                                       └── S3 (optional: files, backups)
```

## What you get

- **Configuration backups**: every upload is kept as a version, per server and service.
- **Workspaces and roles**: super admin, admins (workspace managers), and users.
- **Storage**: local disk by default; S3 optional, with one-click migration between the two.
- **Scheduled backups and restore**: of configuration files and of the whole portal, to S3. See [BACKUP.md](BACKUP.md).
- **Notifications**: Email, Microsoft Teams, Slack, n8n, Telegram, webhooks and SMS, set up per workspace. See [NOTIFICATIONS.md](NOTIFICATIONS.md).
- **White-label**: your organization's name and logo, plus About, Features, FAQ, Support and Contact pages.
- **Resilience**: if MySQL goes down, writes are queued in Redis and replayed when it comes back.

## Installation Steps

> [!IMPORTANT]
> **Choose how to deploy.** Both ways use the same images from Docker Hub. Full steps: **[INSTALLATION.md](INSTALLATION.md)**.
>
> | | **Part A: VM** | **Part B: Container service** |
> |---|---|---|
> | Where | Any Linux server: EC2, Azure VM, VPS, bare metal | AWS ECS (Fargate/EC2), Kubernetes, Azure Container Apps, Cloud Run |
> | MySQL and Redis | Included, as containers | Managed services (RDS, ElastiCache, and so on) |
> | Effort | One command | Task definitions or manifests, load balancer, shared storage |
> | Guide | [Part A](INSTALLATION.md#part-a-deploy-on-a-vm) | [Part B](INSTALLATION.md#part-b-deploy-on-a-container-service) |

### Part A: Deploy on a VM

Run one command on a Linux server (amd64 or arm64). Docker is installed for you if it is missing.

```bash
curl -fsSL https://raw.githubusercontent.com/niketchandra/atGlance-managementGUI/main/install.sh | sudo bash
```

1. The installer checks the server (root, OS, CPU, disk, memory, ports) and installs Docker and Docker Compose if needed.
2. It creates `/opt/atglance` with random database passwords, pulls the images and starts the containers.
3. Open the printed URL, `http://<server-ip>:8000`, and complete the setup wizard.

Add the Kong API gateway or pin a version with `| sudo bash -s -- --with-gateway --version 1.2.1`. To upgrade, run the installer again. It keeps your data and passwords.

### Part B: Deploy on ECS or another container service

Run the images as separate services next to managed MySQL 8.0 and Redis 7:

| Service | Image | Command | Count |
|---|---|---|---|
| app | `atglance/ce-atglance-app` | default (port 8000, health `GET /up`) | 1 during install, then scale |
| worker | `atglance/ce-atglance-app` | `php artisan queue:work redis --tries=5 --backoff=30,60,120,300,600 --timeout=60` | 1 or more |
| scheduler | `atglance/ce-atglance-app` | `php artisan schedule:work` | exactly 1 |
| gateway (optional) | `atglance/ce-atglance-gateway` | default (port 8002) | 1 or more |

1. Create MySQL (database and user `atglance`), Redis and a shared file system (EFS, Azure Files, a `ReadWriteMany` volume).
2. Mount the shared file system at `/app/storage` in the app, worker and scheduler. It holds the app key and stored files.
3. Set `ATGLANCE_ROLE`, `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `REDIS_HOST`, `CACHE_STORE=redis` and `QUEUE_CONNECTION=redis` on all three.
4. Start the app first, behind a load balancer with HTTPS. When it is healthy, start the worker and the scheduler.
5. Open `https://<your-domain>` and complete the setup wizard.

[INSTALLATION.md](INSTALLATION.md#ecs-on-fargate-step-by-step) has a full ECS Fargate walkthrough with task definitions, plus Kubernetes, Azure Container Apps and Cloud Run notes.

### After installing

- **Back up the app key.** It encrypts stored secrets (S3, SMTP and SSO passwords, API keys). On a VM: `docker exec ce-atglance-app grep APP_KEY /app/storage/.env`.
- **Connect a server.** In the console, open **Settings** and create an API key (it starts with `atgla-`). Point the `atglance` CLI at `https://<your-domain>/api` with that key. The API is described in [API.md](API.md).

### Containers

| Container | Image | What it does |
|---|---|---|
| `ce-atglance-app` | `atglance/ce-atglance-app` | Web console and API. Runs database migrations on start. |
| `ce-atglance-worker` | `atglance/ce-atglance-app` | Queue worker. Replays writes that were queued while MySQL was down. |
| `ce-atglance-scheduler` | `atglance/ce-atglance-app` | Laravel scheduler. Runs scheduled S3 backups, see [BACKUP.md](BACKUP.md). |
| `ce-atglance-db` | `mysql:8.0` | Database (VM install) |
| `ce-atglance-redis` | `redis:7-alpine` | Cache, queue and circuit-breaker state (VM install) |
| `ce-atglance-gateway` | `atglance/ce-atglance-gateway` | Kong with the AtGlance routes built in. Optional. |
| `ce-atglance-dbadmin` | `phpmyadmin:5.2` | Optional (compose profile `tools`), bound to 127.0.0.1:8080 |

Only the two `atglance/ce-atglance-*` images are built by this project. The others are public images.

### Before going to production

- Put HTTPS in front of port 8000: a reverse proxy (Caddy, nginx, Traefik) on a VM, or the load balancer on a container service.
- [#5](https://github.com/niketchandra/atGlance-managementGUI/issues/5): keep settings only in the database, not in `.env`.
- [#8](https://github.com/niketchandra/atGlance-managementGUI/issues/8): production web server.

## Development

The Laravel app lives in `composer/`. The dev overlay builds the images from source, bind-mounts `composer/storage` and uses `composer/.env`:

```bash
cp .env.example .env                    # compose settings (ports, DB passwords)
cp composer/.env.example composer/.env  # then set APP_KEY
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build
```

Add `--profile gateway` or `--profile tools` to start Kong or phpMyAdmin.

Run the tests in a container (no local PHP needed):

```bash
docker run --rm -e VIEW_COMPILED_PATH=/tmp/views -v "$(pwd)/composer:/app" -w /app --entrypoint php ce-atglance-app:dev artisan test
```

`VIEW_COMPILED_PATH` keeps the test run from writing compiled views into the storage folder that the running app shares.

### Publish images

```bash
docker buildx build --platform linux/amd64,linux/arm64 \
  -t atglance/ce-atglance-app:<version> -t atglance/ce-atglance-app:latest --push .
docker buildx build --platform linux/amd64,linux/arm64 \
  -t atglance/ce-atglance-gateway:<version> -t atglance/ce-atglance-gateway:latest --push kong
```

## Documentation

| Topic | File |
|---|---|
| Installation (VM, ECS, Kubernetes) | [INSTALLATION.md](INSTALLATION.md) |
| API endpoints | [API.md](API.md) |
| Web console pages, white-label pages | [GUI_DOCUMENTATION.md](GUI_DOCUMENTATION.md) |
| Scheduled backups and restore | [BACKUP.md](BACKUP.md) |
| Notifications | [NOTIFICATIONS.md](NOTIFICATIONS.md) |
| API keys (PAT) | [PersonalAccessToken.md](PersonalAccessToken.md) |
| Kong gateway | [KONG.md](KONG.md) |
| Circuit breaker and queue | [resilience.md](resilience.md), [CircuitBreak.md](CircuitBreak.md), [QUEUE.md](QUEUE.md), [scenerio.md](scenerio.md) |
| Original project notes, curl examples, table layouts | [high-level-understanding.md](high-level-understanding.md) |
