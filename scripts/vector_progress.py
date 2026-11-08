#!/usr/bin/env python3
"""Offline VECTOR scorecard. Standard library only; never boots the application."""
import argparse
from collections import Counter
from datetime import date, datetime, timezone
from fractions import Fraction
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[1]
STATES = {"NOT_STARTED", "IN_PROGRESS", "IMPLEMENTED_UNVERIFIED", "VERIFIED_LOCAL",
          "VERIFIED_INTEGRATION", "VERIFIED_OPERATIONAL", "ACCEPTED", "BLOCKED", "FAILED", "NOT_APPLICABLE"}
ENVIRONMENTS = {"local", "isolated_postgresql", "browser", "production", "documentation"}
TYPES = {"unit", "integration", "postgresql", "concurrency", "api", "frontend", "browser", "go",
         "reconciliation", "reproducibility", "performance", "operational", "document_review"}
METRICS = ("implementation", "verification", "product")


class InvalidRegistry(ValueError):
    pass


def require(condition, message):
    if not condition:
        raise InvalidRegistry(message)


def canonical(value):
    return json.dumps(value, sort_keys=True, separators=(",", ":"), ensure_ascii=False).encode()


def digest(value):
    return hashlib.sha256(canonical(value)).hexdigest()


def git(root, *args):
    return subprocess.check_output(["git", "-C", str(root), *args], stderr=subprocess.DEVNULL, text=True).strip()


PATH_ALIASES = {
    ".github/workflows/deploy.yml": ".github/workflows/ci.yml",
}


def safe_path(root, value):
    aliased = PATH_ALIASES.get(value, value)
    path = (root / aliased).resolve()
    require(not Path(aliased).is_absolute() and path.is_relative_to(root.resolve()), "Unsafe reference: " + value)
    return path


def fingerprint(root, paths):
    """Include additions/deletions in watched trees; no symlink escape or .env reads."""
    entries = {}
    for ref in sorted(set(paths)):
        path = safe_path(root, ref)
        require(path.exists(), "Missing watched path: " + ref)
        children = sorted(path.rglob("*")) if path.is_dir() else [path]
        for child in children:
            require(not child.is_symlink(), "Symlink in watched paths")
            if child.is_file():
                entries[str(child.relative_to(root))] = hashlib.sha256(child.read_bytes()).hexdigest()
    return digest(entries)


def scope(registry):
    """Freeze denominator AND criterion semantics, ownership, gates and dependencies."""
    requirements = []
    for r in registry["requirements"]:
        item = {k: r[k] for k in ("id", "phase", "parent_capability", "title", "description", "type",
                                  "priority", "weight", "dependencies", "acceptance_criteria", "watch_paths")}
        item["criteria"] = [{k: c[k] for k in ("id", "description")} for c in r["criteria"]]
        item["obligations"] = [{k: v for k, v in o.items() if k != "evidence"} for o in r["obligations"]]
        requirements.append(item)
    return {"weight_policy": registry["weight_policy"], "capabilities": registry["capabilities"], "requirements": requirements}


