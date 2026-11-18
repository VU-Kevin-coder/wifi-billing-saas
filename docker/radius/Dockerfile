FROM debian:bookworm-slim

RUN apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
        ca-certificates \
        freeradius \
        freeradius-rest \
        gettext-base \
        iproute2 \
    && rm -rf /var/lib/apt/lists/*

COPY docker/radius/clients.conf.template /opt/radius/clients.conf.template
COPY docker/radius/mods-available/rest /etc/freeradius/3.0/mods-available/rest
COPY docker/radius/sites-available/default /opt/radius/default.template
COPY docker/radius/sites-available/dynamic-clients /opt/radius/dynamic-clients.template
COPY docker/radius/entrypoint.sh /usr/local/bin/radius-entrypoint

RUN chmod 0755 /usr/local/bin/radius-entrypoint \
    && ln -sf ../mods-available/rest /etc/freeradius/3.0/mods-enabled/rest

EXPOSE 1812/udp 1813/udp

ENTRYPOINT ["/usr/local/bin/radius-entrypoint"]
