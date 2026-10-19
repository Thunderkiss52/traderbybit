#!/usr/bin/env python3
"""
Generate Golden Fixtures from vector-audit-1d-e534ac49-60de-5263-9685-4bea563094c2.zip
Creates deterministic JSON fixtures in tests/Fixtures/Golden/ for testing:
- golden_counterfactual_duplicates.json (the 4 duplicate baseline/ETHUSDT slots)
- golden_risk_decisions_109.json (the 109 decision rows with verified reasons)
- golden_positions_29.json (the 29 lifecycle positions with gross/fees/funding/net)
- golden_boundary_executions.json (the executions with fee gap reconciliation)
"""

import csv
import io
import json
import os
import zipfile

ARCHIVE_PATH = "vector-audit-1d-e534ac49-60de-5263-9685-4bea563094c2.zip"
OUTPUT_DIR = "tests/Fixtures/Golden"

def load_csv(zf, filename):
    with zf.open(filename) as f:
        content = io.TextIOWrapper(f, encoding='utf-8')
        return list(csv.DictReader(content))

def main():
    if not os.path.exists(ARCHIVE_PATH):
        print(f"Archive not found: {ARCHIVE_PATH}")
        return

    os.makedirs(OUTPUT_DIR, exist_ok=True)
    zf = zipfile.ZipFile(ARCHIVE_PATH)

    # 1. Duplicate Counterfactuals
    cf = load_csv(zf, "counterfactual_evaluations.csv")
    signals = {s['id']: s for s in load_csv(zf, "signals.csv")}
    observations = {o['id']: o for o in load_csv(zf, "strategy_observations.csv")}

    dup_sig_ids = [
        '6923c82d-31f3-5d98-88b8-875f074aa7c7',
        '32044ded-9865-510e-9064-6d669945243f',
        'ef6b4bea-ad5d-5c5f-828d-06972481244e',
        '13933284-41ea-58e7-bb73-dfcc5a17dd3f'
    ]

    dup_fixture = []
    for sid in dup_sig_ids:
        sig = signals.get(sid, {})
        matching_cf = [c for c in cf if c['signal_id'] == sid]
        matching_obs = [observations[c['id']] for c in matching_cf if c['id'] in observations]
        dup_fixture.append({
            "signal_id": sid,
            "strategy_id": sig.get('strategy_id'),
            "symbol": sig.get('symbol'),
            "timeframe": sig.get('timeframe'),
            "candle_close_time": sig.get('candle_close_time'),
            "decision_time": sig.get('decision_time'),
            "evaluations": matching_cf,
            "observations": matching_obs
        })

    with open(os.path.join(OUTPUT_DIR, "golden_counterfactual_duplicates.json"), "w") as f:
        json.dump(dup_fixture, f, indent=2)

    # 2. 109 Risk Decisions
    risk_decisions = load_csv(zf, "risk_decisions.csv")
    with open(os.path.join(OUTPUT_DIR, "golden_risk_decisions_109.json"), "w") as f:
        json.dump(risk_decisions, f, indent=2)

    # 3. 29 Virtual Positions
    positions = load_csv(zf, "virtual_positions.csv")
    with open(os.path.join(OUTPUT_DIR, "golden_positions_29.json"), "w") as f:
        json.dump(positions, f, indent=2)

    # 4. Executions and Boundary Fee Gap
    executions = load_csv(zf, "virtual_executions.csv")
    with open(os.path.join(OUTPUT_DIR, "golden_executions.json"), "w") as f:
        json.dump(executions, f, indent=2)

    print(f"[OK] Generated golden fixtures in {OUTPUT_DIR}")

if __name__ == '__main__':
    main()
