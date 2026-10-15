# Console security hardening — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add two CLI-facing APIs (attach a service account to an existing system; config version hashes) and close the open findings from the 2026-10-15 review: credentials in GET query strings, brute-forceable force routes (account lockout + a security-events API), plain-text system keys, the committed `cookies.txt`, an unchecked AI Connect upload path, and the legacy-route sunset (2026-12-31). HTTPS for the CLI is a separate decision: see `docs/cli-https-options.md`.

**Architecture:** Laravel console (`composer/`), branch `Version2.1.2` (HEAD `318d740` when written). Each task is self-contained with PHPUnit feature tests. One small CLI task (separate repo) maps the new 423 answer and the Sunset header.

**Tech Stack:** Laravel / PHP, MySQL, PHPUnit (run in a throwaway container from the app image, as earlier tasks did: never restart, rebuild or `compose up` the running stack). CLI: Go 1.23.

**Decisions (owner, 2026-10-15):**
- Lockout: 5 failed force attempts for one account within 15 minutes lock force actions for that account for 15 minutes; each further lock doubles (30 min, 1 h, … max 24 h); the level resets after 24 h without a failure. Lock and unlock are logged, org admins are notified, and an API lists these security events.
- Legacy routes: removed on **2026-12-31**; GET variants removed now.
- CLI branches: unchanged. HTTPS: options document first.

## Global Constraints

- Never touch the running containers (`ce-atglance-*`): tests run in a separate container from the image.
- Same 404/401 wording as today for unknown systems and bad credentials (no existence leaks). New 423 answers must not reveal whether an email exists (see Task 3).
- No secret (password, PIN, PAT, system key, service-account key) in logs, activity rows, notifications or responses; keys only as last 4.
- Every schema change is a new migration; down() must restore the previous state except where a task says it is one-way.
- Commits: Conventional Commits, no Co-Authored-By trailer.

## Review Focus

1. Lockout must also count failures on the legacy force routes and the v1 force routes together (same account), and an unknown email must behave the same as a known one (no enumeration via 423).
2. Hashing system keys must keep every existing host working (1.x and v2 send the plaintext key; the console hashes and compares).
3. Rotate-key, register (idempotent + replaces), config uploads and legacy lookups must all use the hash after Task 5.
4. Removing GET routes must not break 1.x CLI (it uses POST for these routes — verify in its source on `main`).
5. AI Connect: no config content leaves the console unmasked.

---

### Task 1: Remove committed `cookies.txt`

- [ ] `git rm --cached cookies.txt`; add `cookies.txt` and `*.cookies.txt` to `.gitignore`; delete the local file if it is only a test artefact.
- [ ] Grep the repo for other committed artefacts: `git ls-files | grep -E "\.(txt|log|sqlite|sql|pem|key|env)$"` and review each (keep fixtures that tests need; report anything with a secret).
- [ ] The XSRF token in it was for localhost only; note in the commit that no rotation is needed beyond clearing sessions.
- [ ] Commit: `chore: stop tracking cookies.txt`.

### Task 2: Drop GET variants and add the legacy Sunset

**Files:** `composer/routes/api.php`, `kong/kong.yml` (no change needed: Kong routes are path-based), a middleware `LegacyApiSunset`, tests, `docs/api-reference.md`.

- [ ] Verify the 1.x CLI uses POST for `/system-reactive`, `/system-deregister-force`, `/system-reactivate-force` (`git show main:build/lib/atglance/cli.py` in the CLI repo, or the 1.2 source): expected POST.
- [ ] Tests: GET on each of the three routes → 405; POST still works (existing tests).
- [ ] Remove the three `Route::get(...)` lines (`api.php` ~64, 69, 73).
- [ ] Middleware `LegacyApiSunset` on every 1.x CLI route (`/auth/validate-token`, `/system-register*`, `/system-deregister*`, `/system-reactive`, `/system-reactivate-force`, `/config-files*` 1.x routes): adds `Deprecation: true`, `Sunset: Thu, 31 Dec 2026 23:59:59 GMT`, `Link: <…/docs/api-reference.md#cli-api-v1-apiv1>; rel="successor-version"`; records one activity row per (system or token, day) `legacy_api.used` with route and user agent, no secrets. Test: headers present; one row per day.
- [ ] Admin dashboard (or an artisan command `atglance:legacy-usage`): count of hosts that used legacy routes in the last 30 days.
- [ ] Docs: api-reference "Legacy routes are removed on 2026-12-31".
- [ ] Commit: `feat(api): drop GET force routes; announce legacy sunset 2026-12-31`.
- [ ] Follow-up (not now): a dated task to delete the legacy routes after 2026-12-31.

