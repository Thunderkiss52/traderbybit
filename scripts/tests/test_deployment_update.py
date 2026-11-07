"""Failure injection at CLI boundaries; executes real update/backup/health/validation."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
OLD = 'sha-' + '1' * 40
NEW = 'sha-' + '2' * 40
PREVIOUS = 'sha-' + '0' * 40


class UpdateRecoveryTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix='vector-update-')
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        shutil.copytree(ROOT / 'docker', self.root / 'docker')
        self.bin = self.root / 'bin'
        self.bin.mkdir()
        shutil.copy(ROOT / 'scripts/tests/deployment_docker_fixture.py', self.bin / 'docker')
        (self.bin / 'docker').chmod(0o755)
        (self.bin / 'curl').write_text('#!/bin/sh\nexit 0\n')
        (self.bin / 'curl').chmod(0o755)
        (self.root / 'compose.yaml').write_text('{}')
        self.env_file = self.root / '.env'
        self.env_file.write_text('''ALLOW_TEST_PROJECT=1
COMPOSE_PROJECT_NAME=vector-update-fixture
UI_BASIC_AUTH_USER=fixture
UI_BASIC_AUTH_PASSWORD=fixture
POSTGRES_USER=fixture
POSTGRES_DB=fixture
REDIS_PASSWORD=fixture
APP_IMAGE=ghcr.io/vector/fixture/app
WEB_IMAGE=ghcr.io/vector/fixture/web
RESEARCH_WORKER_IMAGE=ghcr.io/vector/fixture/research-worker
MIN_FREE_DISK_GB=0
''')
        self.state = self.root / 'state'
        self.state.mkdir()
        for name, value in (('.deploy-current', OLD), ('.deploy-previous', PREVIOUS),
                            ('release-current', OLD[4:]), ('release-previous', PREVIOUS[4:])):
            (self.state / name).write_text(value + '\n')
        self.env = {**os.environ, 'ENV_FILE': str(self.env_file), 'DEPLOY_STATE_DIR': str(self.state),
                    'DOCKER_FIXTURE': str(self.root), 'NEW_IMAGE_TAG': NEW, 'DEPLOY_MODE': 'pull',
                    'PATH': str(self.bin) + os.pathsep + os.environ['PATH'], 'SKIP_DOCKER_INSPECT': '0',
                    'ALLOW_TEST_PROJECT': '1'}

    def update(self, mode='', new=NEW):
        (self.root / 'docker-state.json').write_text(json.dumps(dict(mode=mode, running=OLD, migrations='2026_10_20_initial')))
        result = subprocess.run(['bash', str(self.root / 'docker/scripts/update.sh')],
                                env={**self.env, 'NEW_IMAGE_TAG': new}, text=True, capture_output=True, timeout=30)
        self.output = result.stdout + result.stderr
        self.calls = [json.loads(line) for line in (self.root / 'calls.jsonl').read_text().splitlines()] if (self.root / 'calls.jsonl').exists() else []
        evidence = os.environ.get('VECTOR_DEPLOY_EVIDENCE')
        if evidence:
            Path(evidence).mkdir(parents=True, exist_ok=True)
            (Path(evidence) / f'{self.id().split(".")[-1]}-{mode or "success"}-{new[4:8]}.log').write_text(self.output)
        self.assertFalse(any('restart' in c['args'] for c in self.calls), self.output)
        self.assertFalse(any('rollback' in c['args'] for c in self.calls), self.output)
        return result

    def test_success_and_timing_summary(self):
        result = self.update()
        self.assertEqual(0, result.returncode, self.output)
        self.assertEqual(NEW, (self.state / '.deploy-current').read_text().strip())
        self.assertEqual(OLD, (self.state / '.deploy-previous').read_text().strip())
        self.assertIn('VECTOR_TIMING_SUMMARY', self.output)

    def test_same_sha_preserves_previous(self):
        result = self.update(new=OLD)
        self.assertEqual(0, result.returncode, self.output)
        self.assertEqual(PREVIOUS, (self.state / '.deploy-previous').read_text().strip())

    def test_backup_failures_preserve_running_services(self):
        for mode in ('backup-failure', 'backup-verification-failure'):
            with self.subTest(mode=mode):
                (self.root / 'calls.jsonl').unlink(missing_ok=True)
                result = self.update(mode)
                self.assertNotEqual(0, result.returncode)
                self.assertFalse(any('up' in c['args'] or 'migrate' in c['args'] for c in self.calls))
                self.assertEqual(OLD, (self.state / '.deploy-current').read_text().strip())

    def test_cleanup_failure_does_not_rollback_accepted_release(self):
        result = self.update('cleanup-failure')
        self.assertEqual(0, result.returncode, self.output)
        self.assertIn('Image cleanup failed; release retained', self.output)
        self.assertEqual(NEW, (self.state / '.deploy-current').read_text().strip())
        self.assertEqual(NEW, json.loads((self.root / 'docker-state.json').read_text())['running'])

    def test_pull_failure_preserves_running_and_state(self):
        result = self.update('pull-failure')
        self.assertNotEqual(0, result.returncode)
        self.assertFalse(any('up' in c['args'] for c in self.calls))
        self.assertEqual(OLD, (self.state / '.deploy-current').read_text().strip())

    def test_missing_image_preserves_running(self):
        result = self.update('missing-image')
        self.assertNotEqual(0, result.returncode)
        self.assertFalse(any('up' in c['args'] for c in self.calls))

    def test_migration_failure_never_rolls_back_or_transitions(self):
        result = self.update('migration-failure')
        self.assertNotEqual(0, result.returncode)
        self.assertFalse(any('up' in c['args'] for c in self.calls), self.output)
        self.assertEqual(OLD, (self.state / '.deploy-current').read_text().strip())

    def test_invalid_digest_manifest_fails_before_pull(self):
        records = [dict(target=t, sha=NEW[4:], repository='vector/fixture',
                        tag=f'ghcr.io/vector/fixture/{t}:{NEW}', published=True,
                        digest='sha256:invalid') for t in ('app', 'web', 'research-worker')]
        (self.root / 'ci-release.json').write_text(json.dumps(dict(sha=NEW[4:], run_id='12', images=records)))
        result = self.update()
        self.assertNotEqual(0, result.returncode)
        self.assertIn('Invalid image digest', self.output)
        self.assertFalse(any('pull' in c['args'] for c in self.calls))

    def test_wrong_revision_blocks_before_transition(self):
        result = self.update('wrong-revision')
        self.assertNotEqual(0, result.returncode)
        self.assertIn('OCI revision mismatch', self.output)
        self.assertFalse(any('up' in c['args'] for c in self.calls))

    def test_failed_migration_requires_review_before_retry(self):
        self.assertNotEqual(0, self.update('migration-failure').returncode)
        (self.root / 'calls.jsonl').write_text('')
        self.assertNotEqual(0, self.update().returncode)
        self.assertIn('explicit recovery review', self.output)
        self.assertFalse(any('migrate' in c['args'] for c in self.calls))

    def test_wrong_image_health_partial_and_interruption_roll_back(self):
        for mode in ('wrong-image', 'health-failure', 'partial-transition', 'interrupted'):
            with self.subTest(mode=mode):
                (self.root / 'calls.jsonl').unlink(missing_ok=True)
                result = self.update(mode)
                self.assertNotEqual(0, result.returncode, self.output)
                self.assertEqual(OLD, json.loads((self.root / 'docker-state.json').read_text())['running'], self.output)
                self.assertEqual(OLD, (self.state / '.deploy-current').read_text().strip())
                self.assertEqual(PREVIOUS, (self.state / '.deploy-previous').read_text().strip())
                self.assertIn('ROLLED_BACK', (self.state / 'update-in-progress').read_text())
                if (self.state / 'deploy-state.json').exists():
                    state_data = json.loads((self.state / 'deploy-state.json').read_text())
                    self.assertEqual('ROLLED_BACK', state_data['status'])
                    self.assertEqual(OLD, state_data['current_image_tag'])
                    self.assertEqual(OLD[4:], state_data['current_release_sha'])
                    self.assertIn('app', state_data.get('services', {}))

    def test_interrupted_migration_in_progress_blocks_predeploy_validator(self):
        (self.state / 'update-in-progress').write_text(f'{OLD} -> {NEW}: MIGRATING\n')
        val_res = subprocess.run(['bash', str(self.root / 'docker/scripts/validate-deployment-state.sh'), 'pre-deploy'],
                                 env=self.env, text=True, capture_output=True)
        self.assertNotEqual(0, val_res.returncode)
        self.assertIn('interrupted or schema-blocked update is pending', val_res.stdout + val_res.stderr)

    def test_forward_migration_blocks_application_rollback(self):
        result = self.update('forward-migration')
        self.assertNotEqual(0, result.returncode)
        self.assertIn('BLOCKED_SCHEMA_REVIEW', (self.state / 'update-in-progress').read_text())
        up = [c for c in self.calls if 'up' in c['args']]
        self.assertEqual([NEW], [c['tag'] for c in up], self.output)
        self.assertEqual(OLD, (self.state / '.deploy-current').read_text().strip())


if __name__ == '__main__':
    unittest.main()
