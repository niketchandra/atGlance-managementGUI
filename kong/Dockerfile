# AtGlance Community Edition - API gateway image.
# Kong in DB-less mode with the AtGlance route config baked in, so a deploy
# needs no bind-mounted files.
FROM kong:3.6

COPY kong.yml /kong/declarative/kong.yml

ENV KONG_DATABASE=off \
    KONG_DECLARATIVE_CONFIG=/kong/declarative/kong.yml \
    KONG_PROXY_LISTEN="0.0.0.0:8002" \
    KONG_ADMIN_LISTEN="127.0.0.1:8001"

EXPOSE 8002
