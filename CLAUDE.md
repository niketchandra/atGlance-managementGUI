# CLAUDE.md

Guidance for Claude Code when working in this repository.

## What this is

Laravel 12 API + web management console for **AtGlance**, a server-health CLI
(companion repo, not in this working tree: `AtGlance` at
`D:\Projects\Personal\AtGlance\atGlance`, Python 3.10+, installed as a `.deb`
on Ubuntu hosts). This repo (`atGlance-managementGUI`) is the backend those
CLI agents talk to: it authenticates them via Personal Access Tokens (PAT),
stores config-file backups they upload, tracks registered systems, and now
also ships a browser-based admin/user console for humans.

Two audiences hit this backend:
- **atglance CLI agents** (machine clients) → REST API under `/api/*`
  (`routes/api.php`), fronted by Kong (see below).
- **Humans** (org admins / end users) → server-rendered Blade web app under
  `/` (`routes/web.php`).

Laravel app lives in `composer/`. Root-level `*.md` files are living docs —
read them, don't duplicate them here: `API.md` (full endpoint reference),
`resilience.md` / `CircuitBreak.md` / `QUEUE.md` (resilience deep-dives),
`KONG.md` (gateway config), `PersonalAccessToken.md` (PAT flow),
`GUI_DOCUMENTATION.md` (web UI layout/pages), `BACKUP.md` (scheduled S3
backups and the scheduler container), `NOTIFICATIONS.md` (notification
channels, workspace groups and events), `AI_CONNECT.md` (AI provider
connection and per-provider setup), `scenerio.md` (tested failure
scenarios), `LARAVEL.md` / `IMPLEMENTATION.md` (original API layout notes).

## Architecture

```
atglance CLI  ──▶  Kong (:8002)  ──▶  Laravel API (:8000, routes/api.php)
                                            │
Browser (admin/user)  ──▶  Laravel web (routes/web.php)
                                            │
                                     MySQL 8.0 + Redis 7
                                            │
                              queue-worker (php artisan queue:work redis)
```

- **Kong** (`kong/kong.yml`, `docker-compose-kong.yml`) is the only public
  entrypoint for API traffic (port 8002 → proxies to Laravel :8000). Add new
  API routes here too when adding a Kong route — see `KONG.md`.
- **MySQL** (`atglance` db, root/no password in dev) via Eloquent.
- **Redis**: cache store, queue backend, and circuit-breaker state
  (`CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`).
- **Queue worker**: `php artisan queue:work redis --tries=5
  --backoff=30,60,120,300,600 --timeout=60` — retries writes that were queued
  while the DB circuit breaker was open.

## Two authentication systems — don't mix them up

| | Session auth | PAT auth |
|---|---|---|
| Middleware alias | `auth.session` / Laravel `auth` (web) | `auth.pat` |
| Used by | Browser (Blade forms, `routes/web.php`) | atglance CLI machine clients (`routes/api.php`) |
| Token format | Laravel session / `sessions` table | `atgla-`-prefixed, SHA256-hashed, in `personal_access_tokens` |
| Model | `App\Models\Session` | `App\Models\PatToken` (extends Sanctum `PersonalAccessToken`) |
| Header | Cookie/session | `Authorization: Bearer atgla-...` |

`PatToken::generateCustomToken()` mints the `atgla-` token; only the SHA256
hash is stored (`token`), plus an `Illuminate\Support\Facades\Crypt`-encrypted
copy (`token_encrypted`) so the plaintext can be shown once more in the
Settings UI (`DashboardController::viewApiKey`). See `PersonalAccessToken.md`.

`AuthenticatePatToken` middleware validates the `atgla-` prefix, hashes,
looks up, checks `status`/`expires_at`, updates `last_used_at`, and resolves
`$request->user()`.

## RBAC — `rbac_id` values

Defined in `app/Models/Rbac.php` / `rbac` table, referenced everywhere as a
raw int on `User`:

- `100` — org super-admin. Exactly one is created by the **installer wizard**
  (see below) via `InstallationSeeder`. `User::isSuperAdmin()`.
- `101` — admin. Created via `/admin/users` by a `100`/`101` user.
  `User::isAdmin()` returns true for `[100, 101]`.
- `102` — regular user (default for new signups/`register`).

`AdminRoleMiddleware` (alias `admin.role`) gates `/admin/*` web routes to
`rbac_id` in `[100, 101]`. Organizations (`organizations` table /
`App\Models\Organization`) group users and systems via `org_id`.