### Task 3: Account lockout for force actions

**Files:** migration (table `force_attempts`: `id`, `email_hash` (sha256 of lower-cased email), `user_id` nullable, `ip`, `succeeded` bool, `created_at`; and on `users`: `force_locked_until` nullable datetime, `force_lock_level` tinyint default 0, `force_lock_level_at` nullable datetime), `composer/app/Support/ForceLockout.php`, `SystemRegisterController::resolveForceActor` (shared by legacy and v1 force routes), tests.

Rules:
- Key: the lower-cased email (hashed), so unknown and known emails behave the same.
- Before checking credentials: if the email is locked (`force_locked_until > now` on the user, or ≥ 5 failed attempts for that email hash in the last 15 minutes with no lock yet) → `423 {"message":"Too many failed attempts; try again after <ISO time>"}` with `Retry-After`. For an unknown email, apply the same counting on `email_hash` (no user row) so the answer is identical.
- A failure records a row; reaching 5 failures in 15 min sets the lock: duration `15 min × 2^level` (max 24 h), `level++`, `level_at = now`. The level resets to 0 when `level_at` is older than 24 h at the time of the next lock.
- A success records a row and clears the counter window for that email (lock level stays until it ages out).
- The throttle (`v1-force`) stays as well.
- On lock: `ActivityRecorder::record('security.force_locked', …)` (user id, lock until, attempt count, IPs — no secrets) and a notification to org admins (new event `security.force_lockout` in `NotificationEvents`, org scope). On unlock by expiry: recorded lazily as `security.force_unlocked` at the next attempt after expiry.
- Admin unlock: `POST /api/v1/security/lockouts/{user}/unlock` (admin PAT) and a button in the console user page; recorded as `security.force_unlocked` (actor).

Tests: 5 failures → 6th attempt 423 even with the right password; Retry-After present; after the lock passes, a right password works; second lock lasts 30 min; unknown email: 6th attempt 423 too; legacy and v1 routes share the count; success resets the 15-min window; notification sent once per lock; no password/PIN in activity rows; admin unlock clears the lock.

- [ ] Commit: `feat(security): lock force actions after repeated failures`.

### Task 4: Security events API

**Files:** migration `security_events` (`id`, `org_id`, `type`, `user_id` nullable, `email_hash` nullable, `ip`, `details` JSON (no secrets), `created_at`), `composer/app/Support/SecurityEvents.php` (write helper used by Task 3 and others), `composer/app/Http/Controllers/Api/V1/SecurityEventController.php`, route, tests, docs.

- Event types (first set): `force.failed`, `force.locked`, `force.unlocked`, `force.succeeded`, `key.revoked`, `key.expired_used` (a request with an expired key), `system.key_rotated`, `system.replaced`, `legacy_api.used` (daily).
- `GET /api/v1/security/events?type=&since=&until=&user_id=&page=` — admin PAT only (rbac 100/101), scoped to the caller's org (super admin: all), newest first, 50 per page, `Link` next header. Response `{"events":[{"id","type","user":{"id","name"}|null,"ip","details","created_at"}],"next":…}`.
- Optional (same task if small): `GET /api/v1/security/lockouts` — currently locked accounts in the org.
- Retention: artisan command `atglance:security-events-prune` keeps 180 days (scheduled daily).
- Tests: admin sees own org only; non-admin 403; filters; pagination; no secrets in `details`.
- Docs: api-reference section "Security events".
- [ ] Commit: `feat(api): security events API`.

### Task 5: Store system keys as hashes

Today `system_register.validation_hash` holds the plaintext key and is copied into `configuration_files.validation_hash`, `raw_data`, and `services.system_hash`; about 55 references in 15 files (`FileController` 17, `SystemRegisterController` 9, `DashboardController` 7, `V1/SystemController` 6, `AuthenticateSystemKey` 3, …).

