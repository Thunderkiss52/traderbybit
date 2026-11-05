#!/usr/bin/env python3
"""Capture hosted job/step timings; usable after a run completes without rerunning CI."""
import argparse
from datetime import datetime
import json
import os
from pathlib import Path

try:
    from scripts.ci.release_guard import GitHub
except ModuleNotFoundError:
    from release_guard import GitHub


def seconds(start, end):
    if not start or not end:
        return None
    return (datetime.fromisoformat(end.replace("Z", "+00:00")) - datetime.fromisoformat(start.replace("Z", "+00:00"))).total_seconds()


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("--repository", default=os.getenv("GITHUB_REPOSITORY"), required=not os.getenv("GITHUB_REPOSITORY"))
    p.add_argument("--run-id", default=os.getenv("GITHUB_RUN_ID"), required=not os.getenv("GITHUB_RUN_ID"))
    p.add_argument("--output", type=Path, required=True)
    a = p.parse_args()
    api = GitHub(a.repository)
    run = api.get(f"actions/runs/{a.run_id}")
    jobs, page = [], 1
    while True:
        data = api.get(f"actions/runs/{a.run_id}/attempts/{run['run_attempt']}/jobs?per_page=100&page={page}")
        jobs.extend(data["jobs"])
        if len(jobs) >= data["total_count"]:
            break
        if not data["jobs"]:
            raise ValueError("Incomplete timing pagination")
        page += 1
    result = {"sha": run["head_sha"], "run_id": a.run_id, "status": run["status"],
              "completed_wall_seconds": seconds(run["run_started_at"], run["updated_at"]) if run["status"] == "completed" else None,
              "jobs": [{"name": j["name"], "conclusion": j["conclusion"], "seconds": seconds(j.get("started_at"), j.get("completed_at")),
                        "steps": [{"name": s["name"], "conclusion": s["conclusion"], "seconds": seconds(s.get("started_at"), s.get("completed_at"))}
                                  for s in j.get("steps", [])]} for j in jobs]}
    a.output.write_text(json.dumps(result, indent=2) + "\n")


if __name__ == "__main__":
    main()
