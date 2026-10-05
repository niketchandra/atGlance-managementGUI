"""AtGlance MCP server: read-only tools over the console's /api/mcp/* API.

Two ways to run it:

- stdio (local, one user): set ATGLANCE_URL and ATGLANCE_PAT, and let the MCP
  client (Claude Desktop, Claude Code, ...) start ``atglance-mcp``.
- HTTP (shared): ``atglance-mcp --http``. Each MCP client sends its own PAT as
  ``Authorization: Bearer atgla-...``; the server forwards it to the console.
  ATGLANCE_PAT is not used in this mode, so one user never acts as another.

Every answer comes from the console as the PAT's user, with that user's
role and workspace access.
"""

from __future__ import annotations

import argparse
import os
from typing import Any, Literal

from mcp.server.mcpserver import Context, MCPServer
from mcp.server.transport_security import TransportSecuritySettings
from starlette.requests import Request
from starlette.responses import JSONResponse

from . import __version__
from .client import AtGlanceClient, AtGlanceError

INSTRUCTIONS = """\
AtGlance is a server-health console. It stores backups of service configuration
files (sshd, nginx, systemd units, ...) uploaded from Linux servers, groups the
servers ("systems") into workspaces, and reviews the configs with AI for
vulnerabilities (status error, warning, ok).
All tools are read-only and answer as the user who owns the API key.
Start with get_dashboard or list_workspaces; use workspace_id to narrow.
Config content has secret-looking values masked as ********.
"""

mcp = MCPServer(name="AtGlance", instructions=INSTRUCTIONS, version=__version__)

_HTTP_MODE = False


def _token(ctx: Context) -> str:
    """The PAT for this call: the client's Authorization header in HTTP mode, else ATGLANCE_PAT."""
    if _HTTP_MODE:
        header = (ctx.headers or {}).get("authorization", "")
        if not header.lower().startswith("bearer "):
            raise AtGlanceError("Send your AtGlance API key as 'Authorization: Bearer atgla-...'.")
        return header[7:].strip()

    return os.environ.get("ATGLANCE_PAT", "")


async def _get(ctx: Context, path: str, params: dict[str, Any] | None = None) -> Any:
    client = AtGlanceClient(os.environ.get("ATGLANCE_URL", ""), _token(ctx))
    try:
        return await client.get(path, params)
    finally:
        await client.aclose()


@mcp.tool(title="Who am I")
async def whoami(ctx: Context) -> dict:
    """The user behind the API key: name, role (super_admin, admin, user) and visible workspace ids."""
    return await _get(ctx, "/me")


@mcp.tool(title="Dashboard summary")
async def get_dashboard(ctx: Context, workspace_id: int | None = None) -> dict:
    """Totals for systems, services and config backups, vulnerability counts (errors and
    warnings with week-over-week change), and the last 7 days of AI review results.
    Leave workspace_id empty for every workspace you can see."""
    return await _get(ctx, "/dashboard", {"workspace_id": workspace_id})


@mcp.tool(title="List workspaces")
async def list_workspaces(ctx: Context) -> dict:
    """Workspaces you can see, with tags, member and system counts, your role, and
    whether automatic AI checks and backups are scheduled."""
    return await _get(ctx, "/workspaces")


@mcp.tool(title="List systems")
async def list_systems(
    ctx: Context,
    workspace_id: int | None = None,
    search: str | None = None,
    status: Literal["active", "inactive"] | None = None,
    limit: int = 50,
) -> dict:
    """Registered servers with OS, IP, workspace, and counts of services, config
    backups, AI errors and AI warnings. `search` matches name, IP or tags."""
    return await _get(ctx, "/systems", {"workspace_id": workspace_id, "search": search, "status": status, "limit": limit})


@mcp.tool(title="List config backups")
async def list_config_files(
    ctx: Context,
    workspace_id: int | None = None,
    system_id: int | None = None,
    service: str | None = None,
    ai_status: Literal["error", "warning", "ok", "unknown", "not_checked"] | None = None,
    all_versions: bool = False,
    limit: int = 50,
) -> dict:
    """Stored config files with their latest AI review status. By default only the
    latest version of each file on each system; set all_versions for history."""
    return await _get(ctx, "/config-files", {
        "workspace_id": workspace_id,
        "system_id": system_id,
        "service": service,
        "ai_status": ai_status,
        "all_versions": 1 if all_versions else None,
        "limit": limit,
    })


@mcp.tool(title="Get config file")
async def get_config_file(ctx: Context, config_file_id: int) -> dict:
    """One config file: its content (secrets masked) and its latest AI review with findings."""
    return await _get(ctx, f"/config-files/{config_file_id}")


@mcp.tool(title="Config file versions")
async def list_config_versions(ctx: Context, config_file_id: int) -> dict:
    """Every stored version of the same file on the same system, newest first, with AI status."""
    return await _get(ctx, f"/config-files/{config_file_id}/versions")


@mcp.tool(title="List vulnerabilities")
async def list_vulnerabilities(
    ctx: Context,
    workspace_id: int | None = None,
    severity: Literal["error", "warning"] | None = None,
    limit: int = 50,
) -> dict:
    """Config files whose latest AI review is error (high) or warning (medium), errors
    first, with summary and findings (issue, line, standard, fix)."""
    return await _get(ctx, "/vulnerabilities", {"workspace_id": workspace_id, "severity": severity, "limit": limit})


@mcp.tool(title="AI check progress")
async def get_ai_check_progress(ctx: Context, workspace_id: int) -> dict:
    """Progress of a workspace's latest Vulnerability Checks run: total, reviewed,
    skipped, failed, percent, finished or reset."""
    return await _get(ctx, f"/workspaces/{workspace_id}/ai-progress")


@mcp.custom_route("/health", methods=["GET"], include_in_schema=False)
async def health(request: Request) -> JSONResponse:
    """Liveness for the container healthcheck (HTTP mode)."""
    return JSONResponse({"status": "ok", "version": __version__})


def _transport_security() -> TransportSecuritySettings | None:
    """Host/Origin checks against DNS rebinding.

    ATGLANCE_MCP_ALLOWED_HOSTS: comma-separated Host values to accept, e.g.
    "mcp:8010" behind the Kong gateway. "*" turns the check off (the server is
    then reachable only through the gateway on the internal Docker network).
    Unset: the SDK default for the listen address.
    """
    allowed = os.environ.get("ATGLANCE_MCP_ALLOWED_HOSTS", "").strip()
    if allowed == "":
        return None
    if allowed == "*":
        return TransportSecuritySettings(enable_dns_rebinding_protection=False)

    return TransportSecuritySettings(allowed_hosts=[host.strip() for host in allowed.split(",") if host.strip()])


def main() -> None:
    global _HTTP_MODE

    parser = argparse.ArgumentParser(prog="atglance-mcp", description=__doc__.splitlines()[0])
    parser.add_argument("--http", action="store_true", help="serve MCP over HTTP instead of stdio")
    parser.add_argument("--host", default=os.environ.get("ATGLANCE_MCP_HOST", "127.0.0.1"))
    parser.add_argument("--port", type=int, default=int(os.environ.get("ATGLANCE_MCP_PORT", "8010")))
    args = parser.parse_args()

    if args.http:
        _HTTP_MODE = True
        mcp.run("streamable-http", host=args.host, port=args.port, stateless_http=True, transport_security=_transport_security())
    else:
        mcp.run("stdio")


if __name__ == "__main__":
    main()
