#!/bin/sh
set -eu
umask 077

: "${RADIUS_LISTEN_ADDRESS:?RADIUS_LISTEN_ADDRESS is required}"
: "${RADIUS_BRIDGE_TOKEN:?RADIUS_BRIDGE_TOKEN is required}"

case "$RADIUS_LISTEN_ADDRESS" in
    *[!0-9.]*|'') echo "RADIUS_LISTEN_ADDRESS must be an IPv4 address" >&2; exit 1 ;;
esac
case "$RADIUS_BRIDGE_TOKEN" in
    *[!0-9a-fA-F]*|'') echo "RADIUS_BRIDGE_TOKEN must be hexadecimal" >&2; exit 1 ;;
esac
if [ "${#RADIUS_BRIDGE_TOKEN}" -lt 64 ]; then
    echo "RADIUS_BRIDGE_TOKEN must contain at least 64 hexadecimal characters" >&2
    exit 1
fi

attempt=0
while ! ip -4 -o addr show dev "${WIREGUARD_INTERFACE:-wg0}" | grep -Fq " ${RADIUS_LISTEN_ADDRESS}/"; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 60 ]; then
        echo "RADIUS_LISTEN_ADDRESS is not assigned to the WireGuard interface" >&2
        exit 1
    fi
    sleep 1
done

cp /opt/radius/clients.conf.template /etc/freeradius/3.0/clients.conf

envsubst '${RADIUS_BRIDGE_TOKEN}' \
    < /opt/radius/dynamic-clients.template \
    > /etc/freeradius/3.0/sites-available/dynamic-clients

envsubst '${RADIUS_LISTEN_ADDRESS} ${RADIUS_BRIDGE_TOKEN}' \
    < /opt/radius/default.template \
    > /etc/freeradius/3.0/sites-available/default

ln -sf ../sites-available/dynamic-clients /etc/freeradius/3.0/sites-enabled/dynamic-clients
chown freerad:freerad \
    /etc/freeradius/3.0/clients.conf \
    /etc/freeradius/3.0/sites-available/default \
    /etc/freeradius/3.0/sites-available/dynamic-clients
exec freeradius -f -l stdout
