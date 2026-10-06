from types import SimpleNamespace

import pytest

from atglance_mcp import server
from atglance_mcp.client import AtGlanceError


def ctx_with(headers):
    return SimpleNamespace(headers=headers)


def test_stdio_mode_uses_env_pat(monkeypatch):
    monkeypatch.setattr(server, "_HTTP_MODE", False)
    monkeypatch.setenv("ATGLANCE_PAT", "atgla-fromenv")
    assert server._token(ctx_with(None)) == "atgla-fromenv"


def test_http_mode_uses_the_callers_header_not_the_env(monkeypatch):
    monkeypatch.setattr(server, "_HTTP_MODE", True)
    monkeypatch.setenv("ATGLANCE_PAT", "atgla-server-owner")
    assert server._token(ctx_with({"authorization": "Bearer atgla-caller"})) == "atgla-caller"

    with pytest.raises(AtGlanceError, match="Authorization"):
        server._token(ctx_with({}))


def test_allowed_hosts_setting(monkeypatch):
    monkeypatch.delenv("ATGLANCE_MCP_ALLOWED_HOSTS", raising=False)
    assert server._transport_security() is None

    monkeypatch.setenv("ATGLANCE_MCP_ALLOWED_HOSTS", "mcp:8010, localhost:8002")
    assert server._transport_security().allowed_hosts == ["mcp:8010", "localhost:8002"]

    monkeypatch.setenv("ATGLANCE_MCP_ALLOWED_HOSTS", "*")
    assert server._transport_security().enable_dns_rebinding_protection is False


@pytest.mark.anyio
async def test_all_tools_are_registered_and_read_only():
    tools = {tool.name for tool in await server.mcp.list_tools()}
    assert tools == {
        "whoami", "get_dashboard", "list_workspaces", "list_systems", "list_config_files",
        "get_config_file", "list_config_versions", "list_vulnerabilities", "get_ai_check_progress",
    }


@pytest.fixture
def anyio_backend():
    return "asyncio"
