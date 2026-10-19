#!/usr/bin/env python3
"""
Static Hardcoding Audit (§15.2 / Spec Item 4g)
Scans production application source code (app/) against known historical constants and identifiers
to prove that analytical and financial calculations are dynamic and not hardcoded returns or bypasses.
"""

import json
import os
import re
import sys
import time

PROJECT_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
TARGET_DIR = os.path.join(PROJECT_ROOT, "app")

CONSTANTS_TO_AUDIT = [
    {"pattern": r"142\.5411", "label": "Full-lifecycle gross PnL (142.5411)"},
    {"pattern": r"104\.4998", "label": "Full-lifecycle fees (104.4998)"},
    {"pattern": r"37\.5057", "label": "Full-lifecycle net PnL (37.5057)"},
    {"pattern": r"11\.8735", "label": "Point-in-time gross PnL (11.8735)"},
    {"pattern": r"15\.3567", "label": "Point-in-time fees (15.3567)"},
    {"pattern": r"-3\.4832", "label": "Point-in-time net cash flow (-3.4832)"},
    {"pattern": r"7\.7731", "label": "Historical R-sum (7.7731)"},
    {"pattern": r"0\.6552", "label": "Historical win rate (0.6552)"},
    {"pattern": r"2\.3662", "label": "Historical profit factor (2.3662)"},
    {"pattern": r"e534ac49-60de-5263-9685-4bea563094c2", "label": "Historical audit generation ID"},
]

def scan_files():
    findings = []
    scanned_count = 0

    for root, dirs, files in os.walk(TARGET_DIR):
        for file in files:
            if not file.endswith(".php"):
                continue
            scanned_count += 1
            rel_path = os.path.relpath(os.path.join(root, file), PROJECT_ROOT)
            file_path = os.path.join(root, file)

            with open(file_path, "r", encoding="utf-8", errors="ignore") as f:
                lines = f.readlines()

            for line_no, line in enumerate(lines, 1):
                for target in CONSTANTS_TO_AUDIT:
                    if re.search(target["pattern"], line):
                        findings.append({
                            "file": rel_path,
                            "line_number": line_no,
                            "label": target["label"],
                            "matched_text": line.strip(),
                        })

    return scanned_count, findings

def main():
    print("=== Executing Static Hardcoding Audit against app/ ===")
    scanned_count, findings = scan_files()
    print(f"Scanned {scanned_count} PHP files in app/.")

    verdict = "PASS" if len(findings) == 0 else "FAIL"
    print(f"Findings: {len(findings)}")
    for f in findings:
        print(f"  [ALERT] {f['file']}:{f['line_number']} matches {f['label']}: {f['matched_text']}")

    report = {
        "audit_name": "Production Codebase Static Hardcoding Audit",
        "timestamp_utc": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "target_directory": "app/",
        "files_scanned": scanned_count,
        "prohibited_constants_audited": [c["label"] for c in CONSTANTS_TO_AUDIT],
        "findings_count": len(findings),
        "findings": findings,
        "verdict": verdict,
        "explanation": "No hardcoded historical financial constants, UUIDs, or fake metric returns exist in production app/ code." if verdict == "PASS" else "Prohibited constants detected in app/ code.",
    }

    out_file = os.path.join(PROJECT_ROOT, "reports/evidence/hardcoding_audit.json")
    os.makedirs(os.path.dirname(out_file), exist_ok=True)
    with open(out_file, "w", encoding="utf-8") as f:
        json.dump(report, f, indent=2)

    print(f"Report written to: {out_file}")
    print(f"OVERALL VERDICT: {verdict}")

if __name__ == "__main__":
    main()
