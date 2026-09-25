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

## Deploy

You need Docker with Docker Compose on the server. Nothing else: PHP, Composer and MySQL run in containers.

**1. Get the code**

```bash
git clone https://github.com/niketchandra/atGlance-managementGUI.git
cd atGlance-managementGUI
```

**2. Create the app settings file with a fixed key**

```bash
cp composer/.env.example composer/.env
sed -i "s|^APP_KEY=.*|APP_KEY=base64:$(openssl rand -base64 32)|" composer/.env
```

The key encrypts stored secrets, such as S3, SMTP and SSO passwords and API keys. Back it up. If it changes, those secrets can no longer be read. On macOS, use `sed -i ''` instead of `sed -i`.

**3. Start everything**

```bash
docker compose up -d --build
```

This starts the app on port 8000, MySQL, Redis, the queue worker and the scheduler. The first build takes a few minutes.

**4. Run the setup wizard**

Open `http://<server-ip>:8000` in a browser. The installer creates the database tables and asks for:

- organization name
- your super admin account
- the domain or IP address, and whether it uses HTTPS

The installer ends on a summary page. Then log in at `http://<server-ip>:8000` with the super admin account you created.

**5. Connect a server**

1. In the console, open **Settings** and create an API key. It starts with `atgla-`.
2. On the Linux server, install the `atglance` CLI.
3. Point the CLI at `http://<server-ip>:8000/api` and give it the key.

The API is described in [API.md](API.md).

### Optional: API gateway (Kong)

To put the API behind Kong (rate limiting, one public port for the CLI):

```bash
docker compose -f docker-compose.yml -f docker-compose-kong.yml up -d --build
```

The CLI then uses `http://<server-ip>:8002` (Kong forwards `/<path>` to the app's `/api/<path>`). See [KONG.md](KONG.md).

### Ports

| Port | Service |
|---|---|
| 8000 | Web console and API |
| 8002 | Kong proxy (only with the Kong file) |
| 8080 | phpMyAdmin (development only; do not expose it) |
| 3306, 6379 | MySQL and Redis (do not expose them) |

## Update to a new version

```bash
git pull
docker compose up -d --build
docker compose exec api php artisan migrate --force
docker compose exec api php artisan view:clear
```

Keep `composer/.env` between updates. It holds the app key.

## Before going to production

The current setup is fine for a single server you control. These improvements are planned:

| Issue | What |
|---|---|
| [#4](https://github.com/niketchandra/atGlance-managementGUI/issues/4) | Supply the app key from outside the image; MySQL user with a password (it runs as `root` with no password today) |
| [#5](https://github.com/niketchandra/atGlance-managementGUI/issues/5) | Keep settings only in the database, not in `.env` |
| [#8](https://github.com/niketchandra/atGlance-managementGUI/issues/8) | Production web server, HTTPS, and fewer containers |

Until then:
- Put a reverse proxy with HTTPS (nginx, Caddy, Traefik) in front of port 8000.
- Keep ports 3306, 6379 and 8080 closed to the internet.

## Development

The Laravel app lives in `composer/`.

Run the tests in a container (no local PHP needed):

```bash
docker compose build api
docker run --rm -e VIEW_COMPILED_PATH=/tmp/views -v "$(pwd)/composer:/app" -w /app atglance-managementgui-api php artisan test
```

`VIEW_COMPILED_PATH` keeps the test run from writing compiled views into the storage folder that the running app shares.

## Documentation

| Topic | File |
|---|---|
| API endpoints | [API.md](API.md) |
| Web console pages, white-label pages | [GUI_DOCUMENTATION.md](GUI_DOCUMENTATION.md) |
| Scheduled backups and restore | [BACKUP.md](BACKUP.md) |
| Notifications | [NOTIFICATIONS.md](NOTIFICATIONS.md) |
| API keys (PAT) | [PersonalAccessToken.md](PersonalAccessToken.md) |
| Kong gateway | [KONG.md](KONG.md) |
| Circuit breaker and queue | [resilience.md](resilience.md), [CircuitBreak.md](CircuitBreak.md), [QUEUE.md](QUEUE.md), [scenerio.md](scenerio.md) |
| Original project notes, curl examples, table layouts | [high-level-understanding.md](high-level-understanding.md) |
