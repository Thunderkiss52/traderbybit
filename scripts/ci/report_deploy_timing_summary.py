#!/usr/bin/env python3
"""Publish deployment stage latency breakdown table to GITHUB_STEP_SUMMARY."""
import json
import os
from pathlib import Path
import sys


def main() -> int:
    timing_file = os.environ.get("DEPLOY_TIMING_FILE")
    if not timing_file or not Path(timing_file).exists():
        return 0

    lines = [
        "",
        "### ⏱️ Stage Latency Breakdown",
        "| Stage | Duration (s) | Status |",
        "| :--- | :--- | :--- |",
    ]
    for raw in Path(timing_file).read_text(encoding="utf-8").splitlines():
        if not raw.strip():
            continue
        try:
            ev = json.loads(raw)
            sec = f"{ev.get('elapsed_ms', 0) / 1000.0:.2f}s"
            st = "✅ Success" if ev.get("exit_code", 0) == 0 else f"❌ Failed ({ev.get('exit_code')})"
            lines.append(f"| `{ev.get('stage')}` | {sec} | {st} |")
        except Exception:
            pass

    summary_path = os.environ.get("GITHUB_STEP_SUMMARY")
    if summary_path and len(lines) > 4:
        with open(summary_path, "a", encoding="utf-8") as f:
            f.write("\n".join(lines) + "\n")

    return 0


if __name__ == "__main__":
    sys.exit(main())
