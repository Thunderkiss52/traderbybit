#!/usr/bin/env bash
set -Eeuo pipefail
archive="${1:?Release archive required}"
directory="${2:?Release image state directory required}"
sha="${3:?Release SHA required}"
[[ "$sha" =~ ^[a-f0-9]{40}$ && "$(basename "$archive")" == "${sha}.tar.gz" ]]
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
checksum="${archive}.sha256"
[[ -f "$archive" && -f "$checksum" ]]
(cd "$(dirname "$archive")" && sha256sum -c "$(basename "$checksum")")
manifest=$(mktemp)
trap 'rm -f "$manifest"' EXIT
# Always use this dispatch's verified archive, including when the release directory is cached.
tar -xOf "$archive" ci-release.json > "$manifest"
python3 "${script_dir}/release-images.py" "$manifest" "$directory" "$sha"
