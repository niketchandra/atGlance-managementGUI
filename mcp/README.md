# AtGlance MCP server

Read-only [MCP](https://modelcontextprotocol.io) server for the AtGlance management console.
It lets an AI client (Claude Desktop, Claude Code, or any MCP client) answer questions like
"which servers have AI errors?" or "show me the sshd config on web-01" from your console's data.

- **Read only.** It cannot change anything in the console.
- **Your access only.** Every answer comes from the console as the user who owns the API key,
  with that user's role (super admin, admin, user) and workspaces.
- **Secrets masked.** Secret-looking values in config files are shown as `********`.

## Quickest way: use the one in your console

Every AtGlance install already runs this server behind the API gateway. Nothing to install:

```bash
claude mcp add --transport http atglance http://<console-host>:8002/mcp \
  --header "Authorization: Bearer atgla-xxxxxxxxxxxxxxxxxxx"
```

The rest of this page is for running it yourself (locally over stdio, or as a separate server).

## What you need

- Python 3.10 or newer
- An AtGlance API key (PAT): in the console, **Settings > API Keys > Create**. It starts with `atgla-`.
- The console address. Either port works:
  - the API gateway, `http://<console-host>:8002` (recommended), or
  - the app directly, `http://<console-host>:8000`.

## Install

```bash
pip install ./mcp          # from a clone of the atGlance-managementGUI repository
```

## Use it locally (stdio)

The MCP client starts the server on your machine. Your API key stays on your machine.

**Claude Code:**

```bash
claude mcp add atglance \
  --env ATGLANCE_URL=http://console.example.com:8002 \
  --env ATGLANCE_PAT=atgla-xxxxxxxxxxxxxxxxxxx \
  -- atglance-mcp
```

**Claude Desktop** (`claude_desktop_config.json`):

```json
{
  "mcpServers": {
    "atglance": {
      "command": "atglance-mcp",
      "env": {
        "ATGLANCE_URL": "http://console.example.com:8002",
        "ATGLANCE_PAT": "atgla-xxxxxxxxxxxxxxxxxxx"
      }
    }
  }
}
```

## Run it as a shared HTTP server

One server for a whole team. Each person's MCP client sends their own API key; the
server never uses a key of its own in this mode.

```bash
ATGLANCE_URL=http://console.example.com:8002 atglance-mcp --http --host 0.0.0.0 --port 8010
```

Or with Docker:

```bash
docker build -t atglance-mcp ./mcp
docker run -d --name atglance-mcp -p 8010:8010 \
  -e ATGLANCE_URL=http://console.example.com:8002 atglance-mcp
```

Clients connect to `http://<mcp-host>:8010/mcp` with the header
`Authorization: Bearer atgla-...`. For example, in Claude Code:

```bash
claude mcp add --transport http atglance http://mcp.example.com:8010/mcp \
  --header "Authorization: Bearer atgla-xxxxxxxxxxxxxxxxxxx"
```

Put HTTPS in front of it (a reverse proxy) when it is reachable from outside your network:
the API key travels in every request.

## Tools

| Tool | Answers |
|---|---|
| `whoami` | Your name, role and visible workspaces |
| `get_dashboard` | Totals, vulnerability counts with weekly change, last 7 days of AI results |
| `list_workspaces` | Workspaces with tags, members, systems and schedules |
| `list_systems` | Servers with services, backups, AI errors and warnings |
| `list_config_files` | Config backups (latest versions by default) with AI status |
| `get_config_file` | One config's content (secrets masked) and latest AI review |
| `list_config_versions` | Version history of one config file |
| `list_vulnerabilities` | Configs with AI errors or warnings, with findings |
| `get_ai_check_progress` | Progress of a workspace's Vulnerability Checks run |

Most tools take an optional `workspace_id`. Leave it out for every workspace you can see.

## Settings

| Variable | Used for |
|---|---|
| `ATGLANCE_URL` | Console address (`:8002` gateway or `:8000` app) |
| `ATGLANCE_PAT` | Your API key, stdio mode only |
| `ATGLANCE_MCP_HOST`, `ATGLANCE_MCP_PORT` | HTTP mode listen address (default `127.0.0.1:8010`) |

## Develop

```bash
pip install -e "./mcp[test]"
pytest mcp/tests
```

The console side is `GET /api/mcp/*` in `composer/routes/api.php`
(`App\Http\Controllers\Api\McpController`). See `docs/mcp.md`.