Design:
- New columns on `system_register`: `system_key_hash` (char 64, sha256 hex, indexed) and `system_key_last_four` (char 4).
- `SystemRegister::keyHash(string $key): string` = `hash('sha256', $key)`; `SystemRegister::matchesKey(string $key): bool` = `hash_equals($this->system_key_hash, self::keyHash($key))`.
- Every lookup that today compares or queries the plaintext key hashes the incoming key first and compares or queries `system_key_hash`.
- Copies on `configuration_files`, `raw_data`, `services`: replace lookups by key with lookups by `system_register_id` where the id is known; where only the key is known (legacy `config-show`, legacy upload with `validation_hash`), hash it and resolve the system first.
- Dashboard/search: show `••••` + `system_key_last_four`; search by full key hashes it; search by last four uses the column.

Subtasks (one commit each, tests first):
- [ ] 5a. Migration: add columns; backfill `system_key_hash` and `system_key_last_four` from `validation_hash` for every row; model helpers. Test: backfill correct for rows with and without a key.
- [ ] 5b. Auth paths: `AuthenticateSystemKey`, `V1/SystemController` (idempotent register, `replaces_*`, rotate-key), `CredentialController::optionalSystem`, `V1/ConfigFileController`. New keys are written to `system_key_hash`/`last_four` (and, until 5e, still to `validation_hash`). Tests: every existing v1 test passes; rotate-key updates the hash; old key → 404.
- [ ] 5c. Legacy paths: `SystemRegisterController` (register with `validation_hash`, deregister/reactivate), `FileController` (upload, filter, config-show, download), `ServiceController`. Tests: existing legacy tests pass unchanged (they send plaintext).
- [ ] 5d. Dashboards and search (`DashboardController`, `AdminDashboardController`), `SecretMask`, `ActivityLogger` redaction list. Tests: masked display; search by last four.
- [ ] 5e. **One-way, separate approval:** stop writing and then drop the plaintext columns (`system_register.validation_hash`, and the copies on `configuration_files`, `raw_data`, `services.system_hash`) after a release in which 5a–5d ran in production. Take a DB backup first. Not part of this plan's automatic execution.
- Review Focus 2/3 apply. Host test after 5d: 1.x host (legacy) and v2 host (v1) both still back up and restore.

### Task 6: AI Connect — no unmasked configs to third parties

**Files:** `composer/app/Http/Controllers/AiConnectController.php` (it does not reference `SecretMask` today), the services it calls, `composer/app/Support/SecretMask.php`, tests.

- [ ] Map every path where config content or system data leaves the console for an AI provider (AI Connect review, any "explain this config" action, MCP tools); list them in the PR description.
- [ ] Failing test per path: a config containing `password=…`, `Environment=DB_PASSWORD=…`, a PEM private key, an `atgla-`/`atgsa-` key and an AWS-style key is sent through the path with the HTTP client faked; assert none of those values appear in the outgoing request body.
- [ ] Apply `SecretMask` (extend its patterns if a case above is missed) before any outbound call; one shared helper so new paths cannot skip it.
- [ ] Add a visible note in the AI Connect UI: "Secrets are masked before sending; review what is sent" with a preview of the masked payload.
- [ ] Commit: `fix(ai): mask secrets before sending configs to AI providers`.

### Task 7: CLI follow-up (CLI repo `atGlance`, branch `Version2.0`)

- [ ] Map HTTP 423 from force routes to `atglance: too many failed attempts; try again after <time>` (no retry). Test with httptest.
- [ ] When a legacy route answers with a `Sunset` header, `check` prints `warn console legacy API ends on 2026-12-31; upgrade the console`. Test.
- [ ] Commit: `feat(v2): report force lockout and legacy API sunset`.

### Task 9: Attach or replace the service-account key of an existing system

**Why:** today a host can only get or change its service-account key with `register --force-new`, which creates a new system and a new version history. Rotating an expiring SA key should keep the system.

**Files:** `composer/routes/api.php` (v1 group with `auth.pat`, `active.user`, `atglance.system`), `composer/app/Http/Controllers/Api/V1/SystemController.php` (new method `serviceAccount`), tests in `CliApiV1Test.php` / `ServiceAccountSystemRoutesTest.php`, `docs/api-reference.md`.

