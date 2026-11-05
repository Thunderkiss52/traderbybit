#!/usr/bin/env bash
set -Eeuo pipefail
mapfile -t images < "${1:?Verified image reference file required}"
[[ "${#images[@]}" == 3 && "$(printf '%s\n' "${images[@]}" | sort -u | wc -l)" == 3 ]]
for image in "${images[@]}"; do
    [[ "$image" =~ ^ghcr.io/[a-z0-9._/-]+:sha-[0-9a-f]{40}@sha256:[0-9a-f]{64}$ ]]
    tag="${image%@*}"
    expected="${image##*@}"
    actual=$(docker buildx imagetools inspect "$tag" --format '{{.Manifest.Digest}}')
    [[ "$actual" == "$expected" ]] || { echo "Image tag digest changed: $tag" >&2; exit 1; }
    docker buildx imagetools inspect "$image" >/dev/null
done
