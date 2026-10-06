# AtGlance Community Edition - API gateway image.
# Kong in DB-less mode with the AtGlance route config baked in, so a deploy
# needs no bind-mounted files.
FROM kong:3.6

COPY kong.yml /kong/declarative/kong.yml

# DNS TTLs: containers get a new IP when restarted (e.g. the console turning MCP
# off and on), and Docker DNS answers with TTL 600, so re-resolve every few seconds.
ENV KONG_DATABASE=off \
    KONG_DECLARATIVE_CONFIG=/kong/declarative/kong.yml \
    KONG_PROXY_LISTEN="0.0.0.0:8002" \
    KONG_ADMIN_LISTEN="127.0.0.1:8001" \
    KONG_DNS_VALID_TTL=5 \
    KONG_DNS_STALE_TTL=0 \
    KONG_DNS_NOT_FOUND_TTL=5

EXPOSE 8002
