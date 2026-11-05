"""Negative controls for sharding, eligibility and deployment provenance."""
import collections
import contextlib
import copy
import importlib.util
import io
import json
import os
from pathlib import Path
import tempfile
import subprocess
import tarfile
import hashlib
import unittest
from unittest.mock import patch
import xml.etree.ElementTree as ET

from scripts.ci.php_shards import discover, partition, verify
from scripts.ci.required_gate import JOBS, check_images, check_jobs
from scripts.ci.release_guard import GitHub, check_gate, check_manifest, select_run
from scripts.ci.check_isolation import verify as verify_isolation

ROOT = Path(__file__).resolve().parents[2]
SHA = "a" * 40
REPOSITORY = "vector/test"


def images():
    return [dict(target=t, sha=SHA, repository=REPOSITORY, tag=f"ghcr.io/{REPOSITORY}/{t}:sha-{SHA}",
                 published=True, digest="sha256:" + str(i) * 64)
            for i, t in enumerate(("app", "web", "research-worker"))]


class RequiredGateTests(unittest.TestCase):
    def test_every_dependency_must_succeed(self):
        needs = {job: {"result": "success"} for job in JOBS}
        check_jobs(needs)
        for job in JOBS:
            for result in ("failure", "cancelled", "skipped", None):
                with self.subTest(job=job, result=result), self.assertRaises(ValueError):
                    check_jobs({**needs, job: {"result": result}})
            with self.assertRaises(ValueError):
                check_jobs({k: v for k, v in needs.items() if k != job})

    def test_all_image_records_need_correct_identity_and_digest(self):
        check_images(images(), SHA, True)
        for field, value in (("sha", "b" * 40), ("digest", ""), ("published", False), ("tag", "latest")):
            records = images()
            records[0][field] = value
            with self.subTest(field=field), self.assertRaises(ValueError):
                check_images(records, SHA, True)
        for records in (images()[:2], images() + images()[:1], [images()[0]] * 3):
            with self.assertRaises(ValueError):
                check_images(records, SHA, True)


class ReleaseTests(unittest.TestCase):
    def run_fixture(self, **changes):
        return dict(id=12, run_attempt=2, head_sha=SHA, head_branch="main", event="push",
                    status="completed", conclusion="success", **changes)

    def test_no_failed_missing_wrong_sha_or_untrusted_ci_can_deploy(self):
        good = self.run_fixture()
        self.assertEqual(12, select_run([good], SHA)["id"])
        for changes in ({"head_sha": "b" * 40}, {"head_branch": "feature"}, {"event": "pull_request"},
                        {"conclusion": "failure"}, {"conclusion": "cancelled"}, {"status": "in_progress"}):
            with self.subTest(changes=changes), self.assertRaises(ValueError):
                select_run([{**good, **changes}], SHA)
        with self.assertRaises(ValueError):
            select_run([], SHA)
        with self.assertRaises(ValueError):
            select_run([good, {**good, "id": 13, "conclusion": "failure"}], SHA)

    def test_successful_workflow_without_aggregate_is_rejected(self):
        check_gate([dict(name="CI required", status="completed", conclusion="success")])
        for jobs in ([], [dict(name="CI required", status="completed", conclusion="skipped")],
                     [dict(name="old CI", status="completed", conclusion="success")]):
            with self.assertRaises(ValueError):
                check_gate(jobs)

    def test_stale_main_and_rerun_attempt_pagination(self):
        client = GitHub(REPOSITORY)
        fixture = self.run_fixture()
        responses = [dict(commit=dict(sha=SHA)), dict(workflow_runs=[fixture]),
                     dict(total_count=2, jobs=[dict(name="PHP", conclusion="success")]),
                     dict(total_count=2, jobs=[dict(name="CI required", status="completed", conclusion="success")])]
        with patch.object(client, "get", side_effect=responses) as api:
            self.assertEqual(12, client.authorize(SHA))
            self.assertIn("/attempts/2/jobs?", api.call_args_list[-1].args[0])
            self.assertIn("page=2", api.call_args_list[-1].args[0])
        with patch.object(client, "get", return_value=dict(commit=dict(sha="b" * 40))), self.assertRaises(ValueError):
            client.current(SHA)

    def test_manifest_must_come_from_exact_run_and_repository(self):
        manifest = dict(sha=SHA, run_id="12", images=images())
        check_manifest(manifest, SHA, 12, REPOSITORY)
        for sha, run, repo in (("b" * 40, 12, REPOSITORY), (SHA, 13, REPOSITORY), (SHA, 12, "other/repo")):
            with self.assertRaises(ValueError):
                check_manifest(manifest, sha, run, repo)


