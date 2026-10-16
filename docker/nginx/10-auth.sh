#!/bin/sh
set -eu

password="${UI_BASIC_AUTH_PASSWORD:-}"
if [ -n "${UI_BASIC_AUTH_PASSWORD_B64:-}" ]; then
    password="$(printf '%s' "$UI_BASIC_AUTH_PASSWORD_B64" | base64 -d)" || {
        echo "UI_BASIC_AUTH_PASSWORD_B64 is not valid base64; refusing to start unprotected." >&2
        exit 1
    }
fi

if [ -z "${UI_BASIC_AUTH_USER:-}" ] || [ -z "$password" ]; then
    echo "UI_BASIC_AUTH_USER and either UI_BASIC_AUTH_PASSWORD or UI_BASIC_AUTH_PASSWORD_B64 are required; refusing to start unprotected." >&2
    exit 1
fi

htpasswd -bcB /etc/nginx/.htpasswd "$UI_BASIC_AUTH_USER" "$password" >/dev/null
chown root:nginx /etc/nginx/.htpasswd
chmod 0640 /etc/nginx/.htpasswd
unset password
