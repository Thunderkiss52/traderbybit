#!/usr/bin/env python3
"""Stage the CI-approved digest overlay without modifying the production env file."""
import json
import os
from pathlib import Path
import re
import sys


def overlay(data, sha):
    if not re.fullmatch(r"[a-f0-9]{40}", sha) or data.get("sha") != sha or not str(data.get("run_id", "")).isdigit():
        raise ValueError("Invalid CI release provenance")
    records = data.get("images", [])
    if len(records) != 3 or {r.get("target") for r in records} != {"app", "web", "research-worker"}:
        raise ValueError("Incomplete CI image manifest")
    refs = {}
    for r in records:
        expected = f"ghcr.io/{r['repository'].lower()}/{r['target']}:sha-{sha}"
        if r.get("sha") != sha or r.get("published") is not True or r.get("tag") != expected:
            raise ValueError("Unpublished or mismatched image")
        if not re.fullmatch(r"sha256:[a-f0-9]{64}", r.get("digest", "")):
            raise ValueError("Invalid image digest")
        key = {"app": "APP_IMAGE", "web": "WEB_IMAGE", "research-worker": "RESEARCH_WORKER_IMAGE"}[r["target"]]
        if expected != f"{os.environ[key]}:sha-{sha}":
            raise ValueError("CI image repository differs from production configuration")
        refs[r["target"]] = expected + "@" + r["digest"]
    services = {s: {"image": refs["app"]} for s in ("app", "queue", "queue-bybit", "queue-binance", "scheduler", "market-ws", "audit-worker", "backtest-worker")}
    services.update({s: {"image": refs[s]} for s in ("web", "research-worker")})
    return {"services": services, "x-vector-release": data}


def main():
    manifest, directory, sha = Path(sys.argv[1]), Path(sys.argv[2]), sys.argv[3]
    result = overlay(json.loads(manifest.read_text()), sha)
    directory.mkdir(parents=True, exist_ok=True)
    target = directory / f"sha-{sha}.json"
    if target.exists():
        previous = json.loads(target.read_text())
        if previous["services"] != result["services"] or previous["x-vector-release"]["sha"] != sha:
            raise ValueError("Existing immutable release images differ; refusing overwrite")
        print(f"Verified existing immutable release images: {target.name}")
        return
    temporary = target.with_suffix(".tmp")
    with open(temporary, "w") as stream:
        json.dump(result, stream, indent=2)
        stream.write("\n")
        stream.flush()
        os.fsync(stream.fileno())
    temporary.replace(target)
    print(f"Staged exact-SHA image digests: {target.name}")


if __name__ == "__main__":
    main()
