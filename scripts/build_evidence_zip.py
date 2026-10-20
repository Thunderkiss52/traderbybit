#!/usr/bin/env python3
"""
Packages all verified Section 15 evidence and deployment recovery evidence into:
1. reports/archive.zip (and reports/archive.zip.sha256)
2. reports/evidence.zip (and reports/evidence.zip.sha256)
3. evidence.zip (and evidence.zip.sha256)
Includes raw test logs, golden fixtures, concurrency runner, deployment recovery evidence,
test matrices, mutation patches, command exit codes, and changed code provenance.
"""

import hashlib
import os
import shutil
import sys
import zipfile

PROJECT_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

BASE_FILES_TO_INCLUDE = [
    # Deployment State Recovery Evidence & Scripts
    "reports/evidence/deployment_state_recovery_evidence.json",
    "tests/Unit/DeploymentStateValidationAndRecoveryTest.php",
    "scripts/test_deployment_recovery_matrix.sh",
    "scripts/generate_deployment_recovery_evidence.py",
    "docker/scripts/validate-deployment-state.sh",
    "docker/scripts/recover-deployment-state.sh",

    # Golden fixtures
    "tests/Fixtures/Golden/golden_counterfactual_duplicates.json",
    "tests/Fixtures/Golden/golden_risk_decisions_109.json",
    "tests/Fixtures/Golden/golden_positions_29.json",
    "tests/Fixtures/Golden/golden_executions.json",

    # Source fixture generation and verification scripts
    "scripts/generate_golden_fixtures.py",
    "scripts/forensic_p0_verify.py",
    "scripts/capture_postgres_queries_and_results.py",
    "scripts/run_negative_controls_with_traces.py",
    "scripts/audit_hardcoding.py",

    # Concurrency runner and worker source
    "scripts/test_p1_postgres_concurrency.php",
    "scripts/concurrency_worker.php",
    "database/migrations/2026_10_19_000000_add_slot_idempotency_to_counterfactual_evaluations.php",
    "database/migrations/2026_10_20_000000_add_slot_idempotency_to_strategy_observations.php",
    "tests/Integration/CounterfactualSlotIdempotencyPostgresIntegrationTest.php",
    "tests/Integration/StrategyObservationSlotMigrationPostgresIntegrationTest.php",
]

def collect_all_files():
    files = list(BASE_FILES_TO_INCLUDE)
    # Recursively include all files in reports/evidence
    evidence_dir = os.path.join(PROJECT_ROOT, "reports", "evidence")
    if os.path.exists(evidence_dir):
        for root, _, filenames in os.walk(evidence_dir):
            for fn in sorted(filenames):
                rel_path = os.path.relpath(os.path.join(root, fn), PROJECT_ROOT)
                if rel_path not in files:
                    files.append(rel_path)
    return sorted(files)

def compute_sha256(path):
    h = hashlib.sha256()
    with open(path, "rb") as f:
        while chunk := f.read(65536):
            h.update(chunk)
    return h.hexdigest()

def main():
    print("=== Building Evidence Archives ===")
    files_to_pack = collect_all_files()

    reports_archive_zip = os.path.join(PROJECT_ROOT, "reports", "archive.zip")
    reports_archive_sha = os.path.join(PROJECT_ROOT, "reports", "archive.zip.sha256")
    reports_evidence_zip = os.path.join(PROJECT_ROOT, "reports", "evidence.zip")
    reports_evidence_sha = os.path.join(PROJECT_ROOT, "reports", "evidence.zip.sha256")
    root_evidence_zip = os.path.join(PROJECT_ROOT, "evidence.zip")
    root_evidence_sha = os.path.join(PROJECT_ROOT, "evidence.zip.sha256")

    # Remove stale files
    for p in [reports_archive_zip, reports_archive_sha, reports_evidence_zip, reports_evidence_sha, root_evidence_zip, root_evidence_sha]:
        if os.path.exists(p):
            os.remove(p)

    # Build primary reports/archive.zip
    with zipfile.ZipFile(reports_archive_zip, "w", zipfile.ZIP_DEFLATED) as z:
        for rel_path in files_to_pack:
            full_path = os.path.join(PROJECT_ROOT, rel_path)
            if not os.path.exists(full_path):
                print(f"ERROR: Missing required evidence file: {rel_path}")
                sys.exit(1)
            z.write(full_path, arcname=rel_path)
            print(f"  Added: {rel_path} ({os.path.getsize(full_path):,} bytes)")

    archive_sha = compute_sha256(reports_archive_zip)
    archive_size = os.path.getsize(reports_archive_zip)

    with open(reports_archive_sha, "w", encoding="utf-8") as sf:
        sf.write(f"{archive_sha}  archive.zip\n")

    # Copy to reports/evidence.zip and root evidence.zip for spec compatibility
    shutil.copy2(reports_archive_zip, reports_evidence_zip)
    with open(reports_evidence_sha, "w", encoding="utf-8") as sf:
        sf.write(f"{archive_sha}  evidence.zip\n")

    shutil.copy2(reports_archive_zip, root_evidence_zip)
    with open(root_evidence_sha, "w", encoding="utf-8") as sf:
        sf.write(f"{archive_sha}  evidence.zip\n")

    print("\n=== Archives Built Successfully ===")
    print(f"Total files packaged : {len(files_to_pack)}")
    print(f"Archive Size         : {archive_size:,} bytes")
    print(f"Archive SHA-256      : {archive_sha}")
    print(f"\nGenerated Files:")
    print(f"  1. {reports_archive_zip}")
    print(f"     Checksum: {reports_archive_sha}")
    print(f"  2. {reports_evidence_zip}")
    print(f"     Checksum: {reports_evidence_sha}")
    print(f"  3. {root_evidence_zip}")
    print(f"     Checksum: {root_evidence_sha}")

if __name__ == "__main__":
    main()
