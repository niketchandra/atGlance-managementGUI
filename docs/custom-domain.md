# Custom domain and HTTPS

Open the console on your own domain or subdomain (for example
`https://atglance.acme.com`), with or without HTTPS. You set it up after
installation. The installer does not ask for a domain, and a default install
publishes only ports 8000 and 8002.

## Addresses that always work

| Address | Notes |
|---|---|
| `http://<server IP>:8000` | Always works and is never redirected. Use it to fix a wrong setting. |
| `http://atglance.internal:8000` | Works once the name points to the server (DNS or hosts file). Never redirected. |
| `http(s)://atglance.internal` | With the built-in proxy. HTTPS uses the built-in CA. |
| `http(s)://<your domain>` | After you set the domain on the Site tab. |

Each address has its own login session.

## 1. Enable the plugin

Go to Admin Settings > Plugins > **Custom Domain & HTTPS** and click **Enable**
(super admin only). Nothing changes on the server yet.

## 2. Put a proxy in front

**VM / Docker Compose (built-in proxy):**

1. Check that ports 80 and 443 are free:
   `sudo ss -ltn '( sport = :80 or sport = :443 )'`. No output means both are free.
2. In the install folder (default `/opt/atglance`), run:
   `docker compose -f docker-compose.yml -f docker-compose.domain.yml up -d`.
   If a port is taken, set `HTTP_PORT` or `HTTPS_PORT` in `.env` first. If you
   don't, Docker stops with "port is already allocated".
3. Open `http://<server IP>` (port 80) once. The plugin status changes to
   "Built-in proxy detected".
4. To undo it, run `docker compose up -d`, without the second file.

The built-in proxy is Caddy, shipped inside the app image. It only runs when
`docker-compose.domain.yml` sets `ATGLANCE_PROXY=builtin`. If it fails to
start, the console keeps running on port 8000.

**AWS ECS:** set up an ALB listener on 443 with an ACM certificate, forwarding
to container port 8000. The atglance CLI still needs port 8002 (Kong); route it
separately.

**Azure Container Apps:** set the ingress target port to 8000, and add a custom
domain with a managed certificate.

**Kubernetes:** create an Ingress to service port 8000, with cert-manager for
TLS.

For these three platforms, choose "Handled by my platform" as the HTTPS option
in step 3.

## 3. Set the domain

Go to Admin Settings > Site > **Access URL**:

- **Domain:** any domain or subdomain, without `http://` and without a port.
  Avoid `.local`, which is reserved for mDNS; use `.internal` or `.lan`.
- **Server IP:** the address users' machines reach the server on. It is used
  for the DNS record the page shows.
- **HTTPS:**
  - **Off:** plain `http://`.
  - **Automatic certificate** (built-in proxy): Caddy gets the certificate on
    the first HTTPS visit and renews it by itself.
    - A public name reachable from the internet gets a Let's Encrypt
      certificate.
    - Any other name, such as `.internal` or an internal-only subdomain, gets a
      certificate from the built-in CA. Download the CA certificate from the
      Site tab and trust it once on each machine.
  - **Use my own certificate** (built-in proxy): upload a PEM certificate (with
    its chain) and key, or a `.pfx`/`.p12` file.
    - The upload is refused if the key does not match, the certificate does
      not cover the domain (the exact name or a one-level wildcard), or it has
      expired.
    - To renew, upload again. New connections use the new certificate within
      seconds.
    - The console warns 30 days before the certificate expires.
  - **Handled by my platform:** the load balancer or ingress does TLS.

  The two built-in options unlock once the built-in proxy has been detected.

Then create the DNS record the page shows (`<domain>  A  <server IP>`). Use
**Check DNS** to confirm it; that check uses the server's DNS view. To test on
one machine first, add the hosts-file line the page shows.

## How it works

- **Changes apply at request time.** The app decides everything when a request
  arrives. Changing the domain, HTTPS mode or certificate needs no restart.
  - When the domain or HTTPS mode changes, the app touches
    `storage/caddy/reload`, and the built-in proxy restarts itself within about
    5 seconds. Caddy serves a certificate it already holds before asking the
    app, so the restart is what switches it over.
- **Redirects.** Only the configured domain is redirected to HTTPS: `302`, GET
  and HEAD only, and only for requests that came through a proxy. IPs,
  `localhost`, `atglance.internal` and direct hits on port 8000 are never
  redirected.
- **Session cookie.** It is host-only and marked `secure` only on HTTPS
  requests.
- **`APP_URL`.** Saving the domain sets `APP_URL`, which emails and background
  jobs use. Disabling the plugin sets it back to `http://<server IP>`.
- **How the built-in proxy talks to the app:**
  - Before it issues a certificate, it asks
    `/api/internal/domain/tls-allowed`, which allows only the configured domain
    and `atglance.internal`.
  - It gets the uploaded certificate from `/api/internal/domain/certificate`.
  - Both endpoints answer only Caddy itself: local requests without
    `X-Forwarded-For` or `X-AtGlance-Proxy` headers.
  - Caddy also answers `404` for `/api/internal/*` from outside.
- **Trusted proxy ranges:** `127.0.0.1, ::1, 10.0.0.0/8, 172.16.0.0/12,
  192.168.0.0/16`. Override them with `ATGLANCE_TRUSTED_PROXIES`
  (comma-separated).
- **Storage.**
  - Settings live in `admin_settings`: `domain_plugin_enabled`,
    `site_domain_alias`, `site_domain_alias_ip`, `site_https_mode`,
    `custom_cert` (encrypted with `APP_KEY`), and `proxy_seen`.
  - Certificates and the built-in CA live in `storage/caddy/` on the
    app-storage volume.

## Not supported

- Wildcard certificates from Let's Encrypt, and Let's Encrypt for internal-only
  names. Both need a DNS-01 challenge with DNS provider credentials. Upload
  your own certificate instead, or use a platform proxy.
- More than one domain at a time.
- Kong (port 8002) behind the domain. The CLI port is fixed.

Code: `App\Support\DomainSettings`, `App\Support\CustomCertificate`,
`App\Http\Controllers\DomainSettingsController`,
`App\Http\Controllers\DomainTlsController`,
`App\Http\Middleware\EnforceDomainHttps`,
`App\Http\Middleware\DetectFrontProxy`, `docker/Caddyfile`,
`docker/entrypoint.sh`, `docker-compose.domain.yml`.
