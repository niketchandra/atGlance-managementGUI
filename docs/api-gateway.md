# Kong config guide

This project runs Kong in DB-less mode and loads kong/kong.yml at startup.

## How it works
- Kong runs as a separate container.
- The declarative config is mounted to /kong/declarative/kong.yml.
- Kong reads the file on startup and builds routes and services.

## kong/kong.yml structure
- services:
  - users-service: proxies to http://app:8000 (Laravel app in composer/)
    - routes:
      - /users (GET, POST, PUT, DELETE)
      - /products (GET, POST, PUT, DELETE)
    - plugin: rate-limiting (minute: 4)
  - auth-service: proxies to http://app:8000
    - routes:
      - /auth/login (POST)
      - /auth/logout (POST)
  - files-service: proxies to http://app:8000
    - routes:
      - /files/upload (POST)
      - /files (GET)
  - cli-v1-service: proxies to http://app:8000
    - routes:
      - /api/v1/ (GET, POST), strip_path false
    - The atglance CLI v2 API. The path is the same on the gateway (:8002) and on the app (:8000),
      so the CLI needs only a base URL. Endpoints: docs/api-reference.md, "CLI API v1 (/api/v1)".
    - No gateway plugin; the app rate limits with named limiters: v1-meta (/api/v1/meta, 60/min per IP)
      and v1-force (v1 and legacy force routes, 10/min per IP + email). Behind Kong the app sees the
      gateway's IP for every client, so the email part of the v1-force key is what separates callers
      (see Client IPs behind Kong below).

## Adding new routes (examples)
Below are examples for adding product APIs and file upload/download APIs to kong/kong.yml.

### Example: products routes
Add to the users-service routes (or create a new products-service if you prefer):

```yaml
  - name: users-service
    url: http://app:8000
    routes:
      - name: users-route
        paths:
          - /users
        strip_path: false
        methods: [GET, POST, PUT, DELETE]
      - name: products-route
        paths:
          - /products
        strip_path: false
        methods: [GET, POST, PUT, DELETE]
```

### Example: file upload and download routes
Add these routes so Kong forwards file traffic to the API:

```yaml
  - name: files-service
    url: http://app:8000
    routes:
      - name: files-upload-route
        paths:
          - /files/upload
        strip_path: false
        methods: [POST]
      - name: files-download-route
        paths:
          - /files
        strip_path: false
        methods: [GET]
```

Notes:
- For downloads, /files is a prefix path that matches /files/{file_id}.
- After editing kong/kong.yml, restart Kong to load changes.

## How to add a new API (developer workflow)
1. Create the Laravel API (controller, model, migration, routes) in composer/:
  - Model: composer/app/Models
  - Controller: composer/app/Http/Controllers/Api
  - Routes: composer/routes/api.php
  - Migration: composer/database/migrations
2. Run migrations:

```bash
docker compose exec app php artisan migrate --force
```

3. Expose the new route in kong/kong.yml under an existing service or a new service.
4. Rebuild the gateway image (see Reloading config), then push a new `atglance/ce-atglance-gateway` tag for deployed servers.

## Client IPs behind Kong
Kong proxies to the app over the compose network, so by default Laravel sees Kong's container IP as the
client of every API request. Rate limits (`v1-meta`, `v1-force`) and the IPs in the activity log are then
per gateway, not per client. The app only trusts `X-Forwarded-For` from addresses in
`ATGLANCE_TRUSTED_PROXIES` (default `127.0.0.1,::1`, the built-in proxy; see docs/custom-domain.md).

To see real client IPs, add Kong's Docker network to that list (it replaces the default, so keep the
loopback entries):

```bash
# Subnet of the compose network (project name "atglance")
docker network inspect atglance_default --format '{{(index .IPAM.Config 0).Subnet}}'
# e.g. 172.18.0.0/16, then in the app's .env (on the app-storage volume, /app/.env):
ATGLANCE_TRUSTED_PROXIES=127.0.0.1,::1,172.18.0.0/16
# restart the app so it reads the value
docker compose restart app
```

- Kong adds the caller's address to `X-Forwarded-For`. If another proxy or load balancer sits in front of
  Kong, Kong must trust it too (Kong's `trusted_ips` / `real_ip_header` settings), or the app sees that
  proxy's address instead of the client's.
- Trusting the subnet trusts every container on that network; only put trusted containers on it.
- The subnet can change if the network is recreated; check it again after `docker compose down`.

## Reloading config
Kong config is baked into the `atglance/ce-atglance-gateway` image. Rebuild the gateway after edits:

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build gateway
```

## Common issues
- "no Route matched": Kong is running but has not reloaded the updated config.
- Rate limit errors (429): the rate-limiting plugin is set to 4 requests/min.
