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

if [[ "${1:-}" == "recover" ]]; then
    shift
    recovery_script=""
    if [[ -x "${BASE_DIR}/current/docker/scripts/recover-deployment-state.sh" ]]; then
        recovery_script="${BASE_DIR}/current/docker/scripts/recover-deployment-state.sh"
    else
        candidate="$(ls -td "${RELEASES_DIR}"/* 2>/dev/null | head -n 1 || true)"
        if [[ -n "${candidate}" && -x "${candidate}/docker/scripts/recover-deployment-state.sh" ]]; then
            recovery_script="${candidate}/docker/scripts/recover-deployment-state.sh"
        fi
    fi
    if [[ -n "${recovery_script}" ]]; then
        exec env ENV_FILE="${ENV_FILE}" DEPLOY_STATE_DIR="${STATE_DIR}" "${recovery_script}" "$@"
    else
        echo "recover-deployment-state.sh not found in current or available releases." >&2
        exit 1
    fi
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
if [[ -x "${release_dir}/docker/scripts/validate-deployment-state.sh" ]]; then
    if ! ENV_FILE="${ENV_FILE}" DEPLOY_STATE_DIR="${STATE_DIR}" \
        "${release_dir}/docker/scripts/validate-deployment-state.sh" pre-deploy; then
        echo "[FATAL] Pre-deployment state validation failed. Deployment halted fail-closed before running update.sh." >&2
        echo "To recover production deployment state, run:" >&2
        echo "  sudo ${0} recover --repair --realign-containers" >&2
        echo "or directly:" >&2
        echo "  sudo DEPLOY_STATE_DIR=\"${STATE_DIR}\" ENV_FILE=\"${ENV_FILE}\" \"${release_dir}/docker/scripts/recover-deployment-state.sh\" --repair --realign-containers" >&2
        exit 1
    fi
fi
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
