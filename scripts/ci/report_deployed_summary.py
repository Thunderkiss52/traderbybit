#!/usr/bin/env python3
"""Publish deployed release summary to GITHUB_STEP_SUMMARY."""
import os
from pathlib import Path
import sys


def main() -> int:
    target_sha = os.environ.get("DEPLOY_COMMIT_SHA", "")
    ci_run_id = os.environ.get("CI_RUN_ID", "")
    repo = os.environ.get("GITHUB_REPOSITORY", "traderbybit/traderbybit")
    build_url = f"https://github.com/{repo}/actions/runs/{ci_run_id}" if ci_run_id else ""

    lines = [
        "## 🚢 VECTOR Production Release Deployed",
        "",
        f"- **Deployed Commit SHA:** `{target_sha}`",
    ]
    if build_url:
        lines.append(f"- **Originating Build Run:** [Build #{ci_run_id}]({build_url})")

    lines.extend([
        "",
        "### 📦 Deployed Image References",
        "```",
    ])
    if Path("image-refs.txt").exists():
        lines.append(Path("image-refs.txt").read_text().strip())
    lines.extend([
        "```",
        "",
        "> [!NOTE]",
        "> Runner wall time and production VPS transition are separate timing events.",
        "",
    ])

    summary_path = os.environ.get("GITHUB_STEP_SUMMARY")
    if summary_path:
        with open(summary_path, "a", encoding="utf-8") as f:
            f.write("\n".join(lines) + "\n")

    return 0


if __name__ == "__main__":
    sys.exit(main())
