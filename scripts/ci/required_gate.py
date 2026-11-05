#!/usr/bin/env python3
"""Fail closed on missing, skipped, cancelled or failed mandatory jobs/artifacts."""
import argparse
import json
import re
from pathlib import Path

JOBS = {"inventory", "backend-tests", "go-build", "go-static", "go-checks", "frontend-checks",
        "browser-tests", "php-quality", "security", "workflow-checks", "publish-images"}
TARGETS = {"app", "web", "research-worker"}


def check_jobs(needs):
    if set(needs) != JOBS:
        raise ValueError(f"Required job mismatch: missing={JOBS - set(needs)}, extra={set(needs) - JOBS}")
    failed = {job: value.get("result", "missing") for job, value in needs.items() if value.get("result") != "success"}
    if failed:
        raise ValueError(f"Mandatory jobs did not succeed: {failed}")


def check_images(records, sha, published):
    if len(records) != 3 or {r.get("target") for r in records} != TARGETS:
        raise ValueError("Exactly three distinct image records required")
    if not re.fullmatch(r"[0-9a-f]{40}", sha):
        raise ValueError("Full target SHA required")
    for record in records:
        if record.get("sha") != sha or record.get("published") is not published:
            raise ValueError("Image provenance mismatch")
        if not re.fullmatch(r"sha256:[0-9a-f]{64}", record.get("digest", "")):
            raise ValueError("Missing image digest")
        expected = f"ghcr.io/{record['repository'].lower()}/{record['target']}:sha-{sha}"
        if record.get("tag") != expected:
            raise ValueError("Image tag mismatch")
    return records


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("needs", type=Path)
    p.add_argument("images", type=Path)
    p.add_argument("--sha", required=True)
    p.add_argument("--run-id", required=True)
    p.add_argument("--published", choices=("true", "false"), required=True)
    p.add_argument("--output", type=Path, required=True)
    a = p.parse_args()
    check_jobs(json.loads(a.needs.read_text()))
    records = check_images([json.loads(p.read_text()) for p in sorted(a.images.glob("*.json"))], a.sha, a.published == "true")
    a.output.write_text(json.dumps({"sha": a.sha, "run_id": a.run_id, "images": records}, indent=2) + "\n")
    print(f"CI required: all {len(JOBS)} mandatory job groups and three image digests verified")


if __name__ == "__main__":
    main()
