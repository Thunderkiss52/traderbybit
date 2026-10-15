#!/bin/sh
set -eu

if [ -z "${UI_BASIC_AUTH_USER:-}" ] || [ -z "${UI_BASIC_AUTH_PASSWORD:-}" ]; then
    echo "UI_BASIC_AUTH_USER and UI_BASIC_AUTH_PASSWORD are required; refusing to start unprotected." >&2
    exit 1
fi

htpasswd -bcB /etc/nginx/.htpasswd "$UI_BASIC_AUTH_USER" "$UI_BASIC_AUTH_PASSWORD" >/dev/null
chown root:nginx /etc/nginx/.htpasswd
chmod 0640 /etc/nginx/.htpasswd
