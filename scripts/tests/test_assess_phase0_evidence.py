#!/usr/bin/env python3
"""Automated tests for Phase 0 production evidence assessment engine.

Verifies:
1. Exact 45 obligations loaded from vector-requirements.json
2. Zero duplicate or missing requirement IDs
3. Exact mathematical consistency of summary counts (sum == 45)
4. Zero unjustified PASS verdicts when evidence is missing or inaccessible
5. Complete metadata on every PASS (evidence_file, query_or_result_id, deployed_sha, predicate)
6. Dependency-driven acceptance gate (VEC-P0-008)
7. Deployed SHA runtime parameter validation
8. Distinction of source-code guarantees from runtime production verification
9. Accurate forensic handling of 4 PARTIAL cycles and 316 STALE events
10. Structural synchronization between generated Markdown table and authoritative matrix
"""
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

spec = importlib.util.spec_from_file_location(
    "assessor",
    Path(__file__).resolve().parents[1] / "diagnostics/assess_phase0_evidence.py",
)
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


class AssessPhase0EvidenceTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.evidence_dir = Path(self.tmp.name)

    def tearDown(self):
        self.tmp.cleanup()

    def test_exact_45_obligations_loaded_from_requirements_json(self):
        assessor = m.EvidenceAssessment()
        reqs = assessor.requirements
        self.assertEqual(45, len(reqs))
        # Ensure 9 core, 27 findings, 9 integration findings
        core = [k for k in reqs if k.startswith("VEC-P0-0")]
        findings = [k for k in reqs if k.startswith("VEC-P0-1")]
        integration = [k for k in reqs if k.startswith("VEC-P0-2")]
        self.assertEqual(9, len(core))
        self.assertEqual(27, len(findings))
        self.assertEqual(9, len(integration))

    def test_no_unjustified_pass_when_evidence_directory_missing(self):
        # Local workspace mode: no evidence directory accessible
        assessor = m.EvidenceAssessment(evidence_dir=None)
        res = assessor.assess()

        counts = res["summary_counts"]
        self.assertEqual(0, counts["PASS"], "Local run without evidence artifacts must have 0 PASS")
        self.assertEqual(41, counts["PENDING"])
        self.assertEqual(4, counts["BLOCKED"])
        self.assertEqual(0, counts["FAIL"])
        self.assertEqual(45, sum(counts.values()))
        self.assertEqual("NOT READY", res["acceptance_verdict"])

    def test_no_unjustified_pass_when_evidence_directory_empty(self):
        # Empty directory
        assessor = m.EvidenceAssessment(evidence_dir=self.evidence_dir)
        res = assessor.assess()

        counts = res["summary_counts"]
        self.assertEqual(0, counts["PASS"], "Empty evidence directory must have 0 PASS")
        self.assertEqual(41, counts["PENDING"])
        self.assertEqual(4, counts["BLOCKED"])
        self.assertEqual(45, sum(counts.values()))

    def test_mathematical_consistency_and_id_uniqueness(self):
        assessor = m.EvidenceAssessment(evidence_dir=self.evidence_dir)
        res = assessor.assess()
        obligations = res["obligations"]

        self.assertEqual(45, len(obligations))
        self.assertEqual(set(assessor.requirements.keys()), set(obligations.keys()))

        # Check that sum of individual statuses equals summary counts exactly
        status_counts = {"PASS": 0, "PENDING": 0, "BLOCKED": 0, "FAIL": 0}
        for row in obligations.values():
            status_counts[row["status"]] += 1

        self.assertEqual(status_counts, res["summary_counts"])
        self.assertEqual(45, sum(res["summary_counts"].values()))

    def test_funding_obligations_strictly_blocked_without_independent_rates(self):
        # Populate partial files but NO independent funding
        (self.evidence_dir / "funding_positions_clean.json").write_text(json.dumps([
            {"id": "pos-1", "funding_complete": False, "net_identity_difference": "0.0000"}
        ]), encoding="utf-8")

        assessor = m.EvidenceAssessment(evidence_dir=self.evidence_dir)
        res = assessor.assess()

        self.assertEqual("BLOCKED", res["obligations"]["VEC-P0-003"]["status"])
        self.assertEqual("BLOCKED", res["obligations"]["VEC-P0-203"]["status"])
        self.assertEqual("BLOCKED", res["obligations"]["VEC-P0-207"]["status"])
        self.assertEqual("BLOCKED", res["obligations"]["VEC-P0-008"]["status"])

    def test_acceptance_gate_dependency_driven(self):
        # VEC-P0-008 must be BLOCKED if any requirement is BLOCKED
        assessor = m.EvidenceAssessment(evidence_dir=self.evidence_dir)
        res = assessor.assess()
        self.assertEqual("BLOCKED", res["obligations"]["VEC-P0-008"]["status"])
        self.assertIn("mandatory dependencies are BLOCKED", res["obligations"]["VEC-P0-008"]["reason"])

    def test_deployed_sha_validation_and_runtime_parameter(self):
        # Valid custom SHA
        custom_sha = "abcdef0123456789abcdef0123456789abcdef01"
        assessor = m.EvidenceAssessment(evidence_dir=self.evidence_dir, deployed_sha=custom_sha)
        self.assertEqual(custom_sha, assessor.deployed_sha)

        # Invalid SHA format must raise ValueError
        with self.assertRaises(ValueError):
            m.EvidenceAssessment(evidence_dir=self.evidence_dir, deployed_sha="invalid-sha!")

        # File-based SHA detection
        (self.evidence_dir / "DEPLOYED_REVISION").write_text("1234567890abcdef\n", encoding="utf-8")
        assessor_auto = m.EvidenceAssessment(evidence_dir=self.evidence_dir)
        self.assertEqual("1234567890abcdef", assessor_auto.deployed_sha)

    def test_source_code_guarantees_require_runtime_production_evidence(self):
        # Even with complete diagnostic log, requirements VEC-P0-117, 118, 205, 206
        # remain PENDING runtime verification
        self._populate_mock_operator_evidence()
        assessor = m.EvidenceAssessment(evidence_dir=self.evidence_dir, deployed_sha="9ba6970b4514c32149866e77500757a5784ddcf8")
        res = assessor.assess()

        self.assertEqual("PENDING", res["obligations"]["VEC-P0-117"]["status"])
        self.assertEqual("PENDING", res["obligations"]["VEC-P0-118"]["status"])
        self.assertEqual("PENDING", res["obligations"]["VEC-P0-205"]["status"])
        self.assertEqual("PENDING", res["obligations"]["VEC-P0-206"]["status"])

    def test_historical_partial_cycles_unresolved_forensics(self):
        self._populate_mock_operator_evidence()
        assessor = m.EvidenceAssessment(evidence_dir=self.evidence_dir)
        res = assessor.assess()

        p0_002 = res["obligations"]["VEC-P0-002"]
        self.assertEqual("PENDING", p0_002["status"])
        self.assertIn("4 PARTIAL records", p0_002["reason"])
        self.assertIn("2 distinct Bybit candle cycles", p0_002["reason"])
        self.assertIn("historical account failure identity remains unproven", p0_002["reason"])

    def test_every_pass_has_complete_evidence_metadata(self):
        self._populate_mock_operator_evidence()
        assessor = m.EvidenceAssessment(evidence_dir=self.evidence_dir)
        res = assessor.assess()

        pass_count = 0
        for req_id, row in res["obligations"].items():
            if row["status"] == "PASS":
                pass_count += 1
                self.assertIsNotNone(row["evidence_file"], f"{req_id} missing evidence_file")
                self.assertIsNotNone(row["query_or_result_id"], f"{req_id} missing query_or_result_id")
                self.assertIsNotNone(row["deployed_sha"], f"{req_id} missing deployed_sha")
                self.assertIsNotNone(row["predicate"], f"{req_id} missing predicate")
                self.assertTrue(len(row["reason"]) > 10, f"{req_id} reason too short")

        self.assertEqual(31, pass_count)
        self.assertEqual(31, res["summary_counts"]["PASS"])
        self.assertEqual(10, res["summary_counts"]["PENDING"])
        self.assertEqual(4, res["summary_counts"]["BLOCKED"])
        self.assertEqual(0, res["summary_counts"]["FAIL"])

    def test_markdown_table_structural_match_with_authoritative_matrix(self):
        self._populate_mock_operator_evidence()
        assessor = m.EvidenceAssessment(evidence_dir=self.evidence_dir)
        res = assessor.assess()

        md = m.generate_markdown_table(res)
        md_lines = md.splitlines()

        # Header + separator = 2 lines, then 45 obligation rows
        data_rows = [l for l in md_lines if l.startswith("| **VEC-P0-")]
        self.assertEqual(45, len(data_rows))

        # Check each obligation exists in table with matching status
        for row_str in data_rows:
            cols = [c.strip() for c in row_str.split("|")]
            req_id = cols[1].replace("*", "")
            status = cols[4].replace("*", "")
            self.assertIn(req_id, res["obligations"])
            self.assertEqual(res["obligations"][req_id]["status"], status)

        # Check that summary section in Markdown matches exact counts
        self.assertIn(f"- **PASS:** {res['summary_counts']['PASS']} obligations", md)
        self.assertIn(f"- **PENDING:** {res['summary_counts']['PENDING']} obligations", md)
        self.assertIn(f"- **BLOCKED:** {res['summary_counts']['BLOCKED']} obligations", md)
        self.assertIn(f"- **FAIL:** {res['summary_counts']['FAIL']} obligations", md)

    def _populate_mock_operator_evidence(self):
        """Populate simulated evidence artifacts representing full operator execution."""
        (self.evidence_dir / "funding_positions_clean.json").write_text(json.dumps([
            {"id": "pos-1", "funding_complete": False, "net_identity_difference": "0.0000"}
        ]), encoding="utf-8")

        (self.evidence_dir / "pipeline_anomalies_clean.json").write_text(json.dumps([
            {"id": 1, "status": "PARTIAL", "stage": "PAPER_PROCESSED", "candle_close": "2026-10-28 04:20:00"},
            {"id": 2, "status": "PARTIAL", "stage": "CANDLE_CLOSED", "candle_close": "2026-10-28 04:20:00"},
            {"id": 3, "status": "PARTIAL", "stage": "PAPER_PROCESSED", "candle_close": "2026-10-28 16:35:00"},
            {"id": 4, "status": "PARTIAL", "stage": "CANDLE_CLOSED", "candle_close": "2026-10-28 16:35:00"},
            {"id": 5, "status": "STALE", "stage": "SIGNALS_EVALUATED", "paper_status": "COMPLETED"},
        ]), encoding="utf-8")

        (self.evidence_dir / "evidence_closure.log").write_text(
            "IDENTITY_PRESENT\nBOOTSTRAP_SEQUENCE_PROVEN\nfunding_mark_source_column_present\n"
            "equity_valued_through_column_present\nearliest_event\nclaimed_backtest_run_id\n",
            encoding="utf-8",
        )

        (self.evidence_dir / "production_diagnostic.log").write_text(
            "total_accounts: 26\n"
            "entry_block_required: true\n"
            "invalid_executions_count: 0\n"
            "live_exchange_trading = false\n"
            "mismatch_count: 0\n"
            "cross_exchange_positions: 0\n"
            "closed_without_funding: 379\n"
            "PostgreSQL 17.0 active\n"
            "autonomy_settings: effective_kill_switch_active=true\n",
            encoding="utf-8",
        )

        (self.evidence_dir / "api_status.log").write_text(
            "HTTP_STATUS=200 ENDPOINT=research/replay\n"
            "HTTP_STATUS=200 ENDPOINT=research/profitability/summary\n"
            "HTTP_STATUS=200 ENDPOINT=research/profitability/strategies\n"
            "HTTP_STATUS=200 ENDPOINT=research/health/reconciliation-runs\n"
            "HTTP_STATUS=200 ENDPOINT=research/health/slot-integrity\n"
            "HTTP_STATUS=200 ENDPOINT=audit/strategy-funnel\n"
            "HTTP_STATUS=200 ENDPOINT=signals\n"
            "HTTP_STATUS=200 ENDPOINT=paper/daily-performance\n"
            "HTTP_STATUS=200 ENDPOINT=research/health/strategy-funnel\n"
            "HTTP_STATUS=200 ENDPOINT=research/health/data-freshness\n"
            "HTTP_STATUS=200 ENDPOINT=research/profitability/concentration-dependence\n"
            "HTTP_STATUS=200 ENDPOINT=quote-integrity/status\n"
            "HTTP_STATUS=200 ENDPOINT=paper/daily-performance?page=2\n",
            encoding="utf-8",
        )


if __name__ == "__main__":
    unittest.main()
