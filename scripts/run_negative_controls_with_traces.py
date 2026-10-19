#!/usr/bin/env python3
"""
Executes the critical mutation/negative control triad (Baseline PASS -> Deliberately Broken FAIL -> Restored PASS)
for all 7 mandatory negative controls.
Enforces Safeguard 5:
- Mutation patches contain ONLY the deliberate mutation, not unrelated changes.
- Captures the original file hash, expected failing test, restored passing test, and verified restoration hash.
- Captures raw stdout/stderr, exit codes, and failure traces into reports/evidence/negative_controls_failure_traces.json.
- Guarantees clean restoration in try...finally blocks.
"""

import difflib
import hashlib
import json
import os
import subprocess
import sys
import time

PROJECT_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

def file_sha256(path):
    if not os.path.exists(path):
        return None
    h = hashlib.sha256()
    with open(path, "rb") as f:
        while chunk := f.read(65536):
            h.update(chunk)
    return h.hexdigest()

def run_cmd(cmd, cwd=PROJECT_ROOT):
    p = subprocess.run(cmd, cwd=cwd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, shell=isinstance(cmd, str))
    return {
        "command": cmd if isinstance(cmd, str) else " ".join(cmd),
        "exit_code": p.returncode,
        "stdout": p.stdout.strip(),
        "stderr": p.stderr.strip(),
    }

def run_psql(sql):
    cmd = ["docker", "exec", "-i", "bybit-test-pg", "psql", "-U", "postgres", "-d", "bybit_test", "-c", sql]
    res = run_cmd(cmd)
    if res["exit_code"] != 0:
        raise RuntimeError(f"psql failed: {res['stderr']}")
    return res

