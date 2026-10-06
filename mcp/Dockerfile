FROM python:3.12-slim

WORKDIR /srv/atglance-mcp
COPY pyproject.toml README.md ./
COPY src ./src
RUN pip install --no-cache-dir . && useradd --system --no-create-home mcp

USER mcp
ENV ATGLANCE_MCP_HOST=0.0.0.0 \
    ATGLANCE_MCP_PORT=8010 \
    PYTHONUNBUFFERED=1
EXPOSE 8010

HEALTHCHECK --interval=10s --timeout=5s --retries=10 \
    CMD python -c "import urllib.request; urllib.request.urlopen('http://127.0.0.1:8010/health', timeout=4)"

# Shared HTTP mode: each client sends its own "Authorization: Bearer atgla-..." header.
CMD ["atglance-mcp", "--http"]
