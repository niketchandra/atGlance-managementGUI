import httpx
import pytest

from atglance_mcp.client import AtGlanceClient, AtGlanceError

TOKEN = "atgla-abcdefghijklmnopqrs"


def make_client(handler) -> AtGlanceClient:
    return AtGlanceClient("http://console:8002/", TOKEN, transport=httpx.MockTransport(handler))


@pytest.mark.anyio
async def test_sends_pat_and_drops_empty_params():
    seen = {}

    def handler(request: httpx.Request) -> httpx.Response:
        seen["url"] = str(request.url)
        seen["auth"] = request.headers["authorization"]
        return httpx.Response(200, json={"data": []})

    client = make_client(handler)
    assert await client.get("/systems", {"workspace_id": 3, "search": None, "status": ""}) == {"data": []}
    await client.aclose()

    assert seen["url"] == "http://console:8002/api/mcp/systems?workspace_id=3"
    assert seen["auth"] == f"Bearer {TOKEN}"


@pytest.mark.anyio
@pytest.mark.parametrize("status, message", [
    (401, "rejected the API key"),
    (404, "do not have access"),
    (429, "Too many requests"),
    (500, "HTTP 500"),
])
async def test_errors_are_readable(status, message):
    client = make_client(lambda request: httpx.Response(status, json={"message": "x"}))
    with pytest.raises(AtGlanceError, match=message):
        await client.get("/me")
    await client.aclose()


def test_rejects_missing_settings():
    with pytest.raises(AtGlanceError, match="ATGLANCE_URL"):
        AtGlanceClient("", TOKEN)
    with pytest.raises(AtGlanceError, match="API key"):
        AtGlanceClient("http://console:8002", "not-a-pat")


@pytest.fixture
def anyio_backend():
    return "asyncio"
