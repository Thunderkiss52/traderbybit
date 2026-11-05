#!/usr/bin/env python3
"""The Go DB lifecycle tests must run rather than silently skip in CI."""
import json
import sys

events = [json.loads(line) for line in open(sys.argv[1]) if line.strip()]
skips = [e for e in events if e.get("Action") == "skip" and "Test" in e]
passed = [e for e in events if e.get("Action") == "pass" and "Test" in e]
if skips or not passed:
    raise SystemExit(f"Go verification incomplete: passed={len(passed)}, skips={skips}")
print(f"Go cases passed: {len(passed)}; skipped: 0")