controls = [
    {
        "id": "NEG-P1-01",
        "name": "counterfactual_evaluations slot unique constraint guard",
        "target_module": "CounterfactualAnalyzerService & PostgreSQL schema",
        "type": "SQL",
        "test_cmd": "php -d xdebug.mode=off vendor/bin/phpunit tests/Integration/CounterfactualSlotIdempotencyPostgresIntegrationTest.php --filter test_negative_control_duplicate_direct_insert_rejected_by_postgresql_unique_constraint",
        "sql_patch": "-- NEG-P1-01 MUTATION: Drop slot unique partial indexes to allow duplicate slots\nDROP INDEX IF EXISTS counterfactual_evaluations_slot_unique;\nDROP INDEX IF EXISTS counterfactual_evaluations_signal_unique;\n",
        "break": lambda: run_psql("DROP INDEX IF EXISTS counterfactual_evaluations_slot_unique; DROP INDEX IF EXISTS counterfactual_evaluations_signal_unique;"),
        "restore": lambda: run_psql("DELETE FROM counterfactual_evaluations WHERE skip_reason LIKE 'test_skip%'; CREATE UNIQUE INDEX IF NOT EXISTS counterfactual_evaluations_slot_unique ON counterfactual_evaluations (strategy_id, strategy_version, symbol, timeframe, decision_time) WHERE (superseded_by IS NULL); CREATE UNIQUE INDEX IF NOT EXISTS counterfactual_evaluations_signal_unique ON counterfactual_evaluations (signal_id) WHERE ((signal_id IS NOT NULL) AND (superseded_by IS NULL));"),
    },
    {
        "id": "NEG-P2-01",
        "name": "risk_decisions idempotency unique constraint",
        "target_module": "RiskDecisionRecord & PostgreSQL schema",
        "type": "SQL",
        "test_cmd": "php -d xdebug.mode=off vendor/bin/phpunit tests/Integration/SlotIntegrityAndRiskFunnelPostgresIntegrationTest.php --filter test_negative_control_duplicate_risk_decision_rejected_by_unique_constraint",
        "sql_patch": "-- NEG-P2-01 MUTATION: Drop risk_decisions unique constraint to allow duplicate decisions\nALTER TABLE risk_decisions DROP CONSTRAINT IF EXISTS risk_decisions_paper_account_id_signal_id_risk_version_unique;\n",
        "break": lambda: run_psql("ALTER TABLE risk_decisions DROP CONSTRAINT IF EXISTS risk_decisions_paper_account_id_signal_id_risk_version_unique;"),
        "restore": lambda: run_psql("DELETE FROM risk_decisions WHERE signal_id IN (SELECT id FROM signals WHERE symbol = 'BTCUSDT' AND strategy_id = 'momentum'); DELETE FROM signals WHERE symbol = 'BTCUSDT' AND strategy_id = 'momentum'; ALTER TABLE risk_decisions ADD CONSTRAINT risk_decisions_paper_account_id_signal_id_risk_version_unique UNIQUE (paper_account_id, signal_id, risk_version);"),
    },
    {
        "id": "NEG-P2-02",
        "name": "slot integrity missing unexplained cadence alert",
        "target_module": "SlotIntegrityService",
        "type": "FILE",
        "file_rel_path": "app/Research/Application/SlotIntegrityService.php",
        "test_cmd": "php -d xdebug.mode=off vendor/bin/phpunit tests/Integration/SlotIntegrityAndRiskFunnelPostgresIntegrationTest.php --filter test_slot_integrity_cadence_detects_missing_unexplained_and_market_data_unavailable",
        "target_text": "$alerts[] = [",
        "mutated_text": "// MUTATION_MUTATED\n        if (false) $alerts[] = [",
    },
    {
        "id": "NEG-P3-01",
        "name": "financial reconciliation funding sign discrepancy detection",
        "target_module": "LedgerReconciliationService",
        "type": "FILE",
        "file_rel_path": "app/Research/Application/LedgerReconciliationService.php",
        "test_cmd": "php -d xdebug.mode=off vendor/bin/phpunit tests/Integration/FinancialAccountingAndReconciliationPostgresIntegrationTest.php --filter test_negative_control_inverted_funding_sign_fails_reconciliation",
        "target_text": "$totalFunding = $totalFunding->plus(BigDecimal::of($fe->amount ?? '0'));",
        "mutated_text": "// MUTATION_MUTATED: inverted funding sign calculation\n            $totalFunding = $totalFunding->minus(BigDecimal::of($fe->amount ?? '0'));",
    },
    {
        "id": "NEG-P4-01",
        "name": "unauthorized candidate promotion rejected without Gate 6 validation",
        "target_module": "PromotionPolicyService",
        "type": "FILE",
        "file_rel_path": "app/Research/Application/PromotionPolicyService.php",
        "test_cmd": "php -d xdebug.mode=off vendor/bin/phpunit tests/Integration/MlAuthorityAndProperScoringPostgresIntegrationTest.php --filter test_negative_mutation_unauthorized_promotion_rejected",
        "target_text": "if (! ($gates['all_gates_passed'] ?? false)) {",
        "mutated_text": "// MUTATION_MUTATED\n        if (false && ! ($gates['all_gates_passed'] ?? false)) {",
    },
    {
        "id": "NEG-P4-02",
        "name": "legacy active model authority denial under Gate 6 policy",
        "target_module": "PromotionPolicyService & ModelAdvisoryService",
        "type": "FILE",
        "file_rel_path": "app/Research/Application/PromotionPolicyService.php",
        "test_cmd": "php -d xdebug.mode=off vendor/bin/phpunit tests/Integration/MlAuthorityAndProperScoringPostgresIntegrationTest.php --filter test_legacy_active_model_lacks_authority_and_fails_closed_or_bypasses",
        "target_text": "public function evaluateAdvisoryEligibility(LearningModelArtifactRecord $model): array\n    {",
        "mutated_text": "public function evaluateAdvisoryEligibility(LearningModelArtifactRecord $model): array\n    {\n        // MUTATION_MUTATED\n        return ['eligible' => true, 'reason' => 'gate6_promotion_verified', 'policy_version' => '1.0.0'];",
    },
    {
        "id": "NEG-P5-01",
        "name": "source variation dynamic calculation proof against hardcoded constants",
        "target_module": "QuantPortfolioMetricsService",
        "type": "FILE",
        "file_rel_path": "app/Research/Application/QuantPortfolioMetricsService.php",
        "test_cmd": "php -d xdebug.mode=off vendor/bin/phpunit tests/Integration/QuantPortfolioMetricsPostgresIntegrationTest.php --filter test_negative_control_source_variation_reacts_dynamically",
        "target_text": "'net_pnl' => round($netSum, 4),",
        "mutated_text": "// MUTATION_MUTATED\n            'net_pnl' => 37.5057,",
    },
]

