#!/usr/bin/env python3
"""Report GitHub workflow clock metadata."""
import json
import os
import urllib.error
import urllib.request

url = (
    f"https://api.github.com/repos/{os.environ['GITHUB_REPOSITORY']}"
    f"/actions/runs/{os.environ['GITHUB_RUN_ID']}"
)

request = urllib.request.Request(
    url,
    headers={
        "Authorization": "Bearer " + os.environ["GH_TOKEN"],
        "Accept": "application/vnd.github+json",
    },
)

try:
    with urllib.request.urlopen(request, timeout=30) as response:
        run = json.load(response)

    print(
        "VECTOR_WORKFLOW_CLOCK "
        + json.dumps({
            key: run.get(key)
            for key in (
                "created_at",
                "run_started_at",
                "updated_at",
                "status",
            )
        })
    )
except (OSError, ValueError) as error:
    print(
        "::warning::Workflow timestamp metadata unavailable: "
        + type(error).__name__
    )
