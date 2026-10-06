# MCP Server

AtGlance ships a read-only [MCP](https://modelcontextprotocol.io) server in `mcp/` (Python).
AI clients such as Claude Desktop or Claude Code use it to answer questions from the
console's data: systems, config backups, AI reviews, vulnerabilities and dashboard totals.
Install and client setup: `mcp/README.md`.

## How it fits together

Every install runs the MCP server as the `mcp` service (container `ce-atglance-mcp`,
image `atglance/ce-atglance-mcp`). It has no published port: port 8002 serves both
the CLI API and MCP, and Kong picks by path.

```
                          ┌─ /mcp ──────────▶ mcp :8010 (internal) ──▶ app :8000 GET /api/mcp/*
AI client / atglance CLI ─┤  Kong :8002
                          └─ everything else ─▶ app :8000 (CLI API, unchanged)
```

Connect an AI client to `http://<console-host>:8002/mcp` with the header
`Authorization: Bearer atgla-...`. Claude Code:

```bash
claude mcp add --transport http atglance http://<console-host>:8002/mcp \
  --header "Authorization: Bearer atgla-..."
```

- Kong route `mcp-server-route` (`kong/kong.yml`) has buffering off and a 300 s read timeout,
  because MCP streams responses (SSE).
- The container accepts only the Host `mcp:8010` (`ATGLANCE_MCP_ALLOWED_HOSTS`), so it answers
  only through the gateway.
- Port 8002 is plain HTTP and the API key is in every request: put HTTPS in front of the
  gateway before exposing it outside your network.
- The MCP server holds no data and no database access. It only calls `GET /api/mcp/*`.
- Every call carries a user's PAT (`Authorization: Bearer atgla-...`). The console answers as that
  user, with the same role and workspace rules as the web pages (`App\Http\Controllers\Concerns\ScopesWorkspaceData`,
  shared with `DashboardController`). Super admins, admins and users can all use it with their own PAT.
- `/api/mcp/*` is served on both ports: the gateway route `mcp-api-route` in `kong/kong.yml`
  (port 8002) and the app (port 8000). Set the MCP server's `ATGLANCE_URL` to either.

## Turning MCP on and off

MCP is off on a new install. The super admin turns it on and off with the **MCP Server (AI tools)** plugin in
**Site Setting > Plugins** (`POST /admin/settings/mcp`). Its **Info** panel shows the server state and the MCP URLs.

While MCP is on, every user gets an **MCP** link in the top bar to `/connect-ai` (route `mcp.connect`,
`McpConnectController`, 404 while off). It has the full client setup: Claude Code, Claude Desktop,
VS Code, GitHub Copilot, n8n, other MCP apps (generic config, Python and TypeScript SDK code), curl,
the tool list and a troubleshooting table. Content: `resources/views/partials/mcp-connect.blade.php`,
addresses and snippets: `App\Support\McpConnect`.

- MCP URL: `http://<domain>:8002/mcp` if a custom domain is set, else the private IP.
- Fallback: `http://<private_ip>:8002/mcp`, from the server IP in Site Configuration (or the address the
  page was opened with, if that is a private IP). Users can switch every example to it. If the IP is unknown
  (e.g. saved as `127.0.0.1`), the page says how to find it and the plugin's Info panel asks the super admin to set it.

- The setting is `admin_settings.mcp_enabled` (`'true'`/`'false'`, migration
  `2026_10_05_000000_add_mcp_enabled_setting`). Code: `App\Support\McpControl`.
- **On/off starts or stops the `ce-atglance-mcp` container right away.** The console does this through the
  `mcp-control` service (container `ce-atglance-mcp-control`, `nginx:alpine`, config inline in
  `docker-compose.yml` under `configs:`). It holds the Docker socket but forwards only three fixed calls;
  every other request gets 403:

  | mcp-control path | Docker API call |
  |---|---|
  | `POST /mcp/start` | `POST /containers/ce-atglance-mcp/start` |
  | `POST /mcp/stop` | `POST /containers/ce-atglance-mcp/stop?t=5` |
  | `GET /mcp/status` | `GET /containers/ce-atglance-mcp/json` |

  It has no published port; the app reaches it at `ATGLANCE_MCP_CONTROL_URL` (default `http://mcp-control:2375`).
- The scheduler runs `mcp:manage` every minute and starts or stops the container if it does not match the
  setting, e.g. after `docker compose up -d` started it while MCP is off.
- If `mcp-control` is not reachable (no Docker socket, Kubernetes, ECS), the setting is still saved, the tab
  says the state is unknown, and you start or stop the MCP service on that platform yourself.
- While MCP is off, `<host>:8002/mcp` returns 500/503 from Kong. After turning it on, allow a few seconds:
  Kong re-resolves container names every 5 seconds (`KONG_DNS_VALID_TTL`, `kong/Dockerfile`), because a
  restarted container can get a new IP.

## Modes

| Mode | Start | Whose PAT |
|---|---|---|
| stdio (local) | The MCP client runs `atglance-mcp` | `ATGLANCE_PAT` on the user's machine |
| HTTP (shared, default in every install) | The `mcp` compose service behind Kong: `<host>:8002/mcp` | Each client's own `Authorization` header; `ATGLANCE_PAT` is ignored |

## Console API

All routes: `GET`, `auth.pat`, `throttle:120,1`, in `composer/routes/api.php`, controller
`App\Http\Controllers\Api\McpController`. Optional `workspace_id` narrows to one workspace
(`0` = the user's systems with no workspace); without it, every workspace the user can see.

| Route | Returns |
|---|---|
| `/api/mcp/me` | User, role, visible workspace ids |
| `/api/mcp/dashboard` | Totals, vulnerability stats, 7-day AI trend |
| `/api/mcp/workspaces` | Workspaces with tags, counts, your role, schedules |
| `/api/mcp/workspaces/{id}/ai-progress` | Latest Vulnerability Checks run |
| `/api/mcp/systems` | Systems with service, backup and AI issue counts (`search`, `status`, `limit`) |
| `/api/mcp/config-files` | Latest version of each file by default (`system_id`, `service`, `ai_status`, `all_versions`, `limit`) |
| `/api/mcp/config-files/{id}` | Content with secrets masked, latest AI review with findings |
| `/api/mcp/config-files/{id}/versions` | Version history with AI status |
| `/api/mcp/vulnerabilities` | Latest reviews with error or warning (`severity`, `limit`) |

- A file or workspace the user cannot see returns 404, the same as a missing one.
- Config content always has secret-looking values masked (`ConfigAiValidator::maskSecrets()`), for every role,
  because the answer goes to an AI client.
- `limit` is at most 200.

## Out of scope for now

- Write tools (run a check, run a backup): the API is read-only.
- Per-token read-only flag or MCP-only tokens: any active PAT works.
- MCP calls are GET requests, so the activity log (which records writes) does not record them.

## Tests

- Console: `composer/tests/Feature/McpApiTest.php`.
- MCP server: `pytest mcp/tests`.
