#!/usr/bin/env python3
"""Publish verified release summary table to GITHUB_STEP_SUMMARY."""
import json
import os
from pathlib import Path
import sys


def main() -> int:
    manifest_file = Path("release-manifest.json")
    if not manifest_file.exists():
        return 0
    manifest = json.loads(manifest_file.read_text())
    sha = manifest.get("sha", "")
    run_id = manifest.get("run_id", "")
    repo = os.environ.get("GITHUB_REPOSITORY", "traderbybit/traderbybit")
    ref_name = os.environ.get("GITHUB_REF_NAME", "main")
    deploy_url = f"https://github.com/{repo}/actions/workflows/ci.yml"

    lines = [
        "## 🚀 VECTOR Build & CI Verification Succeeded",
        "",
        f"- **Verified Commit SHA:** `{sha}`",
        f"- **Build Run ID:** `{run_id}`",
        f"- **Target Branch:** `{ref_name}`",
        "",
        "### 📦 Approved Immutable Image Digest Manifest",
        "| Target | Image Reference | Digest |",
        "| :--- | :--- | :--- |",
    ]
    for img in manifest.get("images", []):
        target = img.get("target", "")
        tag = img.get("tag", "")
        digest = img.get("digest", "")
        short_digest = digest[:23] + "..." if len(digest) > 23 else digest
        lines.append(f"| `{target}` | `{tag}` | `{short_digest}` |")

    lines.extend([
        "",
        "### 🛡️ Production Release Action",
        "> [!IMPORTANT]",
        "> **Operator Action Required:** Production deployment is strictly operator-triggered.",
        f"> Click here to trigger production release: [**Release Workflow (ci.yml)**]({deploy_url})",
        f"> *(Specify `commit_sha: {sha}`)*",
    ])

    summary_path = os.environ.get("GITHUB_STEP_SUMMARY")
    if summary_path:
        with open(summary_path, "a", encoding="utf-8") as f:
            f.write("\n".join(lines) + "\n")

    return 0


if __name__ == "__main__":
    sys.exit(main())