## First-run installer (WordPress-style)

New, uncommitted feature — not yet in git history, so don't assume it's
documented elsewhere:

- `App\Support\InstallationState` (`composer/app/Support/InstallationState.php`)
  is the source of truth for "is this instance installed?" — a flat JSON
  marker at `storage/app/installer/installed.json`, **not** a DB row.
- `EnsureApplicationInstalled` middleware (alias `app.installed`) wraps the
  entire auth/dashboard route group in `routes/web.php`; if not installed, it
  redirects to `install.show`. The root `/` route does the same check
  manually.
- `InstallerController` (`GET/POST /install`, `GET /install/info`): renders
  the setup form (org name, admin name/email/password, domain, HTTPS toggle)
  → on submit, runs `migrate --force`, checks for a duplicate admin email,
  stashes form values into runtime `config('installer.*')`
  (`config/installer.php`), runs `db:seed --class=InstallationSeeder --force`,
  clears caches, calls `InstallationState::markInstalled(...)`.
- `InstallationSeeder` (replaces the now-deleted `AdminUserSeeder`) reads
  `installer.*` config and creates the single `rbac_id=100` admin + updates
  org `id=200`'s name. `DatabaseSeeder::run()` is now a no-op — seeding is
  driven entirely by the install form, not build-time fixtures.

When touching auth/admin/install code, check whether a fresh (un-installed)
instance still bootstraps correctly — most web routes are unreachable until
`installer/installed.json` exists.

## Web GUI

Server-rendered Blade, no SPA framework; Tailwind (`@tailwindcss/vite`) +
Vite build (`composer/package.json`, `npm run dev` / `npm run build`).
Layout is a 20/80 sidebar split — see `GUI_DOCUMENTATION.md` for the full
page/route/controller map. Key controllers:

- `AuthController` — web login/register/logout (separate from the API's
  `Api\AuthController`; the web one checks `password_hash` directly rather
  than Sanctum).
- `DashboardController` — user dashboard, configuration-backup browsing
  (`/configuration-backups*`), registered-systems browsing
  (`/systems-registered*`), settings incl. API-key create/view/revoke
  (view/revoke require password re-confirmation), password change.
  `live-service-monitoring` and `vulnerabilities-identified` views currently
  exist as routes/views without backing logic — check before assuming they're
  wired to real data.
- `AdminDashboardController` — `/admin/*`, user management (list/create/edit,
  promote to admin via `rbac_id`).

## Resilience pattern (API side)

- **Circuit breaker** (`app/Services/CircuitBreaker.php`, state in Redis):
  opens after repeated DB failures (3-strike variant wraps DB calls per
  `IMPLEMENTATION.md`; default service config is 5 failures/60s). While open,
  reads return `503`; writes are queued (`CreateUserJob` etc., Redis-backed)
  and the API returns `202`.
- **Queue jobs** retry with exponential backoff `[30, 60, 120, 300, 600]`,
  `tries=5`. See `QUEUE.md` for worker command and monitoring, `scenerio.md`
  for the exact failure scenarios this was built/tested against.

## System registration & config-file flow (why the CLI talks to this repo)

`system_register` table holds one row per enrolled host, keyed by a
persistent `validation_hash` the CLI generates once and stores in
`/etc/environment` on the host (survives reinstalls). `configuration_files` +
`raw_data` store uploaded service-config backups, linked to
`system_register_id` and `service_name`/`service_id`. Full endpoint list incl.
request/response shapes: `API.md`.

## Activity logging

`ActivityLogger` middleware (global, appended in `bootstrap/app.php`) logs
POST/PUT/PATCH/DELETE to a fixed allow-list of transactional paths (auth,
config-files, files, system-register/deregister, users, products, settings)
into `activity_logs`, swallowing its own failures so logging never breaks a
request. Password fields are stripped from the logged payload.

## Conventions / gotchas worth knowing before editing

- `User.password_hash` (legacy/API) and `User.password` (web/Hash::make) are
  two separate columns kept in sync by different code paths — check both
  when touching auth.
- `routes/api.php` (CLI, PAT/session auth) and `routes/web.php` (browser,
  session/installer-gated) are separate route files with separate
  middleware stacks — a new endpoint almost always belongs in exactly one.
- Migrations are date-stamped incrementally in
  `composer/database/migrations/` (not squashed) — check the latest files
  there for current schema truth over any prose doc.