def validate(registry, root, baseline):
    require(registry.get("registry_version") == 1, "Unsupported registry version")
    require(registry.get("scope_baseline") == baseline.get("id"), "Scope baseline ID mismatch")
    require(scope(registry) == baseline.get("scope"), "Scope changed: explicit new baseline and approval record required")
    require(baseline.get("approval", {}).get("status") in {"PROPOSED", "APPROVED"}, "Missing scope approval record")
    require(bool(baseline.get("change_log")), "Missing scope change log")
    capabilities = registry["capabilities"]
    cap_ids = [c["id"] for c in capabilities]
    require(len(cap_ids) == len(set(cap_ids)), "Duplicate capability")
    ids = [r["id"] for r in registry["requirements"]]
    require(len(ids) == len(set(ids)), "Duplicate requirement IDs")
    criteria_ids, obligation_ids, runtime_atoms = set(), set(), set()
    require(all(any(r["parent_capability"] == c for r in registry["requirements"]) for c in cap_ids), "Capability without requirements")
    for metric in METRICS:
        weights = registry["weight_policy"][metric]
        require(set(weights) == set(map(str, range(7))), "All seven phase weights required")
        require(all(type(w) is int and w >= 0 for w in weights.values()) and sum(weights.values()) > 0, "Invalid phase weights")
    by_id = {r["id"]: r for r in registry["requirements"]}
    for r in registry["requirements"]:
        for key in ("notes", "blocking_issues", "source_paths", "test_paths", "last_verified_commit",
                    "last_verified_environment", "last_evidence_update", "assessment", "current_state", "product_evidence"):
            require(key in r, f"{r['id']}: missing {key}")
        require(r["phase"] in range(7), "Invalid phase")
        require(r["parent_capability"] in cap_ids, "Unknown capability")
        require(next(c["phase"] for c in capabilities if c["id"] == r["parent_capability"]) == r["phase"], "Capability phase mismatch")
        require(r["type"] in {"runtime", "assurance", "documentation"}, "Invalid type")
        require(r["current_state"] in STATES, "Invalid state")
        require(r["current_state"] != "NOT_APPLICABLE", "N/A requires explicit scope revision; never silently exclude")
        require(type(r["weight"]) is int and r["weight"] > 0, "Invalid weight")
        require(r["priority"] in (0, 1, 2), "Invalid priority")
        require(all(d in by_id and d != r["id"] for d in r["dependencies"]), "Invalid dependency")
        require(bool(r["criteria"]) and bool(r["obligations"]) and bool(r["acceptance_criteria"]), "Empty criteria/obligations")
        for ref in r["source_paths"] + r["test_paths"] + r["watch_paths"]:
            require(safe_path(root, ref).exists(), "Missing reference: " + ref)
        for c in r["criteria"]:
            require(c["id"] not in criteria_ids, "Duplicate implementation criterion (double-counting)")
            criteria_ids.add(c["id"])
            if r["type"] == "runtime":
                atom = " ".join(c["description"].lower().split())
                require(atom not in runtime_atoms, "Duplicate runtime atom ownership")
                runtime_atoms.add(atom)
            require(c["status"] in {"IMPLEMENTED", "PLANNED", "UNKNOWN"} and bool(c["description"]), "Invalid criterion")
            if c["status"] == "IMPLEMENTED":
                e = c.get("evidence")
                require(bool(e) and bool(e.get("references")), "Implemented criterion lacks source evidence")
                validate_record(e, root, source=True)
                if r["type"] == "runtime":
                    require(all(not ref["path"].endswith(".md") and not ref["path"].startswith("tests/")
                                for ref in e["references"]), "Documentation/tests cannot implement runtime functionality")
        for o in r["obligations"]:
            require(o["id"] not in obligation_ids, "Duplicate verification obligation")
            obligation_ids.add(o["id"])
            require(o["type"] in TYPES and o["environment"] in ENVIRONMENTS, "Invalid verification class/environment")
            require(type(o["weight"]) is int and o["weight"] > 0, "Invalid obligation weight")
            require(type(o["max_age_days"]) is int and o["max_age_days"] >= 0, "Invalid evidence lifetime")
            for e in o["evidence"]:
                validate_record(e, root)
                require(e["requirement_id"] == r["id"] and e["obligation_id"] == o["id"], "Evidence ownership mismatch")
                require(e["type"] == o["type"], "Evidence type mismatch")
                require(e["environment"] == o["environment"], "False environment verification")
                require(not (e["environment"] == "production" and e["synthetic"]), "Synthetic production evidence prohibited")
                require(e["environment"] != "production" or bool(e.get("authorization_ref")), "Production evidence requires authorization reference")
        if r["current_state"].startswith("VERIFIED_"):
            require(all(o["evidence"] and o["evidence"][-1]["result"] == "PASS" for o in r["obligations"]), "Verified state without required evidence")
        if r["product_evidence"]:
            e = r["product_evidence"]
            validate_record(e, root)
            require(e["requirement_id"] == r["id"] and e["type"] in {"browser", "operational", "document_review"}, "Invalid product evidence")
            require(e["environment"] != "production" or not e["synthetic"], "Synthetic production acceptance prohibited")
            require(r["type"] != "documentation" or e["type"] == "document_review", "Documentation needs review acceptance")
            require(set(e["criteria_ids"]) == {c["id"] for c in r["acceptance_criteria"]}, "Incomplete product demonstration")
            require(bool(e.get("accepted_by")) and bool(e.get("authorization_ref")), "Product acceptance requires explicit actor/authorization")
        if r["current_state"] == "ACCEPTED":
            require(r["product_evidence"] is not None and all(c["status"] == "IMPLEMENTED" for c in r["criteria"])
                    and all(o["evidence"] and o["evidence"][-1]["result"] == "PASS" for o in r["obligations"]),
                    "Disallowed ACCEPTED state: incomplete implementation/evidence")
    visited, visiting = set(), set()
    def visit(key):
        require(key not in visiting, "Circular dependencies")
        if key in visited:
            return
        visiting.add(key)
        for dep in by_id[key]["dependencies"]:
            visit(dep)
        visiting.remove(key)
        visited.add(key)
    for key in ids:
        visit(key)


