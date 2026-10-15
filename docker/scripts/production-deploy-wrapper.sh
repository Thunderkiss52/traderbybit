#!/usr/bin/env bash
set -Eeuo pipefail

BASE_DIR=/srv/bybit-signals
INCOMING_DIR="${BASE_DIR}/incoming"
RELEASES_DIR="${BASE_DIR}/releases"
STATE_DIR="${BASE_DIR}/deploy-state"
ENV_FILE="${BASE_DIR}/.env.production"

if [[ "${1:-}" == "rollback" ]]; then
    exec env ENV_FILE="${ENV_FILE}" DEPLOY_STATE_DIR="${STATE_DIR}" "${BASE_DIR}/current/docker/scripts/rollback.sh"
fi

release_sha="${1:-}"
[[ "${release_sha}" =~ ^[0-9a-f]{40}$ ]] || { echo "A full hexadecimal commit SHA is required." >&2; exit 2; }
archive="${INCOMING_DIR}/${release_sha}.tar.gz"
checksum="${archive}.sha256"
[[ -f "${archive}" && -f "${checksum}" ]] || { echo "Release archive or checksum is missing." >&2; exit 2; }

cd "${INCOMING_DIR}"
sha256sum -c "$(basename "${checksum}")"
if tar -tzf "${archive}" | grep -Eq '(^/|(^|/)\.\.(/|$))'; then
    echo "Unsafe path in release archive." >&2
    exit 2
fi

release_dir="${RELEASES_DIR}/${release_sha}"
old_release_sha=""
if [[ -f "${STATE_DIR}/release-current" ]]; then
    old_release_sha="$(<"${STATE_DIR}/release-current")"
fi
if [[ ! -d "${release_dir}" ]]; then
    mkdir -p "${release_dir}"
    tar -xzf "${archive}" --no-same-owner -C "${release_dir}"
fi
chmod 0755 "${release_dir}"/docker/scripts/*.sh "${release_dir}"/docker/postgres/*.sh

mkdir -p "${STATE_DIR}"
ENV_FILE="${ENV_FILE}" DEPLOY_STATE_DIR="${STATE_DIR}" NEW_IMAGE_TAG="sha-${release_sha}" DEPLOY_MODE=pull \
    "${release_dir}/docker/scripts/update.sh"

ln -sfn "${release_dir}" "${BASE_DIR}/current.next"
mv -Tf "${BASE_DIR}/current.next" "${BASE_DIR}/current"
printf '%s\n' "${release_sha}" >"${STATE_DIR}/release-current"
rm -f -- "${archive}" "${checksum}"

for candidate in "${RELEASES_DIR}"/*; do
    [[ -d "${candidate}" ]] || continue
    candidate_sha="$(basename "${candidate}")"
    [[ "${candidate_sha}" == "${release_sha}" || "${candidate_sha}" == "${old_release_sha}" ]] && continue
    rm -rf --one-file-system -- "${candidate}"
done
if [[ -n "${old_release_sha}" ]]; then
    printf '%s\n' "${old_release_sha}" >"${STATE_DIR}/release-previous"
fi