**Contract:** `POST /api/v1/systems/self/service-account`
- Auth: a person's PAT (not an `atgsa-` key: `401` as for register) + `X-Atglance-System-Id` / `X-Atglance-System-Key` + `X-Atglance-Service-Account-Key: atgsa-...`.
- Caller must be allowed to manage the system (owner, org admin, workspace admin: `canManageSystem`); else `403`.
- The SA key must be valid, active, in the system's org, with `systems.register` on the system's workspace; else `401 Invalid service account key` / `403 Service account lacks systems.register in this workspace` (same messages as register).
- Effect: set `system_register.service_account_id` to that account (replacing any previous one); activity `system.service_account_changed` (old and new account ids, actor; no keys); security event (Task 4) of the same type.
- `DELETE /api/v1/systems/self/service-account`: detach (person PAT only); activity row.
- Response `200 {"system":{"id","system_name","service_account_id","workspace_id"}}`.
- Same system key, same id, same config history.

Tests: attach; replace with another account (old key then gets `404` on system-scoped routes, new key works); non-manager `403`; SA without grant `403`; SA of another org `401`; `atgsa-` in Authorization `401`; detach; no key values in activity rows.

- [ ] Commit: `feat(api): attach or replace a system's service account`.

### Task 10: Content hash for config versions

**Why:** after a state loss the CLI downloads the newest version of every service just to compare it; with a hash it compares without downloading.

**Files:** migration (`configuration_files.content_sha256` char(64) nullable, indexed with `system_register_id`, `service_name`), `FileController::uploadConfigFile` (compute `hash('sha256', $fileContent)` before storing), artisan backfill command `atglance:config-hash-backfill` (reads each stored file once; chunked; resumable; logs missing files), `V1/ConfigFileController` (`index` and `store` responses gain `"sha256"`), `docs/api-reference.md`.

**Contract:**
- `GET /api/v1/config-files[?service_name=]` items gain `"sha256": "<hex>"` (`null` until backfilled).
- `POST /api/v1/config-files` 201 `file` gains `"sha256"`.
- Optional query `GET /api/v1/config-files?service_name=ssh&latest=1` returning only the newest version per service (one row per service when no `service_name`), so a CLI with lost state needs one request for all services.

Tests: upload stores the hash (equals sha256 of the bytes); list and upload answers include it; backfill fills old rows and is idempotent; `latest=1` returns the highest id per service; other systems' rows never appear.

- [ ] Commit: `feat(api): sha256 of config versions`.

### Task 11: CLI follow-up for Tasks 9 and 10 (CLI repo `atGlance`, branch `Version2.0`)

- [ ] `api.Client`: `SetServiceAccount(ctx, sys, saKey)` and `ClearServiceAccount(ctx, sys)`; `ConfigVersion.SHA256`; parse `sha256` in list and upload answers; legacy client `ErrUnsupported`. httptest contract tests.
- [ ] Command `sudo atglance system service-account set` (prompts for the `atgsa-` key, hidden/stdin, never argv; saves `/var/lib/atglance/service-account.json` only after `200`) and `... service-account remove` (removes the local file after `200`). `schedule enable` hint and `check` warn text point to it instead of `register --force-new`. On a console without the route (`404`/`405`), print `this needs a newer console; use 'system register --force-new --service-account'`.
- [ ] Unchanged detection: when state has no entry, use `sha256` from `ListConfigs` (one request with `latest=1` when available) instead of downloading the file; fall back to the download when `sha256` is `null` or missing.
- [ ] Docs: commands.md, README, deployment.md (key rotation without re-registering), man page, completion.
- [ ] Commits: `feat(v2): set the service account of a registered system`, `feat(v2): compare config hashes without downloading`.

### Task 8: Docs and release notes

- [ ] `docs/api-reference.md`: 405 on GET force routes, Sunset headers, 423 lockout, security events API, system keys stored hashed (keys cannot be shown again; last four only).
- [ ] `docs/notifications.md`: `security.force_lockout`.
- [ ] `docs/api-reference.md`: `POST/DELETE /api/v1/systems/self/service-account`; `sha256` and `latest=1` on config-files.
- [ ] Release note: hosts need no change; admins can unlock in the console; legacy API ends 2026-12-31; hosts can change their service-account key without re-registering (CLI `2.0.0~alpha10`+).

## Order

1 → 2 → 3 → 4 → 6 → 5a–5d → 9 → 10 → 7 → 11 → 8. Task 5e waits for a production release and an explicit go-ahead. Tasks 9 and 10 are independent of 3–6 and can move earlier if the CLI work is more urgent.
