"""Scorecard tests use disposable Git repositories, never Laravel or databases."""
import copy
from datetime import date
import hashlib
import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch
from contextlib import redirect_stdout, redirect_stderr
import io

SPEC = importlib.util.spec_from_file_location("progress", Path(__file__).resolve().parents[1] / "vector_progress.py")
p = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(p)


class ScorecardTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.run_git("init", "-q")
        self.run_git("config", "user.email", "test@example.invalid")
        self.run_git("config", "user.name", "Test")
        (self.root / "source.py").write_text("def evaluate(): pass\n")
        (self.root / "result.txt").write_text("PASS: fixture-only test\n")
        self.run_git("add", ".")
        self.run_git("commit", "-qm", "fixture")
        self.sha = p.git(self.root, "rev-parse", "HEAD")
        self.today = date(2026, 10, 27)
        self.registry = {"registry_version": 1, "scope_baseline": "test-v1", "assessment_head": self.sha,
                         "weight_policy": {m: {str(i): int(i == 0) for i in range(7)} for m in p.METRICS},
                         "capabilities": [{"id": "C0", "phase": 0}], "requirements": []}
        r = {"id": "VEC-P0-001", "phase": 0, "parent_capability": "C0", "title": "Evaluate", "description": "Pure evaluation",
             "type": "runtime", "priority": 0, "weight": 2, "dependencies": [], "watch_paths": ["source.py"],
             "criteria": [{"id": "I1", "description": "Evaluate contract", "status": "PLANNED", "evidence": None},
                          {"id": "I2", "description": "Integrated validation", "status": "PLANNED", "evidence": None}],
             "obligations": [{"id": "T1", "description": "Fixture", "type": "unit", "environment": "local", "weight": 1, "max_age_days": 30, "evidence": []}],
             "acceptance_criteria": [{"id": "A1", "description": "User outcome demonstrated"}], "product_evidence": None,
             "current_state": "NOT_STARTED", "assessment": "PLANNED", "notes": [], "blocking_issues": [],
             "source_paths": ["source.py"], "test_paths": [], "last_verified_commit": None,
             "last_verified_environment": None, "last_evidence_update": "2026-10-27"}
        self.registry["requirements"].append(r)
        self.rebaseline()

    def run_git(self, *args):
        subprocess.run(["git", "-C", str(self.root), *args], check=True, capture_output=True)

    def rebaseline(self):
        self.baseline = {"id": self.registry["scope_baseline"], "scope": copy.deepcopy(p.scope(self.registry)),
                         "approval": {"status": "PROPOSED"}, "change_log": ["Initial"]}

    @property
    def r(self):
        return self.registry["requirements"][0]

    def source_evidence(self):
        return {"git_sha": self.sha, "date": "2026-10-27", "environment": "local", "limitations": "Source only",
                "reviewer": "test", "dependency_hash": p.fingerprint(self.root, self.r["watch_paths"]),
                "references": [{"path": "source.py", "anchor": "def evaluate"}]}

    def implement(self, count=2):
        for c in self.r["criteria"][:count]:
            c.update(status="IMPLEMENTED", evidence=self.source_evidence())

    def verify(self, result="PASS"):
        evidence = {"requirement_id": self.r["id"], "obligation_id": "T1", "git_sha": self.sha,
                    "date": "2026-10-27", "environment": "local", "type": "unit", "result": result,
                    "fixture": "synthetic fixture", "synthetic": True, "reviewer": "test",
                    "artifact": "result.txt", "artifact_sha256": hashlib.sha256((self.root / "result.txt").read_bytes()).hexdigest(),
                    "limitations": "Not operational", "dependency_hash": p.fingerprint(self.root, self.r["watch_paths"])}
        self.r["obligations"][0]["evidence"].append(evidence)
        return evidence

    def report(self):
        return p.calculate(self.registry, self.root, self.baseline, self.today)

    def test_zero_and_partial(self):
        self.assertEqual(self.report()["overall"], dict.fromkeys(p.METRICS, 0))
        self.implement(1)
        self.assertEqual(self.report()["overall"]["implementation"], 50)
        self.assertEqual(self.report()["overall"]["verification"], 0)

    def test_weights_normalized(self):
        self.implement()
        other = copy.deepcopy(self.r)
        other.update(id="VEC-P0-002", weight=6)
        for c in other["criteria"]:
            c.update(id=c["id"] + "b", description=c["description"] + " other", status="PLANNED", evidence=None)
        other["obligations"][0]["id"] = "T2"
        self.registry["requirements"].append(other)
        self.rebaseline()
        self.assertEqual(self.report()["overall"]["implementation"], 25)

    def test_missing_skipped_failed_expired(self):
        self.assertEqual(self.report()["freshness"], {"UNAVAILABLE": 1})
        self.verify("SKIPPED")
        self.assertEqual(self.report()["overall"]["verification"], 0)
        self.verify()
        self.assertEqual(self.report()["overall"]["verification"], 100)
        self.verify("FAIL")
        self.assertEqual(self.report()["overall"]["verification"], 0)
        e = self.verify()
        e["date"] = "2026-01-01"
        self.assertEqual(self.report()["freshness"], {"STALE": 1})

    def test_dependency_change_decreases_both_scores(self):
        self.implement()
        self.verify()
        before = self.report()
        (self.root / "source.py").write_text("def evaluate(): raise RuntimeError()\n")
        after = self.report()
        self.assertEqual(after["overall"]["implementation"], 0)
        self.assertEqual(after["overall"]["verification"], 0)
        self.assertEqual(p.comparison(after, before)["delivery_delta"]["verification"], -100)

    def test_duplicate_ids_and_criteria(self):
        self.registry["requirements"].append(copy.deepcopy(self.r))
        self.rebaseline()
        with self.assertRaisesRegex(p.InvalidRegistry, "Duplicate requirement"):
            self.report()
        self.registry["requirements"][1]["id"] = "VEC-P0-002"
        self.rebaseline()
        with self.assertRaisesRegex(p.InvalidRegistry, "double-counting"):
            self.report()

    def test_invalid_dependency(self):
        self.r["dependencies"] = ["MISSING"]
        self.rebaseline()
        with self.assertRaisesRegex(p.InvalidRegistry, "Invalid dependency"):
            self.report()

    def test_cycle(self):
        other = copy.deepcopy(self.r)
        other.update(id="VEC-P0-002", dependencies=[self.r["id"]])
        for c in other["criteria"]:
            c["id"] += "b"
            c["description"] += " other"
        other["obligations"][0]["id"] = "T2"
        self.registry["requirements"].append(other)
        self.r["dependencies"] = [other["id"]]
        self.rebaseline()
        with self.assertRaisesRegex(p.InvalidRegistry, "Circular"):
            self.report()

    def test_scope_change_and_comparison(self):
        before = self.report()
        self.r["weight"] += 1
        with self.assertRaisesRegex(p.InvalidRegistry, "Scope changed"):
            self.report()
        self.registry["scope_baseline"] = "test-v2"
        self.rebaseline()
        self.assertEqual(p.comparison(self.report(), before)["status"], "SCOPE_CHANGED")
        self.assertIsNone(p.comparison(self.report(), None)["delivery_delta"])

    def test_accepted_requires_complete_evidence(self):
        self.r["current_state"] = "ACCEPTED"
        with self.assertRaisesRegex(p.InvalidRegistry, "Disallowed ACCEPTED"):
            self.report()

    def test_stable_json_markdown(self):
        self.implement(1)
        first = self.report()
        self.assertEqual(json.dumps(first), json.dumps(self.report()))
        self.assertIn("50.0000%", p.markdown(first))
        self.assertEqual(p.markdown(first), p.markdown(self.report()))

    def test_no_false_operational_verification(self):
        self.r["obligations"][0]["environment"] = "production"
        self.rebaseline()
        self.verify()
        with self.assertRaisesRegex(p.InvalidRegistry, "False environment"):
            self.report()
        self.r["obligations"][0]["evidence"][-1]["environment"] = "production"
        with self.assertRaisesRegex(p.InvalidRegistry, "Synthetic production"):
            self.report()

    def test_artifact_tampering(self):
        self.verify()
        (self.root / "result.txt").write_text("tampered")
        self.assertEqual(self.report()["freshness"], {"STALE": 1})

    def test_atomic_output(self):
        path = self.root / "nested/result.json"
        p.atomic_write(path, "old")
        p.atomic_write(path, "new")
        self.assertEqual(path.read_text(), "new")
        self.assertEqual(list(path.parent.iterdir()), [path])

    def test_product_is_independent_and_requires_acceptance(self):
        self.implement()
        e = self.verify()
        self.assertEqual(self.report()["overall"], {"implementation": 100, "verification": 100, "product": 0})
        e = copy.deepcopy(e)
        e.update(type="browser", criteria_ids=["A1"], accepted_by="owner", authorization_ref="fixture approval")
        self.r["product_evidence"] = e
        self.r["current_state"] = "ACCEPTED"
        self.assertEqual(self.report()["overall"]["product"], 100)
        self.verify("FAIL")
        self.r["current_state"] = "FAILED"
        self.assertEqual(self.report()["overall"]["product"], 0)

    def test_verified_state_requires_evidence(self):
        self.r["current_state"] = "VERIFIED_OPERATIONAL"
        with self.assertRaisesRegex(p.InvalidRegistry, "Verified state"):
            self.report()

    def test_documentation_excluded_from_runtime(self):
        self.implement()
        self.r["type"] = "documentation"
        self.registry["weight_policy"]["implementation"] = {str(i): int(i == 1) for i in range(7)}
        # An implementation phase without runtime criteria must fail, not inflate the denominator.
        self.rebaseline()
        with self.assertRaisesRegex(p.InvalidRegistry, "without eligible"):
            self.report()

    def test_watch_tree_addition_invalidates(self):
        folder = self.root / "module"
        folder.mkdir()
        (folder / "existing.py").write_text("original")
        self.r["watch_paths"].append("module")
        self.rebaseline()
        self.implement()
        (folder / "new.py").write_text("new dependency")
        self.assertEqual(self.report()["overall"]["implementation"], 0)

    def test_path_escape_rejected(self):
        self.r["source_paths"] = ["../outside"]
        with self.assertRaisesRegex(p.InvalidRegistry, "Unsafe reference"):
            self.report()

    def test_historical_baseline_immutable(self):
        directory = self.root / "docs/progress/baselines"
        directory.mkdir(parents=True)
        file = directory / "v1.json"
        file.write_text("original")
        self.run_git("add", ".")
        self.run_git("commit", "-qm", "baseline")
        base = p.git(self.root, "rev-parse", "HEAD")
        file.write_text("edited")
        with self.assertRaisesRegex(p.InvalidRegistry, "Historical baseline"):
            p.check_history(self.root, base, self.registry)

    def test_different_scope_has_no_delivery_delta(self):
        before = self.report()
        self.r["criteria"][0]["description"] = "Changed goal"
        self.registry["scope_baseline"] = "test-v2"
        self.rebaseline()
        delta = p.comparison(self.report(), before)
        self.assertEqual(delta["status"], "SCOPE_CHANGED")
        self.assertIsNone(delta["delivery_delta"])

    def cli(self, *args):
        directory = self.root / "docs/progress"
        directory.mkdir(parents=True, exist_ok=True)
        self.registry["baseline_file"] = "docs/progress/baseline.json"
        (directory / "baseline.json").write_text(json.dumps(self.baseline))
        (directory / "vector-requirements.json").write_text(json.dumps(self.registry))
        output, error = io.StringIO(), io.StringIO()
        with patch.object(p, "ROOT", self.root), patch("sys.argv", ["vector_progress", "--as-of=2026-10-27", *args]), redirect_stdout(output), redirect_stderr(error):
            status = p.main()
        return status, output.getvalue(), error.getvalue()

    def test_cli_generate_strict_json_and_phase(self):
        self.implement(1)
        status, output, error = self.cli("--generate", "--format=json")
        self.assertEqual(status, 0, error)
        report = json.loads(output)
        self.assertEqual(report["overall"]["implementation"], 50)
        self.assertEqual(self.cli("--strict")[0], 0)
        status, output, error = self.cli("--phase=0", "--format=json")
        self.assertEqual(status, 0, error)
        self.assertEqual(len(json.loads(output)["phases"]), 1)

    def test_cli_detects_manually_edited_markdown(self):
        self.assertEqual(self.cli("--generate")[0], 0)
        path = self.root / "docs/progress/VECTOR_PROJECT_COMPLETION_SCORECARD.md"
        path.write_text("Invented 100%")
        status, _, error = self.cli("--strict")
        self.assertEqual(status, 2)
        self.assertIn("Markdown differs", error)

    def test_cli_strict_requires_snapshot(self):
        status, _, error = self.cli("--strict")
        self.assertEqual(status, 2)
        self.assertIn("Missing generated", error)

    def test_future_evidence_is_stale(self):
        self.verify()["date"] = "2027-01-01"
        self.assertEqual(self.report()["freshness"], {"STALE": 1})

    def test_accepted_evidence_expiry_decreases_score(self):
        self.implement()
        e = copy.deepcopy(self.verify())
        e.update(type="browser", criteria_ids=["A1"], accepted_by="owner", authorization_ref="fixture approval")
        self.r["product_evidence"] = e
        self.r["current_state"] = "ACCEPTED"
        self.assertEqual(self.report()["overall"]["product"], 100)
        self.today = date(2027, 1, 1)
        self.assertEqual(self.report()["overall"]["product"], 0)
        self.assertNotEqual(self.report()["requirements"][0]["state"], "ACCEPTED")


if __name__ == "__main__":
    unittest.main()
