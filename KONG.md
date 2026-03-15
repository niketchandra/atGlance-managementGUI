# Kong config guide

This project runs Kong in DB-less mode and loads kong/kong.yml at startup.

## How it works
- Kong runs as a separate container.
- The declarative config is mounted to /kong/declarative/kong.yml.
- Kong reads the file on startup and builds routes and services.

## kong/kong.yml structure
- services:
  - users-service: proxies to http://api:8000 (Laravel app in composer/)
    - routes:
      - /users (GET, POST, PUT, DELETE)
      - /products (GET, POST, PUT, DELETE)
    - plugin: rate-limiting (minute: 4)
  - auth-service: proxies to http://api:8000
    - routes:
      - /auth/login (POST)
      - /auth/logout (POST)
  - files-service: proxies to http://api:8000
    - routes:
      - /files/upload (POST)
      - /files (GET)

## Adding new routes (examples)
Below are examples for adding product APIs and file upload/download APIs to kong/kong.yml.

### Example: products routes
Add to the users-service routes (or create a new products-service if you prefer):

```yaml
  - name: users-service
    url: http://api:8000
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
    url: http://api:8000
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
docker compose exec api php artisan migrate --force
```

3. Expose the new route in kong/kong.yml under an existing service or a new service.
4. Restart Kong to load changes:

```bash
docker compose -f docker-compose.yml -f docker-compose-kong.yml restart kong
```

## Reloading config
Kong does not auto-reload this file. After edits, restart the container:

```bash
docker compose -f docker-compose.yml -f docker-compose-kong.yml restart kong
```

## Common issues
- "no Route matched": Kong is running but has not reloaded the updated config.
- Rate limit errors (429): the rate-limiting plugin is set to 4 requests/min.
