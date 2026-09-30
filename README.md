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
- **Scheduled backups and restore**: of configuration files and of the whole portal, to S3. See [scheduled-backups.md](docs/scheduled-backups.md).
- **Notifications**: Email, Microsoft Teams, Slack, n8n, Telegram, webhooks and SMS, set up per workspace. See [notifications.md](docs/notifications.md).
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

Open ports 8000 (web console) and 8002 (API gateway for the `atglance` CLI) in the firewall. Pin a version with `| sudo bash -s -- --version 1.2.1`. To upgrade, run the installer again. It keeps your data and passwords.

### Part B: Deploy on ECS or another container service

Run the images as separate services next to managed MySQL 8.0 and Redis 7:

| Service | Image | Command | Count |
|---|---|---|---|
| app | `atglance/ce-atglance-app` | default (port 8000, health `GET /up`) | 1 during install, then scale |
| worker | `atglance/ce-atglance-app` | `php artisan queue:work redis --tries=5 --backoff=30,60,120,300,600 --timeout=60` | 1 or more |
| scheduler | `atglance/ce-atglance-app` | `php artisan schedule:work` | exactly 1 |
| gateway | `atglance/ce-atglance-gateway` | default (port 8002) | 1 or more |

1. Create MySQL (database and user `atglance`), Redis and a shared file system (EFS, Azure Files, a `ReadWriteMany` volume).
2. Mount the shared file system at `/app/storage` in the app, worker and scheduler. It holds the app key and stored files.
3. Set `ATGLANCE_ROLE`, `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `REDIS_HOST`, `CACHE_STORE=redis` and `QUEUE_CONNECTION=redis` on all three.
4. Start the app first. When it is healthy, start the worker, the scheduler and the gateway.
5. Put a load balancer with HTTPS in front: ports 443 and 8000 to the app, port 8002 to the gateway, all on one host name. The CLI needs 8000 and 8002.
6. Open `https://<your-domain>` and complete the setup wizard.

[INSTALLATION.md](INSTALLATION.md#ecs-on-fargate-step-by-step) has a full ECS Fargate walkthrough with task definitions, plus Kubernetes, Azure Container Apps and Cloud Run notes.

### After installing

- **Back up the app key.** It encrypts stored secrets (S3, SMTP and SSO passwords, API keys). On a VM: `docker exec ce-atglance-app grep APP_KEY /app/storage/.env`.
- **Connect a server.** In the console, open **Settings** and create an API key (it starts with `atgla-`). On the server, run `sudo atglance --configure` and enter the gateway as the management URL: `http://<server-ip>:8002` (VM) or `https://<your-domain>:8002` (load balancer). The API is described in [api-reference.md](docs/api-reference.md).

### Containers

| Container | Image | What it does |
|---|---|---|
| `ce-atglance-app` | `atglance/ce-atglance-app` | Web console and API. Runs database migrations on start. |
| `ce-atglance-worker` | `atglance/ce-atglance-app` | Queue worker. Replays writes that were queued while MySQL was down. |
| `ce-atglance-scheduler` | `atglance/ce-atglance-app` | Laravel scheduler. Runs scheduled S3 backups, see [scheduled-backups.md](docs/scheduled-backups.md). |
| `ce-atglance-db` | `mysql:8.0` | Database (VM install) |
| `ce-atglance-redis` | `redis:7-alpine` | Cache, queue and circuit-breaker state (VM install) |
| `ce-atglance-gateway` | `atglance/ce-atglance-gateway` | Kong API gateway, port 8002, with the AtGlance routes built in. The `atglance` CLI talks to it. |

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

This starts the app (port 8000), worker, scheduler, MySQL, Redis and the Kong API gateway (port 8002).

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

> [!TIP]
> **Start here.** These documents cover most needs:
>
> | Document | Read it to |
> |---|---|
> | **[INSTALLATION.md](INSTALLATION.md)** | Install and upgrade AtGlance on a VM or on ECS, Kubernetes and other container services |
> | **[docs/api-reference.md](docs/api-reference.md)** | Connect the `atglance` CLI or your own client: every API endpoint, with requests and responses |
> | **[docs/web-console.md](docs/web-console.md)** | Find your way around the web console: pages, roles, white-label pages |
> | **[docs/scheduled-backups.md](docs/scheduled-backups.md)** | Set up scheduled S3 backups and restore the portal |
> | **[docs/api-keys.md](docs/api-keys.md)** | Create, view and revoke the `atgla-` API keys that servers use |

All documents are in the [docs](docs/) folder:

| Area | Document | What it covers |
|---|---|---|
| Install | [INSTALLATION.md](INSTALLATION.md) | VM installer, ECS Fargate walkthrough, Kubernetes and other platforms |
| Use | [web-console.md](docs/web-console.md) | Web console layout, pages and white-label pages |
| Use | [notifications.md](docs/notifications.md) | Notification channels (Email, Teams, Slack, n8n, Telegram, webhooks, SMS) and events |
| Use | [ai-connect.md](docs/ai-connect.md) | Connecting an AI provider, per-provider setup |
| Use | [licence.md](docs/licence.md) | Licence key, verification, and what an unlicensed instance blocks |
| Use | [custom-domain.md](docs/custom-domain.md) | Custom domain and HTTPS after install, the built-in proxy, own certificates |
| Operate | [scheduled-backups.md](docs/scheduled-backups.md) | Scheduled S3 backups, restore, the scheduler container |
| Operate | [queue.md](docs/queue.md) | Queue worker, retries and monitoring |
| Operate | [api-gateway.md](docs/api-gateway.md) | Kong API gateway used by the CLI: routes, rate limits, adding routes |
| Integrate | [api-reference.md](docs/api-reference.md) | Every API endpoint, with requests and responses |
| Integrate | [api-keys.md](docs/api-keys.md) | API key (PAT) flow for the `atglance` CLI |
| Develop | [project-overview.md](docs/project-overview.md) | Features, branches, curl examples, table layouts |
| Develop | [backend-guide.md](docs/backend-guide.md) | Backend code layout, request flow, authentication, CRUD walkthrough |
| Develop | [resilience-patterns.md](docs/resilience-patterns.md) | Circuit breaker and message queue patterns explained |
| Develop | [resilience-implementation.md](docs/resilience-implementation.md) | How the circuit breaker and queue are built in this code |
| Develop | [circuit-breaker.md](docs/circuit-breaker.md) | Circuit breaker code and request workflow |
| Develop | [tested-scenarios.md](docs/tested-scenarios.md) | Failure scenarios tested (MySQL down, breaker open, misconfiguration) |
