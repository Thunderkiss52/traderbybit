#!/usr/bin/env python3
"""
Packages all verified Section 15 evidence into evidence.zip per VECTOR V5.3 specification.
Includes raw test logs, source fixture-generation scripts, golden fixtures, concurrency runner
and worker source, mutation patches, command exit codes, and changed code provenance.
Generates evidence.zip first, then calculates its final SHA-256 and byte size, and writes
the checksum to evidence.zip.sha256.
"""

import glob
import hashlib
import os
import sys
import zipfile

PROJECT_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUTPUT_ZIP = os.path.join(PROJECT_ROOT, "evidence.zip")
OUTPUT_SHA256 = os.path.join(PROJECT_ROOT, "evidence.zip.sha256")

FILES_TO_INCLUDE = [
    # Core manifests
    "reports/evidence/aggregate_manifest.json",
    "reports/evidence/p0_manifest.json",
    "reports/evidence/p0_claims.jsonl",
    "reports/evidence/p0_source_manifest.json",
    "reports/evidence/p0_forensic_summary.json",
    "reports/evidence/p0_performance_baseline.json",
    "reports/evidence/p0_unverified_paths.md",
    "reports/evidence/p0_commands.txt",

    # Package P1
    "reports/evidence/p1_manifest.json",
    "reports/evidence/p1_claims.jsonl",
    "reports/evidence/p1_negative_controls.json",
    "reports/evidence/p1_commands.txt",
    "reports/evidence/p1_concurrency_proof.json",

    # Package P2
    "reports/evidence/p2_manifest.json",
    "reports/evidence/p2_claims.jsonl",
    "reports/evidence/p2_negative_controls.json",
    "reports/evidence/p2_commands.txt",

    # Package P3
    "reports/evidence/p3_manifest.json",
    "reports/evidence/p3_claims.jsonl",
    "reports/evidence/p3_negative_controls.json",
    "reports/evidence/p3_commands.txt",

    # Package P4
    "reports/evidence/p4_manifest.json",
    "reports/evidence/p4_claims.jsonl",
    "reports/evidence/p4_negative_controls.json",

    # Package P5
    "reports/evidence/p5_manifest.json",
    "reports/evidence/p5_claims.jsonl",
    "reports/evidence/p5_negative_controls.json",

    # Reproducibility run payloads
    "reports/evidence/reproducibility/run_1_payload.json",
    "reports/evidence/reproducibility/run_2_payload.json",
    "reports/evidence/reproducibility/p5_reproducibility.json",

    # Critical cross-package evidence
    "reports/evidence/negative_controls_failure_traces.json",
    "reports/evidence/hardcoding_audit.json",
    "reports/evidence/postgres_queries_and_results.json",
    "reports/evidence/command_exit_codes.json",
    "reports/evidence/changed_code_provenance.json",

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

    # Raw test logs
    "reports/evidence/raw_test_logs/phpunit_full_stdout.log",
    "reports/evidence/raw_test_logs/phpunit_isolated_postgres.log",
    "reports/evidence/raw_test_logs/frontend_test_stdout.log",
    "reports/evidence/raw_test_logs/frontend_build_stdout.log",
    "reports/evidence/raw_test_logs/pint_stdout.log",
    "reports/evidence/raw_test_logs/go_test_stdout.log",

    # Mutation patches
    "reports/evidence/mutation_patches/NEG-P1-01.patch",
    "reports/evidence/mutation_patches/NEG-P2-01.patch",
    "reports/evidence/mutation_patches/NEG-P2-02.patch",
    "reports/evidence/mutation_patches/NEG-P3-01.patch",
    "reports/evidence/mutation_patches/NEG-P4-01.patch",
    "reports/evidence/mutation_patches/NEG-P4-02.patch",
    "reports/evidence/mutation_patches/NEG-P5-01.patch",
]

def main():
    print("=== Building evidence.zip ===")
    if os.path.exists(OUTPUT_ZIP):
        os.remove(OUTPUT_ZIP)

    with zipfile.ZipFile(OUTPUT_ZIP, "w", zipfile.ZIP_DEFLATED) as z:
        for rel_path in FILES_TO_INCLUDE:
            full_path = os.path.join(PROJECT_ROOT, rel_path)
            if not os.path.exists(full_path):
                print(f"ERROR: Missing required evidence file: {rel_path}")
                sys.exit(1)
            z.write(full_path, arcname=rel_path)
            print(f"  Added: {rel_path} ({os.path.getsize(full_path):,} bytes)")

    # Compute final archive SHA-256 and size
    h = hashlib.sha256()
    with open(OUTPUT_ZIP, "rb") as f:
        while chunk := f.read(65536):
            h.update(chunk)
    zip_sha = h.hexdigest()
    zip_size = os.path.getsize(OUTPUT_ZIP)

    # Write separate checksum file
    with open(OUTPUT_SHA256, "w", encoding="utf-8") as sf:
        sf.write(f"{zip_sha}  evidence.zip\n")

    print(f"\nevidence.zip built successfully!")
    print(f"File: {OUTPUT_ZIP}")
    print(f"Size: {zip_size:,} bytes")
    print(f"SHA-256: {zip_sha}")
    print(f"Checksum file written to: {OUTPUT_SHA256}")

if __name__ == "__main__":
    main()
