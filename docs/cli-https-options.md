# HTTPS for the atglance CLI — options

Status: for decision (2026-10-15). Nothing here is implemented yet.

## Problem

The atglance CLI sends secrets on every request: the personal API key (`atgla-`) or service account key (`atgsa-`) in `Authorization`, the system key in `X-Atglance-System-Key`, and passwords or PINs for deregister, reactivate, rotate-key and the force routes. It also uploads service unit files, which can contain `Environment=` secrets. On a default install the CLI reaches the console on the Kong gateway, `http://<server>:8002`, which is plain HTTP. Anyone on the network path can read or replay these values.

The CLI (v2) already:
- accepts `https://` and `http://` console URLs and verifies TLS certificates (no `--insecure` option);
- asks `Console URL (https:// recommended; http:// also works)` at `login` and warns after any `http://` login;
- refuses redirects (so an http→https redirect does not silently resend secrets).

What is missing is an HTTPS endpoint on the console side that hosts can use by default.

## Facts about the current console

- The web app listens on port 8000 and serves `/api/v1/*` itself ("same path on the gateway and on the app", docs/api-reference.md).
- The custom-domain feature (docs/custom-domain.md) puts Caddy (built in) or a platform proxy on 443 in front of port **8000**, with Let's Encrypt, the built-in CA, an uploaded certificate, or platform TLS.
- Kong (8002) is not behind the domain; docs/custom-domain.md says "The CLI port is fixed".
- The 1.x Python CLI always rewrites the URL to `<host>:8002` and cannot use another port or HTTPS on 443. The v2 CLI uses the URL exactly as given.

## Options

### A. Use the HTTPS domain for `/api/v1` (no new infrastructure) — recommended

v2 hosts log in with `https://console.example.com`. Requests go Caddy (443, TLS) → app (8000) → `/api/v1/*`. Kong stays on 8002 for 1.x hosts and the legacy routes.

| | |
|---|---|
| Work | 1) Verify that Caddy forwards `/api/v1/*` unchanged (it already blocks only `/api/internal/*`). 2) Verify rate limits (`v1-meta`, `v1-force`) and client IPs behind Caddy (`ATGLANCE_TRUSTED_PROXIES` / trusted proxy config). 3) Document "CLI v2: use your HTTPS domain" in docs/custom-domain.md and docs/api-reference.md; remove "The CLI port is fixed" for v2. 4) Show the HTTPS URL to copy in the console (e.g. on the API keys page: "Log in hosts with `sudo atglance login --server https://<domain>`"). |
| Pros | No new ports, certificates or containers; certificate renewal already handled; works on AWS/Azure/Kubernetes deploys that already terminate TLS for 8000. |
| Cons | Bypasses Kong for v2 traffic (Kong plugins, if any, do not apply); needs a domain (or the built-in CA cert installed on hosts for an internal name). |
| Host impact | `sudo atglance login --server https://console.example.com` once per host; system id and key stay (same console). |

### B. TLS listener on Kong (e.g. 8443)

Add an HTTPS listener to Kong using the same certificate as the domain; hosts use `https://<host>:8443`.

| | |
|---|---|
| Work | Kong `KONG_PROXY_LISTEN` gains `0.0.0.0:8443 ssl`; certificate/key mounted from `storage/caddy/` or uploaded; reload on renewal; publish port 8443; docs. |
| Pros | All CLI traffic keeps going through Kong; works without a domain if an IP certificate or the built-in CA is used. |
| Cons | A second place to install and renew certificates; new port to open on firewalls; renewal coupling with Caddy needs a reload hook. |

### C. Put Kong behind the domain (path-based)

Caddy routes `https://console.example.com/api/v1/*` (and legacy paths) to Kong 8002 instead of the app.

| | |
|---|---|
| Pros | One HTTPS endpoint; Kong stays in the path. |
| Cons | Changes the domain routing for the web app's own `/api/*` calls; more Caddy config; legacy 1.x hosts still cannot use it (they force port 8002). |

### D. Make HTTPS mandatory later

After A (or B) is available: the console answers `/api/v1/*` on plain HTTP with `426 Upgrade Required` (or a warning header first), except from localhost. The CLI already refuses redirects, so the 426 message must tell the user the HTTPS URL.

| | |
|---|---|
| When | After all v2 hosts moved (track with the per-request scheme in the activity log). |
| Cons | Breaks hosts still on http:// until they run `login` again. |

## Recommendation

1. **A now**: verify, document, show the HTTPS login command in the console.
2. Measure: log the scheme (`http`/`https`) of `/api/v1` requests per system; show a count of hosts still on HTTP in the admin dashboard.
3. **D later**: warning header from the first release with A, `426` from a set date.
4. B only if a deployment cannot use a domain at all.

## Open questions for the console team

- Does any Kong plugin (rate limiting, logging, auth) need to apply to v2 traffic? If yes, prefer B or C.
- Are there deployments without a domain? They need the built-in CA certificate installed on hosts (`/usr/local/share/ca-certificates/` + `update-ca-certificates`), or B with an IP certificate.
- Target date for D.
