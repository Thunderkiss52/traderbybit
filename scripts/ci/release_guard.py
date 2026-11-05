#!/usr/bin/env python3
"""Authorize only current main with a successful exact-SHA aggregate CI gate."""
import argparse
import json
import os
from pathlib import Path
import re
import urllib.request

try:
    from scripts.ci.required_gate import check_images
except ModuleNotFoundError:
    from required_gate import check_images


def select_run(runs, sha):
    matching = [r for r in runs if r.get("head_sha") == sha and r.get("head_branch") == "main"
                and r.get("event") in ("push", "workflow_dispatch")]
    if not matching:
        raise ValueError("No trusted main CI run for exact target SHA")
    run = max(matching, key=lambda r: r["id"])
    if run.get("status") != "completed" or run.get("conclusion") != "success":
        raise ValueError("Latest exact-SHA CI run is not successful")
    return run


def check_gate(jobs):
    gates = [j for j in jobs if j.get("name") == "CI required"]
    if len(gates) != 1 or gates[0].get("status") != "completed" or gates[0].get("conclusion") != "success":
        raise ValueError("Successful CI required job missing for latest run attempt")


def check_manifest(data, sha, run_id, repository):
    if data.get("sha") != sha or str(data.get("run_id")) != str(run_id):
        raise ValueError("Release artifact SHA/run provenance mismatch")
    records = check_images(data.get("images", []), sha, True)
    if any(r["repository"].lower() != repository.lower() for r in records):
        raise ValueError("Image repository mismatch")
    return records


class GitHub:
    def __init__(self, repository):
        if not re.fullmatch(r"[\w.-]+/[\w.-]+", repository):
            raise ValueError("Invalid repository")
        self.root = f"https://api.github.com/repos/{repository}/"

    def get(self, path):
        request = urllib.request.Request(self.root + path, headers={
            "Authorization": "Bearer " + os.environ["GH_TOKEN"],
            "Accept": "application/vnd.github+json", "X-GitHub-Api-Version": "2022-11-28"})
        with urllib.request.urlopen(request, timeout=30) as response:
            return json.load(response)

    def current(self, sha):
        if self.get("branches/main")["commit"]["sha"] != sha:
            raise ValueError("Stale target: deployment requires current main HEAD")

    def authorize(self, sha):
        self.current(sha)
        runs = self.get(f"actions/workflows/ci.yml/runs?head_sha={sha}&per_page=100")["workflow_runs"]
        run = select_run(runs, sha)
        jobs = []
        page = 1
        while True:
            data = self.get(f"actions/runs/{run['id']}/attempts/{run['run_attempt']}/jobs?per_page=100&page={page}")
            jobs.extend(data["jobs"])
            if len(jobs) >= data["total_count"]:
                break
            if not data["jobs"]:
                raise ValueError("Incomplete CI job pagination")
            page += 1
        check_gate(jobs)
        return run["id"]


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("command", choices=("authorize", "current", "manifest"))
    p.add_argument("--sha", required=True)
    p.add_argument("--repository", required=True)
    p.add_argument("--run-id")
    p.add_argument("--manifest", type=Path)
    a = p.parse_args()
    if not re.fullmatch(r"[a-f0-9]{40}", a.sha):
        raise ValueError("Full hexadecimal commit SHA required")
    if a.command == "manifest":
        records = check_manifest(json.loads(a.manifest.read_text()), a.sha, a.run_id, a.repository)
        for r in records:
            print(f"{r['tag']}@{r['digest']}")
    elif a.command == "current":
        GitHub(a.repository).current(a.sha)
    else:
        run_id = GitHub(a.repository).authorize(a.sha)
        with open(os.environ["GITHUB_OUTPUT"], "a") as stream:
            stream.write(f"run_id={run_id}\n")
        print(f"Exact-SHA CI required verified: run {run_id}, SHA {a.sha}")


if __name__ == "__main__":
    main()