def validate_record(e, root, source=False):
    keys = ("git_sha", "date", "environment", "dependency_hash", "limitations")
    for key in keys:
        require(key in e, "Evidence missing " + key)
    require(e["environment"] in ENVIRONMENTS, "Invalid evidence environment")
    date.fromisoformat(e["date"])
    require(len(e["git_sha"]) == 40, "Evidence requires full Git SHA")
    if source:
        require(bool(e.get("reviewer")), "Source assessment requires reviewer")
        for ref in e["references"]:
            path = safe_path(root, ref["path"])
            require(path.is_file() and bool(ref["anchor"]), "Missing source reference/anchor")
    else:
        for key in ("requirement_id", "type", "result", "fixture", "synthetic", "reviewer", "artifact", "artifact_sha256"):
            require(key in e, "Evidence missing " + key)
        require(e["result"] in {"PASS", "FAIL", "SKIPPED", "UNAVAILABLE"}, "Invalid evidence result")
        require(type(e["synthetic"]) is bool and bool(e["fixture"]) and bool(e["reviewer"]), "Incomplete evidence provenance")
        require(safe_path(root, e["artifact"]).is_file(), "Missing evidence artifact")


def freshness(e, r, root, as_of, max_age=None, source=False):
    if not e:
        return "UNAVAILABLE"
    if not source and e["result"] != "PASS":
        return {"FAIL": "FAILED", "SKIPPED": "SKIPPED", "UNAVAILABLE": "UNAVAILABLE"}[e["result"]]
    age = (as_of - date.fromisoformat(e["date"])).days
    if age < 0 or (max_age is not None and age > max_age):
        return "STALE"
    try:
        subprocess.run(["git", "-C", str(root), "merge-base", "--is-ancestor", e["git_sha"], "HEAD"],
                       check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    except subprocess.CalledProcessError:
        return "STALE"
    if fingerprint(root, r["watch_paths"]) != e["dependency_hash"]:
        return "STALE"
    if source:
        if any(ref["anchor"] not in safe_path(root, ref["path"]).read_text() for ref in e["references"]):
            return "STALE"
    elif hashlib.sha256(safe_path(root, e["artifact"]).read_bytes()).hexdigest() != e["artifact_sha256"]:
        return "STALE"
    return "CURRENT"


def percent(value):
    return round(float(value * 100), 4)


def calculate(registry, root, baseline, as_of):
    validate(registry, root, baseline)
    rows, freshness_counts = [], Counter()
    for r in registry["requirements"]:
        criteria = [c["status"] == "IMPLEMENTED" and freshness(c.get("evidence"), r, root, as_of, source=True) == "CURRENT"
                    for c in r["criteria"]]
        statuses = []
        for o in r["obligations"]:
            # Append order is authoritative. A later failure invalidates earlier passes.
            e = o["evidence"][-1] if o["evidence"] else None
            statuses.append(freshness(e, r, root, as_of, o["max_age_days"]))
        freshness_counts.update(statuses)
        implementation = Fraction(sum(criteria), len(criteria))
        verification = Fraction(sum(o["weight"] for o, s in zip(r["obligations"], statuses) if s == "CURRENT"),
                                sum(o["weight"] for o in r["obligations"]))
        accepted = implementation == 1 and verification == 1 and freshness(r["product_evidence"], r, root, as_of, 30) == "CURRENT"
        state = "IMPLEMENTED_UNVERIFIED" if implementation == 1 else "IN_PROGRESS" if implementation else "NOT_STARTED"
        if "FAILED" in statuses:
            state = "FAILED"
        elif verification == 1:
            environments = {o["environment"] for o in r["obligations"]}
            state = "VERIFIED_OPERATIONAL" if "production" in environments else "VERIFIED_INTEGRATION" if "isolated_postgresql" in environments else "VERIFIED_LOCAL"
        elif r["blocking_issues"]:
            state = "BLOCKED"
        rows.append({"id": r["id"], "phase": r["phase"], "title": r["title"], "type": r["type"],
                     "priority": r["priority"], "weight": r["weight"], "capability": r["parent_capability"],
                     "implementation": implementation, "verification": verification, "product": Fraction(int(accepted)),
                     "state": state, "blockers": r["blocking_issues"], "dependencies": r["dependencies"],
                     "missing_evidence": [o["id"] + ":" + s for o, s in zip(r["obligations"], statuses) if s != "CURRENT"]})
    by_id = {r["id"]: r for r in rows}
    def gate(row):
        if row["product"] and any(not gate(by_id[d]) for d in row["dependencies"]):
            row["product"] = Fraction(0)
        if row["product"]:
            row["state"] = "ACCEPTED"
        return bool(row["product"])
    for row in rows:
        gate(row)
    phases, overall = [], {m: Fraction(0) for m in METRICS}
    for phase in range(7):
        members = [r for r in rows if r["phase"] == phase]
        scores = {}
        for metric in METRICS:
            eligible = [r for r in members if metric != "implementation" or r["type"] == "runtime"]
            total = sum(r["weight"] for r in eligible)
            fraction = sum((r[metric] * r["weight"] for r in eligible), Fraction(0)) / total if total else None
            scores[metric] = percent(fraction) if fraction is not None else None
            weight = registry["weight_policy"][metric][str(phase)]
            require(fraction is not None or weight == 0, "Positive phase weight without eligible requirements")
            overall[metric] += (fraction or 0) * weight
        phases.append({"phase": phase, "requirements": len(members), "implemented": sum(r["implementation"] == 1 and r["type"] == "runtime" for r in members),
                       "verified": sum(r["verification"] == 1 for r in members),
                       "accepted_capabilities": sum(all(r["product"] for r in members if r["capability"] == c["id"])
                                                    for c in registry["capabilities"] if c["phase"] == phase),
                       **scores, "remaining": [r["id"] for r in members if not r["product"]],
                       "critical_blockers": [r["id"] for r in members if r["priority"] == 0 and not r["product"]]})
    ordered = sorted(rows, key=lambda r: (r["priority"], r["phase"], r["id"]))
    highest = next((r["id"] for r in ordered if r["implementation"] == 1 and r["verification"] < 1 and r["type"] == "runtime"), None)
    p0 = [r["id"] for r in ordered if r["phase"] == 0 and not r["product"]]
    for row in rows:
        for metric in METRICS:
            row[metric] = percent(row[metric])
    return {"status": "PROVISIONAL" if baseline["approval"]["status"] != "APPROVED" or freshness_counts.get("UNAVAILABLE", 0) or any(r["assessment"] == "UNKNOWN" for r in registry["requirements"]) else "ASSESSED",
            "source_head": git(root, "rev-parse", "HEAD"), "assessment_head": registry["assessment_head"],
            "assessment_date": as_of.isoformat(), "scope_baseline": registry["scope_baseline"],
            "scope_hash": digest(scope(registry)), "registry_hash": digest(registry),
            "overall": {m: percent(overall[m] / sum(registry["weight_policy"][m].values())) for m in METRICS},
            "freshness": dict(sorted(freshness_counts.items())), "phases": phases, "requirements": rows,
            "next_actions": {"unverified_implemented": highest, "phase0_blocker": p0[0] if p0 else None,
                             "next_increment": "Phase 0 evidence closure" if p0 else "Phase 1 descriptor/schema and all-13 compatibility",
                             "preventing_phase1": p0}}


def comparison(report, previous):
    if previous is None:
        return {"status": "NO_COMPARABLE_SNAPSHOT", "delivery_delta": None}
    if previous["scope_hash"] != report["scope_hash"]:
        return {"status": "SCOPE_CHANGED", "delivery_delta": None,
                "previous_scope": previous["scope_baseline"], "current_scope": report["scope_baseline"],
                "note": "Raw differences are not delivery progress; rebaseline explicitly."}
    return {"status": "COMPARABLE", "delivery_delta": {m: round(report["overall"][m] - previous["overall"][m], 4) for m in METRICS}}


def markdown(report):
    lines = ["# VECTOR Project Completion Scorecard", "", f"**{report['status']}** — independently calculated scores; not product readiness.", "",
             f"Source HEAD: `{report['source_head']}`. Assessment source: `{report['assessment_head']}`.",
             f"Assessment date: {report['assessment_date']}; scope: `{report['scope_baseline']}`.",
             f"Registry hash: `{report['registry_hash']}`.", "", "| Metric | Completion |", "|---|---:|"]
    lines += [f"| {m.title()} | {report['overall'][m]:.4f}% |" for m in METRICS]
    lines += ["", "Phase 6 documentation is excluded from runtime Implementation, not counted as live trading.", "",
              "| Phase | Requirements | Implemented runtime | Verified | Accepted capabilities | Implementation % | Verification % | Product % |",
              "|---|---:|---:|---:|---:|---:|---:|---:|"]
    for p in report["phases"]:
        values = [p[k] for k in ("phase", "requirements", "implemented", "verified", "accepted_capabilities", *METRICS)]
        lines.append("| " + " | ".join("N/A" if v is None else str(v) for v in values) + " |")
    lines += ["", "Evidence freshness: `" + json.dumps(report["freshness"], sort_keys=True) + "`.", "", "## Immediate next actions", ""]
    lines += [f"- {k}: {v}" for k, v in report["next_actions"].items()]
    lines += ["", "## Baseline comparison", "", json.dumps(report.get("comparison", comparison(report, None)), sort_keys=True), "",
              "## Remaining requirements and evidence", ""]
    for r in report["requirements"]:
        if r["product"] != 100:
            lines.append(f"- **{r['id']}** ({r['state']}): {r['title']}. Blockers: {'; '.join(r['blockers']) or 'Acceptance pending'}. Missing: {', '.join(r['missing_evidence']) or 'Product acceptance'}.")
    return "\n".join(lines) + "\n"


def traceability(registry, report):
    lines = ["# VECTOR Requirements Traceability", "", f"Scope `{registry['scope_baseline']}`; source `{report['assessment_head']}`.", "",
             "F/I entries are assurance obligations, not duplicate runtime delivery credit. JSON contains atomic criteria and evidence records.", "",
             "| Requirement | Phase / capability | Criterion IDs | Source | Tests | Dependencies |", "|---|---|---|---|---|---|"]
    for r in registry["requirements"]:
        lines.append("| " + " | ".join([r["id"], f"{r['phase']} / {r['parent_capability']}",
                     ", ".join(c["id"] for c in r["criteria"]), ", ".join(r["source_paths"]) or "Planned",
                     ", ".join(r["test_paths"]) or "Not mapped", ", ".join(r["dependencies"]) or "None"]) + " |")
    return "\n".join(lines) + "\n"


def atomic_write(path, content):
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, temporary = tempfile.mkstemp(dir=path.parent, prefix=".vector-progress-")
    try:
        with os.fdopen(fd, "w") as stream:
            stream.write(content)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def check_history(root, base_ref, registry):
    """Existing baselines/snapshots are append-only; compare against trusted Git base."""
    if not base_ref:
        return
    tracked = git(root, "ls-tree", "-r", "--name-only", base_ref).splitlines()
    for name in tracked:
        if name.startswith(("docs/progress/baselines/", "docs/progress/history/")):
            expected = subprocess.check_output(["git", "-C", str(root), "show", base_ref + ":" + name])
            require(safe_path(root, name).is_file() and safe_path(root, name).read_bytes() == expected,
                    "Historical baseline/snapshot changed: " + name)
    old_path = "docs/progress/vector-requirements.json"
    if old_path not in tracked:
        return
    old = json.loads(git(root, "show", base_ref + ":" + old_path))
    snapshot_path = "docs/progress/current.json"
    if snapshot_path in tracked and safe_path(root, snapshot_path).exists():
        old_snapshot = json.loads(git(root, "show", base_ref + ":" + snapshot_path))
        new_snapshot = json.loads(safe_path(root, snapshot_path).read_text())
        require(old_snapshot["overall"] == new_snapshot["overall"] or digest(old) != digest(registry),
                "Committed score changes require registry/evidence updates, including invalidation reasons")
    if scope(old) != scope(registry):
        require(old["scope_baseline"] != registry["scope_baseline"], "Scope change requires a new baseline ID")
        base = json.loads(safe_path(root, registry["baseline_file"]).read_text())
        require(base["approval"]["status"] == "APPROVED" and bool(base["approval"].get("authorization_ref")),
                "Subsequent scope changes require explicit approval")
    previous = {r["id"]: r for r in old["requirements"]}
    for r in registry["requirements"]:
        if r["id"] not in previous:
            continue
        prior = previous[r["id"]]
        old_obligations = {o["id"]: o for o in prior["obligations"]}
        for o in r["obligations"]:
            before = old_obligations.get(o["id"], {}).get("evidence", [])
            require(o["evidence"][:len(before)] == before, "Evidence attempts must be append-only")
        if prior["current_state"] != r["current_state"]:
            require(r.get("state_change", {}).get("from") == prior["current_state"] and bool(r["state_change"].get("reason")),
                    "State transition requires prior state and reason")
            forward = {"NOT_STARTED": {"IN_PROGRESS", "IMPLEMENTED_UNVERIFIED", "BLOCKED", "FAILED"},
                       "IN_PROGRESS": {"IMPLEMENTED_UNVERIFIED", "BLOCKED", "FAILED"},
                       "IMPLEMENTED_UNVERIFIED": {"VERIFIED_LOCAL", "VERIFIED_INTEGRATION", "VERIFIED_OPERATIONAL", "BLOCKED", "FAILED"},
                       "VERIFIED_LOCAL": {"VERIFIED_INTEGRATION", "VERIFIED_OPERATIONAL", "ACCEPTED", "BLOCKED", "FAILED", "IN_PROGRESS"},
                       "VERIFIED_INTEGRATION": {"VERIFIED_OPERATIONAL", "ACCEPTED", "BLOCKED", "FAILED", "IN_PROGRESS"},
                       "VERIFIED_OPERATIONAL": {"ACCEPTED", "BLOCKED", "FAILED", "IN_PROGRESS"},
                       "ACCEPTED": {"BLOCKED", "FAILED", "IN_PROGRESS", "IMPLEMENTED_UNVERIFIED"},
                       "BLOCKED": {"IN_PROGRESS", "IMPLEMENTED_UNVERIFIED", "FAILED"},
                       "FAILED": {"IN_PROGRESS", "BLOCKED"}}
            require(r["current_state"] in forward.get(prior["current_state"], set()), "Disallowed state transition")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--registry", type=Path, default=ROOT / "docs/progress/vector-requirements.json")
    parser.add_argument("--format", choices=("table", "json", "markdown"), default="table")
    parser.add_argument("--phase", type=int, choices=range(7))
    parser.add_argument("--strict", action="store_true", help="Validate scope, references and stored snapshot consistency; missing evidence is reported, not waived")
    parser.add_argument("--output", type=Path)
    parser.add_argument("--compare", type=Path)
    parser.add_argument("--base-ref", help="Trusted prior Git ref for immutable history/scope/state checks")
    parser.add_argument("--as-of", type=date.fromisoformat, default=datetime.now(timezone.utc).date())
    parser.add_argument("--generate", action="store_true", help="Regenerate scorecard/traceability/current JSON, never history")
    args = parser.parse_args()
    try:
        registry = json.loads(args.registry.read_text())
        baseline = json.loads(safe_path(ROOT, registry["baseline_file"]).read_text())
        check_history(ROOT, args.base_ref, registry)
        report = calculate(registry, ROOT, baseline, args.as_of)
        previous = json.loads(args.compare.read_text()) if args.compare else None
        report["comparison"] = comparison(report, previous)
        current = ROOT / "docs/progress/current.json"
        if args.strict:
            require(current.exists(), "Missing generated current snapshot")
        if args.strict and current.exists():
            saved = json.loads(current.read_text())
            require(saved["registry_hash"] == report["registry_hash"] and saved["scope_hash"] == report["scope_hash"], "Regenerate scorecard: registry/scope changed")
            require(saved["overall"] == report["overall"] and saved["phases"] == report["phases"] and saved["freshness"] == report["freshness"], "Regenerate scorecard: evidence freshness/results changed")
            require((ROOT / "docs/progress/VECTOR_PROJECT_COMPLETION_SCORECARD.md").read_text() == markdown(saved), "Generated Markdown differs from snapshot")
            require((ROOT / "docs/progress/VECTOR_REQUIREMENTS_TRACEABILITY.md").read_text() == traceability(registry, saved), "Generated traceability differs from registry")
        if args.generate:
            require(args.phase is None, "Cannot generate a partial canonical report")
            atomic_write(current, json.dumps(report, indent=2) + "\n")
            atomic_write(ROOT / "docs/progress/VECTOR_PROJECT_COMPLETION_SCORECARD.md", markdown(report))
            atomic_write(ROOT / "docs/progress/VECTOR_REQUIREMENTS_TRACEABILITY.md", traceability(registry, report))
        if args.phase is not None:
            report["phases"] = [p for p in report["phases"] if p["phase"] == args.phase]
            report["requirements"] = [r for r in report["requirements"] if r["phase"] == args.phase]
        if args.format == "json":
            output = json.dumps(report, indent=2) + "\n"
        elif args.format == "markdown":
            output = markdown(report)
        else:
            output = f"VECTOR {report['status']} | {report['source_head']} | {report['scope_baseline']}\n"
            output += " / ".join(f"{m}: {report['overall'][m]:.4f}%" for m in METRICS) + "\n"
            output += "\n".join(f"Phase {p['phase']}: {p['requirements']} requirements | I={p['implementation']} V={p['verification']} P={p['product']}" for p in report["phases"]) + "\n"
            output += json.dumps(report["next_actions"], sort_keys=True) + "\n"
        if args.output:
            atomic_write(args.output, output)
        else:
            print(output, end="")
    except (InvalidRegistry, ValueError, KeyError, OSError, subprocess.CalledProcessError) as error:
        print("Progress validation failed: " + str(error), file=sys.stderr)
        return 2
    return 0


if __name__ == "__main__":
    sys.exit(main())
