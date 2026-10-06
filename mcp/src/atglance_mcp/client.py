"""HTTP client for the console's read-only /api/mcp/* endpoints."""

from __future__ import annotations

from typing import Any

import httpx
from mcp.server.mcpserver.exceptions import ToolError


class AtGlanceError(ToolError):
    """An error with a message that is safe to show to the AI client.

    A ToolError, so the MCP SDK passes the message on instead of a generic one.
    """


class AtGlanceClient:
    """Calls the console as the user who owns the PAT.

    `base_url` is the console's gateway (``http://host:8002``) or app
    (``http://host:8000``); both serve ``/api/mcp/*``.
    """

    def __init__(self, base_url: str, token: str, *, timeout: float = 30.0, transport: httpx.AsyncBaseTransport | None = None):
        if not base_url:
            raise AtGlanceError("ATGLANCE_URL is not set, e.g. http://your-console:8002")
        if not token.startswith("atgla-"):
            raise AtGlanceError("No valid AtGlance API key. Create one in the console under Settings > API Keys (it starts with 'atgla-').")

        self._client = httpx.AsyncClient(
            base_url=base_url.rstrip("/") + "/api/mcp",
            headers={"Authorization": f"Bearer {token}", "Accept": "application/json"},
            timeout=timeout,
            transport=transport,
        )

    async def get(self, path: str, params: dict[str, Any] | None = None) -> Any:
        clean = {k: v for k, v in (params or {}).items() if v is not None and v != ""}
        try:
            response = await self._client.get(path, params=clean)
        except httpx.HTTPError as exc:
            raise AtGlanceError(f"Cannot reach the AtGlance console: {exc.__class__.__name__}") from exc

        if response.status_code == 401:
            raise AtGlanceError("The console rejected the API key (missing, revoked or expired).")
        if response.status_code == 404:
            raise AtGlanceError("Not found, or you do not have access to it.")
        if response.status_code == 422:
            raise AtGlanceError(f"Invalid request: {response.json().get('message', response.text)}")
        if response.status_code == 429:
            raise AtGlanceError("Too many requests to the console. Wait a minute and try again.")
        if response.status_code >= 400:
            raise AtGlanceError(f"The console returned HTTP {response.status_code}.")

        return response.json()

    async def aclose(self) -> None:
        await self._client.aclose()
