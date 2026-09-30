# Custom domain and HTTPS: design

- **Date:** 2026-09-30
- **Issue:** [#22](https://github.com/niketchandra/atGlance-managementGUI/issues/22)
- **Status:** Draft for review

## 1. Goal

Anyone in an organisation opens the AtGlance console at a plain name, such as
`https://atglance.internal` or `http://atglance.acme.com`. The admin sets this
up **after installation**, from the console. The same image works on a VM with
Docker Compose and on managed container platforms (AWS ECS, Azure Container
Apps, Kubernetes).

### 1.1 Access paths (the promise to users)

The console is always reachable in at least one of these ways. A problem with
one path never blocks the others.

| # | Address | Works when | HTTPS |
|---|---|---|---|
| 1 | `http://<server IP>:8000` | Always, in every mode. The recovery path. | No |
| 2 | `http://atglance.internal:8000` | The name points to the server (DNS or hosts file). Always works; no settings needed. | No |
| 3 | `http://atglance.internal` / `https://atglance.internal` | The built-in proxy is running (section 11) and the name points to the server | HTTP always; HTTPS (internal CA) while the plugin is enabled |
| 4 | `http(s)://<configured domain>` | The domain is set on the Site tab and DNS points to the server (or to the platform load balancer) | As chosen: off / automatic / own certificate / platform |

**Rules:**
- **Any domain or subdomain.** The configured domain can be any valid host
  name at any depth: `acme.io`, `atglance.acme.com`, `ops.eu.acme.com`.
  Exactly one configured domain at a time.
- **`atglance.internal` is the built-in fallback name.** It is always
  accepted, never redirected, and needs no settings.
  - It is also the suggested value when the Domain field is empty.
  - Configuring `atglance.internal` itself as the domain is allowed. Then
    rows 3 and 4 are the same name, with the chosen HTTPS mode.
- **Fallback hosts are never redirected.** The IP, `localhost` and
  `atglance.internal` are never redirected to HTTPS or to the domain. Only the
  configured domain gets the HTTPS redirect. A broken certificate or DNS for
  the domain therefore cannot lock anyone out.
- **Every path serves the whole console.** Login, sessions and links work
  from any path. Links generated inside a request use that request's host.
  Only background mail and jobs use `APP_URL`, which points to the configured
  domain (section 9).

## 2. Constraints

1. **Installation does not change and cannot fail because of this feature.**
   - No domain or HTTPS fields in the installer.
   - No new `install.sh` flags or checks.
   - The default compose file publishes only `8000` and `8002`, as today.
2. **The app never controls Docker.** No `docker.sock` mount. Managed
   platforms have no Docker socket and no Compose.
3. **Recovery path.** `http://<server IP>:8000` keeps working in every mode,
   so a wrong setting can always be fixed from the console.
4. **The CLI and Kong do not change.** The atglance CLI keeps using `<host>:8002`.
5. **The default web server does not change.** Default installs keep
   `php artisan serve` on port `8000`.

## 3. Current state

- Site Configuration > **Domain Alias** is stored in `admin_settings`
  (`site_domain_alias`), but no code uses it. `APP_URL`, redirects and
  certificates do not change.
- **Application IP (auto-fetched)** falls back to `SERVER_ADDR`. Under Docker,
  that is the container IP (`172.x`), which no client can reach.
- The installer writes `APP_FORCE_HTTPS`, but no code reads it.
- A DNS record or hosts entry maps a name to an IP, never to a port. With the
  app on `8000`, users must type `:8000`. Nothing listens on 80 or 443.

## 4. Overview

```
                    VM (built-in proxy)                        Managed platform
Browser ── https://atglance.internal                 Browser ── https://atglance.acme.com
   │                                                    │
   ▼  host :80 / :443 (only after opt-in)               ▼  ALB / ACA ingress / K8s Ingress (TLS)
┌─ app container ──────────────────────┐             ┌─ app container ──────────┐
│ Caddy :80/:443 ── 127.0.0.1:8000 ─┐   │             │ artisan serve :8000      │
│ (on-demand TLS; asks the app)     │   │             │ (trusts X-Forwarded-*)   │
│ artisan serve :8000 ◄─────────────┘   │             └──────────────────────────┘
└───────────────────────────────────────┘
       http://<IP>:8000 always works (recovery)
```

The feature has four parts:

1. **Domain plugin.** A card on the Plugins tab: an enable switch, setup steps
   for each platform, and the detected proxy status.
2. **Access URL settings.** On the Site tab: domain, HTTPS mode, server IP, DNS
   help, and a check button. These fields unlock after the plugin is enabled.
3. **Built-in proxy (VM only).** A Caddy binary in the app image. An opt-in
   compose override file starts it.
4. **Request handling in the app.** Trusted proxy headers, the HTTPS redirect,
   `APP_URL`, and the endpoint that approves certificates.

## 5. Settings

All values are stored in `admin_settings`, in a new group `domain`:

| Key | Values | Meaning |
|---|---|---|
| `domain_plugin_enabled` | `true` / `false` | Plugin switched on in the Plugins tab |
| `site_domain_alias` | host name, e.g. `atglance.internal` | Existing key, kept |
| `site_domain_alias_ip` | IPv4/IPv6 | Existing key; now editable |
| `site_https_mode` | `off` / `builtin` / `custom` / `platform` | New. Replaces the meaning of `site_https_enabled` |
| `custom_cert` | encrypted JSON `{cert_pem, key_pem, subject, sans, issuer, not_after, fingerprint}` | Uploaded organisation certificate (mode `custom`) |
| `proxy_last_seen` | JSON `{type, scheme, at}` | Last proxied request seen (see section 8) |

Migration of existing values: if `site_https_mode` is missing, it is derived.
`site_https_enabled = true` becomes `builtin`; anything else becomes `off`.
The old `site_https_enabled` key is still written, for compatibility.

## 6. Installer and info page

- `/install`:
  - Remove the **Domain Alias (optional)** and **Use HTTPS** fields, and the
    `app_domain` and `use_https` validation.
  - Keep **IP Address**. It still sets `APP_URL=http://<ip>`.
  - Stop writing `APP_FORCE_HTTPS`.
- `installed.json`: stop writing `app_alias_domain` and `https_enabled`.
  Existing files keep them; nothing reads them.
- `/install/info`:
  - Replace the "Domain Alias" and "HTTPS Enabled" rows with one note: "Want
    your own domain or HTTPS? After you log in, open Admin Settings > Plugins
    > Custom Domain & HTTPS."

## 7. Plugins tab: "Custom Domain & HTTPS"

The Plugins tab is a placeholder ("Coming Soon") today. It gets one card. Only
the super admin (`rbac_id` 100) can change it; admins (101) see it read-only.
This matches the other settings tabs.

**The card shows:**

- **Enable switch** (`domain_plugin_enabled`). Switching it off makes the
  domain setup inactive, but the domain and HTTPS mode stay stored so they are
  not lost.
- **Status line.** Its value comes from `proxy_last_seen` (section 8):
  - "Not detected yet. Open the console through the proxy once to confirm."
  - "Built-in proxy detected (HTTP)" or "Built-in proxy detected (HTTPS)".
  - "Platform proxy detected (HTTPS)" (a load balancer or ingress).
- **Setup steps, one tab per platform:**
  - **VM / Docker Compose:**
    1. Check that ports 80 and 443 are free:
       `sudo ss -ltn '( sport = :80 or sport = :443 )'`. No output means both
       are free.
    2. In the install folder, run:
       `docker compose -f docker-compose.yml -f docker-compose.domain.yml up -d`.
       If port 80 or 443 is in use, set `HTTP_PORT` or `HTTPS_PORT` in `.env`
       first.
    3. Open `http://<server IP>` (port 80) once. The status changes to
       "Built-in proxy detected".
    4. To undo it, run `docker compose up -d` without the override file.
  - **AWS ECS:** route an ALB listener on 443 (ACM certificate) to target port
    `8000`. Route port 8002 separately if the CLI must reach Kong. Then set the
    HTTPS mode to "Handled by my platform".
  - **Azure Container Apps:** ingress target port `8000`, plus a custom domain
    with a managed certificate. Then choose "Handled by my platform".
  - **Kubernetes:** an Ingress to service port `8000`, with cert-manager for
    TLS. Then choose "Handled by my platform".
- **Next-step link:** "Set the domain on the Site tab".

## 8. Proxy detection

A middleware, `DetectFrontProxy`, runs on web requests while the plugin is
enabled. When a request shows a proxy, it records `proxy_last_seen`. It writes
at most once a minute, through the cache, so it does not write to the database
on every request.

| Request signal | Recorded `type` |
|---|---|
| Header `X-AtGlance-Proxy: builtin`, and the request came from `127.0.0.1` | `builtin` |
| `X-Forwarded-Proto` or `X-Forwarded-For` present, and not the built-in proxy | `platform` |

`scheme` is the original scheme: `https` if `X-Forwarded-Proto` is `https`.
The plugin card and the Site tab both read this value.

## 9. Site tab: "Access URL" section

The Domain Alias, Application IP and Enable HTTPS fields, and the DNS
instructions box, are replaced by an **Access URL** section.

- **Locked state** (plugin off): the fields are shown disabled and greyed out,
  with the text "Enable Custom Domain & HTTPS in the Plugins tab to set a
  domain", and a link there. The stored values are shown read-only.
- **Fields** (plugin on):
  - **Domain.** Any domain or subdomain (for example `acme.io`,
    `atglance.acme.com`, `ops.eu.acme.com`).
    - A host name only: no scheme, path or port. Lower-cased; a trailing dot
      is removed.
    - Validated as a host name: labels of 1–63 characters from `a-z 0-9 -`,
      not starting or ending with `-`; at least two labels; 253 characters
      at most. An IP address is refused, because the IP path always works
      anyway.
    - Placeholder and suggestion when the field is empty: `atglance.internal`.
    - Warning under the field when it ends in `.local`: "`.local` is reserved
      for mDNS and can resolve slowly on macOS and Linux. Use `.internal` or
      `.lan` instead."
  - **HTTPS:**
    - **Off:** plain `http://<domain>`.
    - **Automatic certificate:** VM with the built-in proxy. Caddy gets the
      certificate itself (Let's Encrypt, falling back to its internal CA).
      Selectable only when a `builtin` proxy has been detected.
    - **Use my own certificate:** VM with the built-in proxy. The organisation
      uploads its certificate (section 9a). Caddy never issues one in this
      mode. Selectable only when a `builtin` proxy has been detected.
    - **Handled by my platform:** a load balancer or ingress does TLS, and the
      certificate is managed there (ACM, Key Vault, cert-manager).
  - **Server IP.** Editable. Validated with `IpAddressWithOptionalPort` (the
    port must be empty here).
    - Pre-fill order: the stored value, then the request host if it is an IP,
      then the `APP_URL` host if it is an IP. `SERVER_ADDR` is no longer used.
- **Help box**, filled from the current values:
  - **Access URL:** `https://atglance.internal`.
  - **DNS record:** `atglance.internal  A  10.0.0.5`, with a copy button.
  - **Hosts-file line** for testing on one machine: `10.0.0.5  atglance.internal`.
    File paths are given for Windows, macOS and Linux.
  - With automatic HTTPS and a private name: a **Download CA certificate** link
    (section 11) and one-line trust steps for Windows, macOS, Linux and Firefox.
    This is not shown in the `custom` mode, because the organisation's CA is
    already trusted on its machines.
- **Check button** (`POST /admin/settings/domain/check`, JSON):
  - Resolves the domain from the server with `gethostbynamel()`.
  - Compares the result with Server IP.
  - Reports "Resolves to 10.0.0.5 (matches)", "Resolves to X (does not match
    10.0.0.5)" or "Does not resolve".
  - The page notes that this is the server's DNS view. Clients on another
    network may differ.

### 9a. Own certificate (mode `custom`)

**Upload form** (shown when "Use my own certificate" is selected):
- Either a **PEM** pair:
  - a certificate file (`.crt`/`.pem`), which may include the intermediate
    chain after the leaf;
  - a private key file (`.key`/`.pem`);
  - an optional key passphrase.
- Or a **PKCS#12** file (`.pfx`/`.p12`) with its password. This is common for
  certificates exported from Windows / AD CS.
- Each file is limited to 64 KB.

**Checks before saving** (PHP `openssl_*`, no shell). The upload is rejected
with a clear message when:
1. the certificate or key cannot be parsed, or the passphrase is wrong;
2. the key does not belong to the certificate (`openssl_x509_check_private_key`);
3. the certificate does not cover the domain. The Subject Alternative Names
   (or the CN, when there are no SANs) must include the domain exactly, or a
   wildcard `*.parent` that matches one label;
4. the certificate has expired, or is not valid yet.

**Storage:**
- The leaf, the chain and an unencrypted PEM copy of the key are stored as
  `custom_cert`, encrypted with `Crypt` (`APP_KEY`), in `admin_settings`.
- The uploaded files are not kept.
- The passphrase is used once to decrypt the key and is not stored.

**Shown after upload:**
- subject, SANs, issuer, expiry date and SHA-256 fingerprint;
- a **Remove certificate** button.

**Expiry warning:**
- The Site tab and the Plugins card show a warning banner when the
  certificate expires within 30 days, and an error when it has expired.
- Renewing means uploading the new certificate. It is used for new
  connections (section 11).

**Domain change:** if the domain is changed and the stored certificate does not
cover the new one, saving is refused with "The uploaded certificate does not
cover <domain>. Upload a certificate for it, or choose another HTTPS option."

**On save:**
- The values are stored.
- `APP_URL` in `.env` is set to `<scheme>://<domain>`. If the domain is
  cleared, it goes back to `http://<server IP>`.
- `config:clear` runs.
- Nothing needs a restart.

## 10. Request handling in the app

- **Trusted proxies** (`bootstrap/app.php`):
  - `$middleware->trustProxies(at: ['127.0.0.1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'])`,
    with the headers `X-Forwarded-For`, `-Host`, `-Port` and `-Proto`.
  - The ranges cover the built-in proxy, Docker networks and private load
    balancers.
  - An operator can replace the list with the env variable
    `ATGLANCE_TRUSTED_PROXIES` (comma-separated). `bootstrap/app.php` reads the
    env variable directly, because config is not loaded yet at that point.
- **HTTPS redirect.** The middleware `EnforceDomainHttps` redirects only when
  all of these are true:
  1. the plugin is enabled;
  2. `site_https_mode` is `builtin`, `custom` or `platform`;
  3. the request host equals `site_domain_alias`, and is not one of the
     fallback hosts (the IP, `localhost`, `atglance.internal` unless it is the
     configured domain);
  4. the request is not secure.

  It redirects with `302` (not `301`) to `https://<domain><path>`. Browsers
  cache a `301` for good, so if HTTPS were later switched off, a cached `301`
  would keep sending users to a dead `https://` URL. Requests to the IP, to
  `:8000` and to the fallback hosts are never redirected. That keeps the
  recovery path open.
- **Cookies on every path:**
  - `SESSION_DOMAIN` stays `null`, so the session cookie is host-only and works
    on the IP, `atglance.internal` and the domain alike.
  - `session.secure` follows the request (`$request->isSecure()`, which trusts
    the proxy's `X-Forwarded-Proto`). The cookie is secure on HTTPS paths and
    still works on plain HTTP paths.
  - Logging in on one address does not log you in on another. Each host has
    its own cookie. This is expected, and stated on the Site tab.
- **Links:** web requests build links from the current request's scheme and
  host. `URL::forceRootUrl` is not used, so the IP and fallback paths do not
  jump to the domain. `APP_URL` is used only outside requests (queued mail,
  scheduled jobs).
- **Local-only rule for internal endpoints.** Both endpoints below live in
  `routes/api.php`, under `/api/internal/domain/*`. The API group has no
  session, so a TLS handshake does not create a session row. A request counts
  as local only when all of these are true:
  1. `REMOTE_ADDR` is `127.0.0.1` or `::1`;
  2. it has no `X-Forwarded-For` and no `X-AtGlance-Proxy` header.

  **Why rule 2 matters:** behind the built-in Caddy, *every* proxied request
  reaches the app from `127.0.0.1`. Without rule 2, anyone could fetch the
  private key through the proxy. Caddy's own `ask` and `get_certificate` calls
  carry neither header; `reverse_proxy` always adds `X-Forwarded-For`. As a
  second layer, the Caddyfile answers `404` for `/api/internal/*` itself
  (section 11).
- **Certificate approval endpoint:**
  `GET /api/internal/domain/tls-allowed?domain=<name>`.
  - Answers `200` when the request is local, the plugin is enabled, and either:
    1. `domain` is `atglance.internal` (the fallback name gets an
       internal-CA certificate in every mode while the built-in proxy runs);
       or
    2. `site_https_mode` is `builtin` or `custom`, and `domain` equals
       `site_domain_alias`.

       `custom` is allowed because Caddy may check permission before it asks
       the certificate endpoint. In `custom` mode the certificate endpoint
       always has a certificate for the domain, because saving `custom` needs
       one and removing it switches the mode to `off`. So Caddy never reaches
       its issuers for that name.
  - Otherwise it answers `404`. So Caddy never gets a certificate for a name
    nobody configured, even if someone points random DNS names at the server.
- **Own certificate endpoint:**
  `GET /api/internal/domain/certificate?server_name=<name>`.
  - Caddy calls it during a TLS handshake (`get_certificate http`).
  - Answers `200` with a PEM bundle (leaf, chain, then the private key) only
    when all of these are true:
    1. the request is local;
    2. the plugin is enabled;
    3. `site_https_mode` is `custom`;
    4. `server_name` is covered by the stored certificate and equals
       `site_domain_alias`.
  - Otherwise it answers `204 No Content`. It never logs the response body.
- **Removing the own certificate** switches `site_https_mode` to `off`.

## 11. Built-in proxy (VM only)

**Image changes** (`Dockerfile`):
- `COPY --from=caddy:2 /usr/bin/caddy /usr/local/bin/caddy`. The binary is
  about 40 MB and is not run by default.
- `docker/Caddyfile` is copied to `/etc/caddy/Caddyfile`:

```
{
    admin off
    storage file_system /app/storage/caddy
    on_demand_tls {
        ask http://127.0.0.1:8000/api/internal/domain/tls-allowed
    }
}

(atglance_upstream) {
    @internal path /api/internal/*
    respond @internal 404
    reverse_proxy 127.0.0.1:8000 {
        header_up X-AtGlance-Proxy builtin
    }
}

:80 {
    import atglance_upstream
}

:443 {
    tls {
        get_certificate http http://127.0.0.1:8000/api/internal/domain/certificate
        on_demand
        issuer acme
        issuer internal
    }
    import atglance_upstream
}
```

- `issuer acme` gets a Let's Encrypt certificate for a public name.
- For private names (such as `.internal`), ACME fails and Caddy falls back to
  `issuer internal`: its own CA, stored in
  `/app/storage/caddy/pki/authorities/local/root.crt`.
- Certificates live on the `app-storage` volume, so they survive when the
  container is recreated.
- **Order at each handshake:**
  1. Caddy asks `/api/internal/domain/certificate`. In mode `custom` it gets
     the organisation's certificate and uses it. That endpoint always has a
     certificate for the domain in this mode (section 10), so Caddy never
     falls through to its issuers.
  2. In every other mode that endpoint answers `204`, and Caddy moves on to
     on-demand issuing, which `tls-allowed` gates.
- **Verify during implementation:**
  - how long Caddy caches a certificate returned by `get_certificate`, so the
    docs can say when a renewed upload takes effect;
  - that the `204` fallback behaves as described, on the Caddy version pinned
    in the image.
  - If the cache holds certificates until they expire, the Upload and Remove
    actions write a marker file, and the entrypoint's Caddy wrapper restarts
    Caddy when the marker changes. Tested manually (section 14).

**Entrypoint** (`docker/entrypoint.sh`), app role only:
- When `ATGLANCE_PROXY=builtin`, run `caddy start --config /etc/caddy/Caddyfile`
  before `exec "$@"`.
- If Caddy fails to start, log `atglance: built-in proxy failed to start` and
  continue. The app on `8000` must still start.
- If the variable is not set, nothing changes.

**Opt-in override file** (`docker-compose.domain.yml`, shipped next to
`docker-compose.yml`; `install.sh` downloads it but does not use it):

```yaml
services:
  app:
    environment:
      ATGLANCE_PROXY: builtin
    ports:
      - "${HTTP_PORT:-80}:80"
      - "${HTTPS_PORT:-443}:443"
```

**CA download.** `GET /admin/settings/domain/ca.crt` (super admin and admin)
streams `root.crt` when the file exists. Otherwise it answers `404` with
"No internal certificate has been issued yet. Open the HTTPS URL once."

## 12. Errors and edge cases

| Case | Behaviour |
|---|---|
| Port 80/443 already in use on the host | The compose override fails to start the app, and Docker prints the error. The card's step 1 checks for this; step 4 is the undo command. Installs are not affected. |
| Caddy fails inside the container | The entrypoint logs it and the app keeps running on `8000`. The status stays "Not detected". |
| DNS not set up yet | The Check button says "Does not resolve". `http://IP:8000` works. |
| HTTPS set to "builtin" but no proxy running | The option is disabled until a `builtin` proxy is detected. If the proxy stops later, the redirect applies only to the domain host, and the IP stays reachable. |
| Plugin switched off | Redirect, secure cookies and certificate approval stop. `APP_URL` goes back to `http://<server IP>`. The stored domain is kept. |
| Let's Encrypt rate limits or unreachable | Caddy falls back to the internal CA. The browser warns until the CA is trusted. |
| Domain DNS or certificate broken | Users open `http://IP:8000` or `atglance.internal`. Neither is redirected, so the admin can fix the setting from there. |
| HTTPS switched off after being on | The redirect was a `302`, so browsers are not stuck on `https://`. `http://<domain>` works immediately. |
| Uploaded certificate expires | Warning 30 days before; error banner after. Caddy keeps serving the expired certificate, so browsers warn, until a new one is uploaded. No silent switch to another issuer. |
| Uploaded key does not match, wrong password, wrong domain | The upload is rejected with the reason. The previous certificate stays in use. |
| Spoofed `X-Forwarded-*` from outside | Only the private ranges are trusted. The detection records only what it sees. The worst case is a wrong status label, and the admin can see that. |

## 13. Code layout

| File | Change |
|---|---|
| `app/Support/DomainSettings.php` | New. Reads and writes the `domain` settings, derives the mode, and builds the access URL, DNS line and hosts line. |
| `app/Http/Controllers/DomainSettingsController.php` | New. Plugin toggle, Access URL save, Check, CA download. |
| `app/Http/Controllers/DomainTlsController.php` | New. The `tls-allowed` and `certificate` endpoints. |
| `app/Support/CustomCertificate.php` | New. Parses PEM or PKCS#12, validates the key match, domain and dates, and stores and reads the encrypted bundle. |
| `app/Http/Middleware/DetectFrontProxy.php` | New |
| `app/Http/Middleware/EnforceDomainHttps.php` | New |
| `app/Support/EnvFile.php` | New. Moves `updateEnv()` out of `InstallerController` so the installer and the domain settings share it. |
| `bootstrap/app.php` | Trusted proxies, and the two middlewares on the web group |
| `routes/web.php` | New admin routes |
| `routes/api.php` | The two `/api/internal/domain/*` routes |
| `app/Http/Controllers/AdminDashboardController.php` | Remove the alias fields from `updateSiteSettings()` and `resolveApplicationIpAddress()`. They move to `DomainSettingsController`. |
| `app/Http/Controllers/InstallerController.php` | Remove the domain and HTTPS fields; use `EnvFile` |
| `resources/views/admin/settings.blade.php` | Plugins card; Site tab Access URL section; Info tab rows |
| `resources/views/install/index.blade.php`, `install/info.blade.php` | Remove the fields; add the note |
| `Dockerfile`, `docker/Caddyfile`, `docker/entrypoint.sh`, `docker-compose.domain.yml`, `install.sh` | Built-in proxy (opt-in) |
| `docs/custom-domain.md` | New operator guide. Linked from the `README.md`, `INSTALLATION.md` and `CLAUDE.md` doc lists |

## 14. Testing

**Feature tests** (`tests/Feature/CustomDomainTest.php`):
- `tls-allowed`: `200` for the configured domain with `builtin` mode from
  `127.0.0.1`. `404` when:
  - the name is different;
  - the mode is not `builtin`;
  - the plugin is off;
  - the request does not come from `127.0.0.1`.
- Site tab: the Access URL fields are disabled while the plugin is off, and
  enabled when it is on.
- Save:
  - updates `APP_URL` to `https://<domain>` or `http://<domain>`;
  - clearing the domain restores `http://<ip>`;
  - rejects a domain with a scheme or a path.
- Redirect: `http` to the domain with HTTPS on gives `301` to `https`. The same
  request to the IP host is not redirected.
- Detection:
  - `X-AtGlance-Proxy: builtin` from `127.0.0.1` records `builtin`;
  - `X-Forwarded-Proto: https` records `platform https`;
  - no headers records nothing.
- Check: with the resolver faked, the three result messages.
- Access paths (section 1.1). With HTTPS on for `ops.eu.acme.com`:
  - requests to the IP, `localhost`, `atglance.internal` and `IP:8000` get
    `200`, not a redirect;
  - `http://ops.eu.acme.com` gets `302` to `https://`;
  - a login on `atglance.internal` sets a host-only, non-secure cookie, and
    the page links use `atglance.internal`;
  - a request with `X-Forwarded-Proto: https` gets a secure cookie.
- Domain validation: `acme.io`, `atglance.acme.com` and `ops.eu.acme.com` are
  accepted. `http://x.com`, `x.com/path`, `x.com:443`, `10.0.0.5`, `-a.com`
  and a single label are refused. A trailing dot is removed.
- `tls-allowed` returns `200` for `atglance.internal` in every mode while the
  plugin is on, and `404` for an unknown name.
- Own certificate. Fixtures are generated in the test with `openssl_pkey_new`
  and `openssl_csr_sign`.
  - Accepted: a valid PEM pair; a PFX with the right password; a wildcard
    certificate for the domain.
  - Rejected, each with its message: a mismatched key, a wrong password, a
    different domain, an expired certificate.
  - `certificate` endpoint:
    - `200` with the PEM bundle in mode `custom` from `127.0.0.1`;
    - `204` in other modes, for other names, and from other IPs.
  - The `certificate` endpoint answers `204` for a request from `127.0.0.1`
    that carries `X-Forwarded-For` or `X-AtGlance-Proxy`: the key-leak guard.
  - Removing the certificate switches the mode to `off`.
  - A domain change that the certificate does not cover is refused.
  - The stored value is encrypted, and the key does not appear in plain text
    in the database.
- Installer: the form no longer has domain or HTTPS fields, and install still
  succeeds.
- Existing tests stay green.

**Manual, before release:**
1. Fresh install with the default compose file. It behaves exactly as today,
   and only 8000 and 8002 are published.
2. VM:
   - enable the plugin and run the override;
   - set `atglance.internal` with built-in HTTPS and a hosts entry;
   - trust the CA. `https://atglance.internal` loads, `http://` redirects, and
     `http://IP:8000` still loads without a redirect.
   - Switch to "Use my own certificate" and upload a certificate from a test
     organisation CA. The browser shows that certificate, and Caddy's storage
     has no new certificate for the domain.
   - Upload a renewed certificate. Note when the new one is served (the cache
     check from section 11).
3. Run the override with port 80 in use: the documented Docker error. The undo
   command restores the default.
4. CLI through Kong `:8002`: unchanged.
5. Build the image for amd64 and arm64.

## 15. Out of scope

- Switching the default server from `artisan serve` to FrankenPHP or php-fpm.
  That is a separate hardening task.
- Serving Kong (`:8002`) behind the domain, or putting the CLI on a
  domain-based URL. The CLI port is hard-coded.
- More than one domain, or wildcard domains.
- Automatic renewal of uploaded certificates (for example ACME against the
  organisation's own CA). Renewal is a new upload.
- Separate certificates per host, or client certificates (mTLS).
