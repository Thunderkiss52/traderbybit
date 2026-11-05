#!/usr/bin/env python3
"""Independent runner services must have distinct real server identities."""
import json
from pathlib import Path
import sys


def verify(plan, reports):
    identities = [json.loads((reports / f"{lane}-isolation.json").read_text()) for lane in plan["lanes"]]
    for key in ("postgres_system_identifier", "redis_run_id", "redis_prefix"):
        values = [record.get(key) for record in identities]
        if not all(values) or len(set(values)) != len(values):
            raise ValueError(f"Missing or shared test service identity: {key}")
    if any(r.get("database") != "bybit_test" for r in identities):
        raise ValueError("Unexpected test database")
    print(f"Verified {len(identities)} isolated PostgreSQL/Redis shard pairs")


if __name__ == "__main__":
    verify(json.loads(Path(sys.argv[1]).read_text()), Path(sys.argv[2]))