def main():
    print("=== Running Critical Mutation Controls Triad (Baseline PASS -> Broken FAIL -> Restored PASS) ===")
    patch_dir = os.path.join(PROJECT_ROOT, "reports/evidence/mutation_patches")
    os.makedirs(patch_dir, exist_ok=True)
    results = []

    for ctrl in controls:
        ctrl_id = ctrl["id"]
        name = ctrl["name"]
        print(f"\n[{ctrl_id}] {name}")

        file_full_path = None
        pre_hash = None
        mut_hash = None
        restored_hash = None
        patch_file_path = os.path.join(patch_dir, f"{ctrl_id}.patch")

        if ctrl["type"] == "FILE":
            file_full_path = os.path.join(PROJECT_ROOT, ctrl["file_rel_path"])
            pre_hash = file_sha256(file_full_path)
            with open(file_full_path, "r", encoding="utf-8") as f:
                original_content = f.read()

        # 1. Baseline PASS
        print(f"  [1/3] Running Baseline PASS...")
        base_res = run_cmd(ctrl["test_cmd"])
        print(f"        Exit Code: {base_res['exit_code']} (Expected: 0)")
        if base_res["exit_code"] != 0:
            print(f"        ERROR: Baseline failed!\nStdout: {base_res['stdout']}\nStderr: {base_res['stderr']}")
            sys.exit(1)

        # 2. Deliberately broken FAIL
        print(f"  [2/3] Injecting fault for Deliberately Broken FAIL...")
        if ctrl["type"] == "FILE":
            if ctrl["target_text"] not in original_content:
                raise ValueError(f"Target text not found in {ctrl['file_rel_path']}")
            mutated_content = original_content.replace(ctrl["target_text"], ctrl["mutated_text"], 1)
            with open(file_full_path, "w", encoding="utf-8") as f:
                f.write(mutated_content)
            mut_hash = file_sha256(file_full_path)

            # Generate strictly isolated unified diff
            diff_lines = list(difflib.unified_diff(
                original_content.splitlines(keepends=True),
                mutated_content.splitlines(keepends=True),
                fromfile=f"a/{ctrl['file_rel_path']}",
                tofile=f"b/{ctrl['file_rel_path']}"
            ))
            with open(patch_file_path, "w", encoding="utf-8") as pf:
                pf.writelines(diff_lines)
        else:
            ctrl["break"]()
            with open(patch_file_path, "w", encoding="utf-8") as pf:
                pf.write(ctrl["sql_patch"])

        broken_res = None
        try:
            broken_res = run_cmd(ctrl["test_cmd"])
            print(f"        Exit Code: {broken_res['exit_code']} (Expected: > 0)")
            if broken_res["exit_code"] == 0:
                print(f"        ERROR: Fault injection did not fail the test! Test falsely passed!")
                sys.exit(1)
            else:
                fail_line = broken_res['stdout'].splitlines()[-1] if broken_res['stdout'] else broken_res['stderr'].splitlines()[-1]
                print(f"        Caught expected failure: {fail_line}")
        finally:
            print(f"  [3/3] Restoring pristine state...")
            if ctrl["type"] == "FILE":
                with open(file_full_path, "w", encoding="utf-8") as f:
                    f.write(original_content)
                restored_hash = file_sha256(file_full_path)
                if restored_hash != pre_hash:
                    raise RuntimeError(f"FATAL: Restoration failed! Hash mismatch for {ctrl['file_rel_path']}: {restored_hash} != {pre_hash}")
                print(f"        Restoration verified bit-for-bit (SHA-256: {restored_hash[:16]}...)")
            else:
                ctrl["restore"]()

        # 3. Restored PASS
        print(f"        Running Restored PASS...")
        restored_res = run_cmd(ctrl["test_cmd"])
        print(f"        Exit Code: {restored_res['exit_code']} (Expected: 0)")
        if restored_res["exit_code"] != 0:
            print(f"        ERROR: Restored test failed!\nStdout: {restored_res['stdout']}\nStderr: {restored_res['stderr']}")
            sys.exit(1)

        results.append({
            "control_id": ctrl_id,
            "name": name,
            "target_module": ctrl["target_module"],
            "type": ctrl["type"],
            "test_command": ctrl["test_cmd"],
            "mutation_patch_file": os.path.relpath(patch_file_path, PROJECT_ROOT),
            "provenance": {
                "file": ctrl.get("file_rel_path"),
                "pre_mutation_sha256": pre_hash,
                "mutated_sha256": mut_hash,
                "restored_sha256": restored_hash,
                "restoration_verified": (pre_hash == restored_hash) if pre_hash else True,
            },
            "baseline_pass": {
                "exit_code": base_res["exit_code"],
                "stdout": base_res["stdout"],
                "stderr": base_res["stderr"],
                "status": "PASS",
            },
            "deliberately_broken_fail": {
                "exit_code": broken_res["exit_code"],
                "stdout": broken_res["stdout"],
                "stderr": broken_res["stderr"],
                "status": "FAIL_AS_EXPECTED",
            },
            "restored_pass": {
                "exit_code": restored_res["exit_code"],
                "stdout": restored_res["stdout"],
                "stderr": restored_res["stderr"],
                "status": "PASS",
            },
            "overall_verdict": "VERIFIED_SENSITIVE",
        })

    evidence_file = os.path.join(PROJECT_ROOT, "reports/evidence/negative_controls_failure_traces.json")
    os.makedirs(os.path.dirname(evidence_file), exist_ok=True)
    with open(evidence_file, "w", encoding="utf-8") as f:
        json.dump({
            "generated_at_utc": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
            "total_controls": len(results),
            "all_verified_sensitive": all(r["overall_verdict"] == "VERIFIED_SENSITIVE" for r in results),
            "controls": results,
        }, f, indent=2)

    print(f"\nAll 7 negative controls verified sensitive with raw failure traces captured.")
    print(f"Report written to: {evidence_file}")

if __name__ == "__main__":
    main()
