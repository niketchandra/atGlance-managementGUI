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

## Reloading config
Kong config is baked into the `atglance/ce-atglance-gateway` image. Rebuild the gateway after edits:

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build gateway
```

## Common issues
- "no Route matched": Kong is running but has not reloaded the updated config.
- Rate limit errors (429): the rate-limiting plugin is set to 4 requests/min.