class ShardTests(unittest.TestCase):
    def test_files_partition_once_with_future_files_and_redis_concurrency_kept_together(self):
        files = {f"tests/{suite}/{i}Test.php": [f"Case::{i}"] for suite in ("Unit", "Feature", "Integration") for i in range(12)}
        for p in ("BinanceObservationRedisLockIntegrationTest.php", "PaperPipelineConcurrentWorkersIntegrationTest.php"):
            files["tests/Integration/" + p] = ["Case::redis"]
        first = partition(files, {})
        self.assertEqual(first, partition(dict(reversed(list(files.items()))), {}))
        self.assertEqual(collections.Counter(files.keys()), collections.Counter(p for paths in first.values() for p in paths))
        self.assertEqual(2, len(first["redis-1"]))

    def test_missing_duplicate_failure_and_unexpected_skip_are_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            plan = root / "plan.json"
            plan.write_text(json.dumps(dict(files={"a": ["Case::method#data"]}, lanes={"unit-1": ["a"]})))
            report = root / "unit-1.xml"
            good = '<testcase class="Case" name=\'method with data set "data"\' assertions="1"/>'
            report.write_text("<testsuite>" + good + "</testsuite>")
            with contextlib.redirect_stdout(io.StringIO()):
                verify(plan, root)
            for body in ("", good + good, good.replace("/>", "><failure/></testcase>"),
                         good.replace("/>", "><skipped/></testcase>")):
                report.write_text("<testsuite>" + body + "</testsuite>")
                with self.assertRaises(ValueError), contextlib.redirect_stdout(io.StringIO()):
                    verify(plan, root)

    def test_actual_postgres_and_redis_identities_must_be_distinct(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            plan = {"lanes": {"a": [], "b": []}}
            first = dict(database="bybit_test", postgres_system_identifier="100", redis_run_id="one", redis_prefix="vector-ci-a-")
            second = dict(database="bybit_test", postgres_system_identifier="200", redis_run_id="two", redis_prefix="vector-ci-b-")
            (root / "a-isolation.json").write_text(json.dumps(first))
            (root / "b-isolation.json").write_text(json.dumps(second))
            verify_isolation(plan, root)
            for key in ("postgres_system_identifier", "redis_run_id", "redis_prefix"):
                (root / "b-isolation.json").write_text(json.dumps({**second, key: first[key]}))
                with self.assertRaises(ValueError):
                    verify_isolation(plan, root)


class DigestDeploymentTests(unittest.TestCase):
    def test_cached_release_cannot_override_current_archive_or_change_approved_bits(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            archive = root / f"{SHA}.tar.gz"
            state = root / "state"
            manifest = dict(sha=SHA, run_id="12", images=images())
            env = {**os.environ, "APP_IMAGE": f"ghcr.io/{REPOSITORY}/app", "WEB_IMAGE": f"ghcr.io/{REPOSITORY}/web",
                   "RESEARCH_WORKER_IMAGE": f"ghcr.io/{REPOSITORY}/research-worker"}

            def pack(data):
                content = json.dumps(data).encode()
                with tarfile.open(archive, "w:gz") as stream:
                    info = tarfile.TarInfo("ci-release.json")
                    info.size = len(content)
                    stream.addfile(info, io.BytesIO(content))
                Path(str(archive) + ".sha256").write_text(hashlib.sha256(archive.read_bytes()).hexdigest() + "  " + archive.name + "\n")

            args = ["bash", str(ROOT / "docker/scripts/stage-release-manifest.sh"), str(archive), str(state), SHA]
            pack(manifest)
            subprocess.run(args, env=env, check=True, capture_output=True)
            approved = (state / f"sha-{SHA}.json").read_bytes()
            pack({**manifest, "run_id": "13"})
            subprocess.run(args, env=env, check=True, capture_output=True)
            self.assertEqual(approved, (state / f"sha-{SHA}.json").read_bytes())
            changed = copy.deepcopy(manifest)
            changed["images"][0]["digest"] = "sha256:" + "f" * 64
            pack(changed)
            self.assertNotEqual(0, subprocess.run(args, env=env, capture_output=True).returncode)
            self.assertEqual(approved, (state / f"sha-{SHA}.json").read_bytes())
            archive.write_bytes(b"corrupted")
            self.assertNotEqual(0, subprocess.run(args, env=env, capture_output=True).returncode)

    def test_pinned_images_keep_sha_aliases_for_existing_rollback_retention(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            env_file = root / ".env"
            env_file.write_text((ROOT / ".env.production.example").read_text())
            docker = root / "docker"
            docker.write_text('#!/bin/sh\nif [ "$MODE" = missing ]; then exit 1; fi\nprintf "%s\\n" "$*" >> "$CALLS"\n')
            docker.chmod(0o755)
            calls = root / "calls"
            reference = f"ghcr.io/{REPOSITORY}/app:sha-{SHA}@sha256:" + "0" * 64
            env = {**os.environ, "ENV_FILE": str(env_file), "DEPLOY_STATE_DIR": str(root), "REF": reference,
                   "CALLS": str(calls), "PATH": str(root) + os.pathsep + os.environ["PATH"]}
            args = ["bash", "-c", 'source docker/scripts/common.sh; retain_release_image "$REF"']
            subprocess.run(args, env=env, cwd=ROOT, check=True)
            self.assertIn(f"tag {reference} {reference.split('@')[0]}", calls.read_text())
            calls.unlink()
            result = subprocess.run(args, env={**env, "MODE": "missing"}, cwd=ROOT)
            self.assertNotEqual(0, result.returncode)
            self.assertFalse(calls.exists())

    def test_registry_mismatch_or_missing_digest_blocks_actual_cd_image_script(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            refs = root / "refs"
            digest = "sha256:" + "0" * 64
            refs.write_text("".join(f"ghcr.io/{REPOSITORY}/{target}:sha-{SHA}@{digest}\n" for target in ("app", "web", "research-worker")))
            docker = root / "docker"
            docker.write_text('#!/bin/sh\nif [ "$MODE" = missing ]; then exit 1; fi\nif [ "$MODE" = changed ]; then echo sha256:invalid; else echo "$EXPECTED"; fi\n')
            docker.chmod(0o755)
            for mode, expected in (("good", 0), ("changed", 1), ("missing", 1)):
                result = subprocess.run(["bash", str(ROOT / "scripts/ci/verify_images.sh"), str(refs)],
                                        env={**os.environ, "PATH": str(root) + os.pathsep + os.environ["PATH"],
                                             "MODE": mode, "EXPECTED": digest}, capture_output=True)
                self.assertEqual(expected, result.returncode, result.stderr)

    def test_all_ten_services_use_pinned_images_and_no_infrastructure_is_overridden(self):
        spec = importlib.util.spec_from_file_location("release_images", ROOT / "docker/scripts/release-images.py")
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        env = {"APP_IMAGE": f"ghcr.io/{REPOSITORY}/app", "WEB_IMAGE": f"ghcr.io/{REPOSITORY}/web",
               "RESEARCH_WORKER_IMAGE": f"ghcr.io/{REPOSITORY}/research-worker"}
        data = dict(sha=SHA, run_id="12", images=images())
        with patch.dict(os.environ, env):
            overlay = module.overlay(data, SHA)
            self.assertEqual(10, len(overlay["services"]))
            self.assertNotIn("postgres", overlay["services"])
            self.assertNotIn("redis", overlay["services"])
            for service in overlay["services"].values():
                self.assertIn("@sha256:", service["image"])
            bad = copy.deepcopy(data)
            bad["images"][0]["published"] = False
            with self.assertRaises(ValueError):
                module.overlay(bad, SHA)
            with patch.dict(os.environ, {"APP_IMAGE": "ghcr.io/other/app"}), self.assertRaises(ValueError):
                module.overlay(data, SHA)

    def test_real_compose_uses_digest_overlay_and_switches_for_rollback(self):
        spec = importlib.util.spec_from_file_location("release_images", ROOT / "docker/scripts/release-images.py")
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            env_file = root / ".env"
            content = (ROOT / ".env.production.example").read_text().replace("ghcr.io/OWNER/REPOSITORY", f"ghcr.io/{REPOSITORY}")
            env_file.write_text(content)
            overlay_dir = root / "release-images"
            overlay_dir.mkdir()
            env = {**os.environ, "ENV_FILE": str(env_file), "DEPLOY_STATE_DIR": str(root),
                   "APP_IMAGE": f"ghcr.io/{REPOSITORY}/app", "WEB_IMAGE": f"ghcr.io/{REPOSITORY}/web",
                   "RESEARCH_WORKER_IMAGE": f"ghcr.io/{REPOSITORY}/research-worker", "REDIS_PASSWORD": "isolated-test"}
            for sha in (SHA, "b" * 40):
                records = images()
                for record in records:
                    record["sha"] = sha
                    record["tag"] = record["tag"].replace(SHA, sha)
                with patch.dict(os.environ, env):
                    data = module.overlay(dict(sha=sha, run_id="12", images=records), sha)
                (overlay_dir / f"sha-{sha}.json").write_text(json.dumps(data))
                result = subprocess.check_output(["bash", "-c", 'source docker/scripts/common.sh; IMAGE_TAG="$TARGET_TAG" compose config --format json'],
                                                 cwd=ROOT, env={**env, "TARGET_TAG": "sha-" + sha}, text=True)
                services = json.loads(result)["services"]
                for name, override in data["services"].items():
                    self.assertEqual(override["image"], services[name]["image"])
                self.assertEqual("postgres:17-alpine", services["postgres"]["image"])
                self.assertEqual("redis:7.4-alpine", services["redis"]["image"])


if __name__ == "__main__":
    unittest.main()
