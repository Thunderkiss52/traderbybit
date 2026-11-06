#!/usr/bin/env bash
# Linux deployment hosts and GitHub runners share a monotonic uptime clock.
monotonic_ms() {
    local uptime seconds fraction
    read -r uptime _ </proc/uptime
    seconds="${uptime%.*}"
    fraction="${uptime#*.}000"
    printf '%s\n' "$((10#${seconds} * 1000 + 10#${fraction:0:3}))"
}

timing_record() {
    local stage="$1" start="$2" status="${3:-0}" elapsed
    elapsed=$(( $(monotonic_ms) - start ))
    printf 'VECTOR_TIMING {"stage":"%s","elapsed_ms":%s,"exit_code":%s}\n' "${stage}" "${elapsed}" "${status}"
    if [[ -n "${DEPLOY_TIMING_FILE:-}" ]]; then
        printf '{"stage":"%s","elapsed_ms":%s,"exit_code":%s}\n' "${stage}" "${elapsed}" "${status}" >>"${DEPLOY_TIMING_FILE}"
    fi
}

timing_run() {
    local stage="$1" start status
    shift
    start="$(monotonic_ms)"
    if "$@"; then status=0; else status=$?; fi
    timing_record "${stage}" "${start}" "${status}"
    return "${status}"
}

timing_summary() {
    [[ -n "${DEPLOY_TIMING_FILE:-}" && -f "${DEPLOY_TIMING_FILE}" ]] || return 0
    python3 -c 'import json,sys; print("VECTOR_TIMING_SUMMARY " + json.dumps({"events": [json.loads(line) for line in open(sys.argv[1])]}))' "${DEPLOY_TIMING_FILE}"
}
