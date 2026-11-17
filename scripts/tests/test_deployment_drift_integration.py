"""Real Docker registry/Compose regression; all resources belong to a unique fixture."""
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
import unittest
import uuid

ROOT = Path(__file__).resolve().parents[2]
SERVICES = ['app', 'web', 'queue', 'queue-bybit', 'queue-binance', 'scheduler',
            'market-ws', 'audit-worker', 'backtest-worker', 'research-worker']
SHA = '832de9b5c2ff5c25351c25fa2e110a78a5f574ca'


def run(*args, **kwargs):
    return subprocess.run(args, text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, **kwargs)


class DeploymentDriftIntegrationTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.tmp = tempfile.TemporaryDirectory(prefix='vector-drift-')
        cls.root = Path(cls.tmp.name)
        cls.project = 'vector-drift-' + uuid.uuid4().hex[:10]
        cls.registry = cls.project + '-registry'
        (cls.root / '.dockerignore').write_text('*\n!Dockerfile\n')
        cls.refs = {}
        cls.owned_tags = []
        cls.addClassCleanup(cls.cleanup)
        shutil.copytree(ROOT / 'docker', cls.root / 'docker')
        (cls.root / 'state/release-images').mkdir(parents=True)
        cls.env_file = cls.root / '.env'
        cls.env_file.write_text(f'''ALLOW_TEST_PROJECT=1
COMPOSE_PROJECT_NAME={cls.project}
UI_BASIC_AUTH_USER=fixture
UI_BASIC_AUTH_PASSWORD=fixture
IMAGE_TAG=sha-{SHA}
''')
        with socket.socket() as reservation:
            reservation.bind(('127.0.0.1', 0))
            registry_port = reservation.getsockname()[1]
        (cls.root / 'registry-data').mkdir()
        (cls.root / 'registry.json').write_text(json.dumps({'services': {'registry': {
            'image': 'registry:2', 'user': f'{os.getuid()}:{os.getgid()}',
            'volumes': [f'{cls.root}/registry-data:/var/lib/registry'], 'ports': [f'127.0.0.1:{registry_port}:5000'],
            'labels': {'vector.fixture': cls.project}}}}))
        result = cls.registry_compose('up', '-d')
        if result.returncode:
            raise RuntimeError(result.stdout)
        cls.registry = cls.registry_compose('ps', '-q', 'registry').stdout.strip()
        port = json.loads(run('docker', 'inspect', cls.registry, check=True).stdout)[0]['NetworkSettings']['Ports']['5000/tcp'][0]['HostPort']
        for _ in range(150):
            import urllib.request
            try:
                urllib.request.urlopen(f'http://127.0.0.1:{port}/v2/', timeout=1).close()
                break
            except OSError:
                time.sleep(.1)
        else:
            raise RuntimeError('Isolated registry did not become ready')
        for target in ('app', 'web', 'research-worker'):
            repo = f'127.0.0.1:{port}/{cls.project}/{target}'
            tag = f'{repo}:sha-{SHA}'
            cls.owned_tags.append(tag)
            (cls.root / 'Dockerfile').write_text(f'''FROM alpine:latest
LABEL org.opencontainers.image.revision={SHA}
LABEL vector.fixture={cls.project}
LABEL vector.target={target}
CMD ["sleep", "3600"]
''')
            result = run('docker', 'build', '-t', tag, str(cls.root))
            if result.returncode:
                raise RuntimeError(result.stdout)
            result = run('docker', 'push', tag)
            if result.returncode:
                raise RuntimeError(result.stdout)
            metadata = json.loads(run('docker', 'image', 'inspect', tag, check=True).stdout)[0]
            digest = next(d.split('@')[1] for d in metadata['RepoDigests'] if d.startswith(repo + '@'))
            cls.refs[target] = tag + '@' + digest
        services = {s: {'image': cls.refs[s if s in ('web', 'research-worker') else 'app'],
                        'network_mode': 'none', 'stop_grace_period': '1s', 'labels': {'vector.fixture': cls.project},
                        'healthcheck': {'test': ['CMD', 'true'], 'interval': '1s'}} for s in SERVICES}
        (cls.root / 'compose.yaml').write_text(json.dumps({'services': services}))
        cls.overlay = {'services': {s: {'image': v['image']} for s, v in services.items()}}
        (cls.root / f'state/release-images/sha-{SHA}.json').write_text(json.dumps(cls.overlay))
        for name, value in (('.deploy-current', 'sha-' + SHA), ('release-current', SHA)):
            (cls.root / 'state' / name).write_text(value + '\n')
        cls.env = {**os.environ, 'ENV_FILE': str(cls.env_file), 'DEPLOY_STATE_DIR': str(cls.root / 'state'),
                   'ALLOW_TEST_PROJECT': '1', 'SKIP_DOCKER_INSPECT': '0'}
        cls.compose('up', '-d', '--wait', '--wait-timeout', '30', check=True)
        cls.evidence = []
        for s in SERVICES:
            ref = services[s]['image']
            cid = cls.compose('ps', '-q', s).stdout.strip()
            actual = json.loads(run('docker', 'inspect', cid, check=True).stdout)[0]
            cls.evidence.append(dict(service=s, expected=ref, compose=ref,
                                     config_image=actual['Config']['Image'], image_id=actual['Image'],
                                     old_suffix=actual['Config']['Image'].rsplit(':', 1)[1],
                                     expected_image_id=json.loads(run('docker', 'image', 'inspect', ref, check=True).stdout)[0]['Id']))
        evidence_dir = os.environ.get('VECTOR_DEPLOY_EVIDENCE')
        if evidence_dir:
            Path(evidence_dir).mkdir(parents=True, exist_ok=True)
            (Path(evidence_dir) / 'isolated-identities.json').write_text(json.dumps(cls.evidence, indent=2) + '\n')

    @classmethod
    def registry_compose(cls, *args):
        return run('docker', 'compose', '-p', cls.project + '-registry', '-f', str(cls.root / 'registry.json'), *args)

    @classmethod
    def compose(cls, *args, **kwargs):
        return run('docker', 'compose', '-p', cls.project, '-f', str(cls.root / 'compose.yaml'), *args, **kwargs)

    @classmethod
    def cleanup(cls):
        if (cls.root / 'compose.yaml').exists():
            cls.compose('down', '--timeout', '2')
        if (cls.root / 'registry.json').exists():
            cls.registry_compose('down', '--timeout', '2')
        else:
            run('docker', 'rm', '-f', cls.registry)
        for tag in cls.owned_tags:
            run('docker', 'image', 'rm', tag)
        cls.tmp.cleanup()

    def validator(self, mode='post-deploy'):
        return run('bash', str(self.root / 'docker/scripts/validate-deployment-state.sh'), mode, env=self.env)

    def helper(self, service, ref):
        return run('bash', '-c', 'source "$1"; verify_image_identity "$2" "$3"', '_',
                   str(self.root / 'docker/scripts/common.sh'), service, ref, env=self.env)

    def test_original_validator_reproduces_digest_suffix_failure(self):
        source = run('git', '-C', str(ROOT), 'show', f'{SHA}:docker/scripts/validate-deployment-state.sh', check=True).stdout
        original = self.root / 'docker/scripts/original-validator.sh'
        original.write_text(source)
        result = run('bash', str(original), 'post-deploy', env=self.env)
        self.assertEqual(1, result.returncode, result.stdout)
        self.assertIn('[FATAL] Post-deployment check failed: container drift detected.', result.stdout)
        self.assertEqual(3, len({record['old_suffix'] for record in self.evidence}))
        self.assertTrue(all(record['image_id'] == record['expected_image_id'] for record in self.evidence))
        evidence = os.environ.get('VECTOR_DEPLOY_EVIDENCE')
        if evidence:
            (Path(evidence) / 'original-validator-reproduction.log').write_text(result.stdout)

    def test_valid_digest_pinned_release(self):
        result = self.validator()
        self.assertEqual(0, result.returncode, result.stdout)

    def test_wrong_image_rejected_for_each_of_ten_services(self):
        for service in SERVICES:
            with self.subTest(service=service):
                expected = self.overlay['services'][service]['image']
                wrong = self.refs['web' if expected == self.refs['app'] else 'app']
                override = self.root / 'wrong.json'
                override.write_text(json.dumps({'services': {service: {'image': wrong}}}))
                run('docker', 'compose', '-p', self.project, '-f', str(self.root / 'compose.yaml'),
                    '-f', str(override), 'up', '-d', '--no-deps', service, check=True)
                try:
                    result = self.validator()
                    self.assertNotEqual(0, result.returncode, result.stdout)
                    self.assertIn(service, result.stdout)
                finally:
                    self.compose('up', '-d', '--wait', '--wait-timeout', '30', service, check=True)

    def test_cached_digest_pull_avoids_registry_and_missing_content_fails(self):
        start = time.monotonic()
        result = self.compose('pull', '--policy', 'always', 'app', 'web', 'research-worker')
        self.assertEqual(0, result.returncode, result.stdout)
        baseline_ms = (time.monotonic() - start) * 1000
        start = time.monotonic()
        result = run('bash', '-c', 'source "$1"; pull_release_images', '_',
                     str(self.root / 'docker/scripts/common.sh'), env=self.env)
        self.assertEqual(0, result.returncode, result.stdout)
        cached_ms = (time.monotonic() - start) * 1000
        self.registry_compose('stop', 'registry')
        try:
            result = run('bash', '-c', 'source "$1"; pull_release_images', '_',
                         str(self.root / 'docker/scripts/common.sh'), env=self.env)
            self.assertEqual(0, result.returncode, result.stdout)
            # A missing digest must still contact the registry and fail closed.
            path = self.root / f'state/release-images/sha-{SHA}.json'
            original = path.read_text()
            legacy = json.loads(original)
            for service in legacy['services'].values():
                service['image'] = service['image'].split('@')[0]
            path.write_text(json.dumps(legacy))
            result = run('bash', '-c', 'source "$1"; pull_release_images', '_',
                         str(self.root / 'docker/scripts/common.sh'), env=self.env)
            self.assertNotEqual(0, result.returncode, result.stdout)
            changed = json.loads(original)
            changed['services']['research-worker']['image'] = self.refs['research-worker'].split('@')[0] + '@sha256:' + '0' * 64
            path.write_text(json.dumps(changed))
            try:
                result = run('bash', '-c', 'source "$1"; pull_release_images', '_',
                             str(self.root / 'docker/scripts/common.sh'), env=self.env)
                self.assertNotEqual(0, result.returncode, result.stdout)
            finally:
                path.write_text(original)
        finally:
            self.registry_compose('start', 'registry')
            import urllib.request
            for _ in range(150):
                try:
                    urllib.request.urlopen('http://' + self.refs['app'].split('/')[0] + '/v2/', timeout=1).close()
                    break
                except OSError:
                    time.sleep(.1)
            else:
                self.fail('Isolated registry did not recover after offline cache test')
        evidence = os.environ.get('VECTOR_DEPLOY_EVIDENCE')
        if evidence:
            (Path(evidence) / 'pull-benchmark.json').write_text(json.dumps(dict(
                synthetic=True, always_pull_ms=baseline_ms, cached_digest_pull_ms=cached_ms,
                cached_with_registry_offline='PASS', missing_digest_with_registry_offline='REJECTED',
                scope='local registry, cached Alpine artifacts; not GHCR/VPS'), indent=2) + '\n')

    def test_equivalent_tag_and_image_id_creation_references(self):
        ref = self.refs['app']
        for creation_ref in (ref.split('@')[0], json.loads(run('docker', 'image', 'inspect', ref, check=True).stdout)[0]['Id']):
            override = self.root / 'alias.json'
            override.write_text(json.dumps({'services': {'app': {'image': creation_ref}}}))
            run('docker', 'compose', '-p', self.project, '-f', str(self.root / 'compose.yaml'),
                '-f', str(override), 'up', '-d', '--no-deps', 'app', check=True)
            try:
                result = self.helper('app', ref)
                self.assertEqual(0, result.returncode, result.stdout)
            finally:
                self.compose('up', '-d', '--wait', '--wait-timeout', '30', 'app', check=True)

    def test_same_release_keeps_container_ids(self):
        before = {s: self.compose('ps', '-q', s).stdout for s in SERVICES}
        start = time.monotonic()
        self.compose('up', '-d', '--no-build', '--no-deps', *SERVICES, check=True)
        no_op_ms = (time.monotonic() - start) * 1000
        self.assertEqual(before, {s: self.compose('ps', '-q', s).stdout for s in SERVICES})
        start = time.monotonic()
        self.compose('restart', 'web', check=True)
        forced_restart_ms = (time.monotonic() - start) * 1000
        evidence = os.environ.get('VECTOR_DEPLOY_EVIDENCE')
        if evidence:
            (Path(evidence) / 'transition-benchmark.json').write_text(json.dumps(dict(
                synthetic=True, no_op_compose_ms=no_op_ms, redundant_web_restart_ms=forced_restart_ms,
                scope='local Alpine fixture; excludes backup, transfer, pull and migrations'), indent=2) + '\n')

    def test_incremental_image_and_configuration_changes(self):
        before = {s: self.compose('ps', '-q', s).stdout for s in SERVICES}
        changes = {'research-worker': {'image': self.refs['app']}}
        override = self.root / 'selective.json'
        override.write_text(json.dumps({'services': changes}))
        run('docker', 'compose', '-p', self.project, '-f', str(self.root / 'compose.yaml'),
            '-f', str(override), 'up', '-d', '--no-build', '--no-deps', *SERVICES, check=True)
        try:
            after = {s: self.compose('ps', '-q', s).stdout for s in SERVICES}
            self.assertEqual(['research-worker'], [s for s in SERVICES if before[s] != after[s]])
        finally:
            self.compose('up', '-d', '--wait', '--wait-timeout', '30', *SERVICES, check=True)
        before = {s: self.compose('ps', '-q', s).stdout for s in SERVICES}
        changes = {s: {'image': self.refs['web']} for s in SERVICES if s not in ('web', 'research-worker')}
        override.write_text(json.dumps({'services': changes}))
        run('docker', 'compose', '-p', self.project, '-f', str(self.root / 'compose.yaml'),
            '-f', str(override), 'up', '-d', '--no-build', '--no-deps', *SERVICES, check=True)
        try:
            after = {s: self.compose('ps', '-q', s).stdout for s in SERVICES}
            self.assertEqual(sorted(changes), sorted(s for s in SERVICES if before[s] != after[s]))
        finally:
            self.compose('up', '-d', '--wait', '--wait-timeout', '30', *SERVICES, check=True)

    def test_retention_and_rollback_with_shared_layers(self):
        previous_sha = 'b' * 40
        previous_refs = {}
        for target, ref in self.refs.items():
            tag = ref.split('@')[0].replace(SHA, previous_sha)
            self.owned_tags.append(tag)
            (self.root / 'Dockerfile').write_text(f'FROM alpine:latest\nLABEL org.opencontainers.image.revision={previous_sha}\nLABEL vector.fixture={self.project}\nLABEL vector.target={target}\nCMD ["sleep", "3600"]\n')
            run('docker', 'build', '-t', tag, str(self.root), check=True)
            run('docker', 'push', tag, check=True)
            metadata = json.loads(run('docker', 'image', 'inspect', tag, check=True).stdout)[0]
            previous_refs[target] = tag + '@' + metadata['RepoDigests'][0].split('@')[1]
        previous = {s: {'image': previous_refs[s if s in ('web', 'research-worker') else 'app']} for s in SERVICES}
        (self.root / f'state/release-images/sha-{previous_sha}.json').write_text(json.dumps({'services': previous}))
        repos = {target: ref.split(':sha-')[0] for target, ref in self.refs.items()}
        alias = repos['app'] + ':retained-alias'
        garbage = repos['app'] + ':obsolete'
        self.owned_tags.extend([alias, garbage])
        run('docker', 'tag', self.refs['app'], alias, check=True)
        (self.root / 'Dockerfile').write_text(f'FROM alpine:latest\nLABEL vector.fixture={self.project}\nLABEL vector.target=obsolete\n')
        run('docker', 'build', '-t', garbage, str(self.root), check=True)
        env = {**self.env, 'APP_IMAGE': repos['app'], 'WEB_IMAGE': repos['web'],
               'RESEARCH_WORKER_IMAGE': repos['research-worker'], 'CURRENT_IMAGE_TAG': 'sha-' + SHA,
               'PREVIOUS_IMAGE_TAG': 'sha-' + previous_sha}
        result = run('bash', str(self.root / 'docker/scripts/cleanup-project-images.sh'), env=env)
        self.assertEqual(0, result.returncode, result.stdout)
        for ref in [*self.refs.values(), *previous_refs.values(), alias]:
            self.assertEqual(0, run('docker', 'image', 'inspect', ref).returncode, ref)
        self.assertNotEqual(0, run('docker', 'image', 'inspect', garbage).returncode)
        override = self.root / 'rollback.json'
        override.write_text(json.dumps({'services': previous}))
        run('docker', 'compose', '-p', self.project, '-f', str(self.root / 'compose.yaml'),
            '-f', str(override), 'up', '-d', '--no-build', '--no-deps', *SERVICES, check=True)
        try:
            for service in SERVICES:
                result = self.helper(service, previous[service]['image'])
                self.assertEqual(0, result.returncode, result.stdout)
        finally:
            self.compose('up', '-d', '--wait', '--wait-timeout', '30', *SERVICES, check=True)

    def test_worker_shutdown_monotonic_observer(self):
        override = json.loads((self.root / 'compose.yaml').read_text())
        original = (self.root / 'compose.yaml').read_text()
        override['services']['research-worker']['image'] = self.refs['app']
        (self.root / 'compose.yaml').write_text(json.dumps(override))
        try:
            result = run('bash', '-c', 'source "$1"; transition_application_services', '_',
                         str(self.root / 'docker/scripts/common.sh'), env={**self.env, 'IMAGE_TAG': 'observer'})
            self.assertEqual(0, result.returncode, result.stdout)
            events = [json.loads(line.removeprefix('VECTOR_TIMING ')) for line in result.stdout.splitlines() if line.startswith('VECTOR_TIMING ')]
            self.assertEqual(1, len(events), result.stdout)
            self.assertIn('research-worker', events[0]['services_ms'], result.stdout)
            self.assertGreater(events[0]['services_ms']['research-worker'], 0)
        finally:
            (self.root / 'compose.yaml').write_text(original)
            self.compose('up', '-d', '--wait', '--wait-timeout', '30', *SERVICES, check=True)

    def test_oci_index_and_platform_manifest_resolve_same_image_id(self):
        import hashlib
        import urllib.request
        ref = self.refs['app']
        repo, digest = ref.split('@')
        registry_repo = repo.rsplit(':sha-', 1)[0]
        host_path = registry_repo.split('/', 1)
        metadata = json.loads(run('docker', 'image', 'inspect', ref, check=True).stdout)[0]
        child_url = f'http://{host_path[0]}/v2/{host_path[1]}/manifests/{digest}'
        request = urllib.request.Request(child_url, headers={'Accept': 'application/vnd.docker.distribution.manifest.v2+json'})
        with urllib.request.urlopen(request) as response:
            child = response.read()
            media_type = response.headers['Content-Type']
        index = json.dumps({'schemaVersion': 2, 'mediaType': 'application/vnd.oci.image.index.v1+json',
                            'manifests': [{'mediaType': media_type, 'digest': digest, 'size': len(child),
                                           'platform': {'architecture': metadata['Architecture'], 'os': metadata['Os']}}]}).encode()
        index_digest = 'sha256:' + hashlib.sha256(index).hexdigest()
        index_url = f'http://{host_path[0]}/v2/{host_path[1]}/manifests/index'
        request = urllib.request.Request(index_url, data=index, method='PUT', headers={'Content-Type': 'application/vnd.oci.image.index.v1+json'})
        urllib.request.urlopen(request).close()
        expected = repo + '@' + index_digest
        run('docker', 'pull', expected, check=True)
        self.assertNotEqual(digest, index_digest)
        result = self.helper('app', expected)
        self.assertEqual(0, result.returncode, result.stdout)

    def test_unhealthy_and_missing_services_fail(self):
        self.compose('stop', 'app', check=True)
        try:
            self.assertNotEqual(0, self.validator().returncode)
        finally:
            self.compose('up', '-d', '--wait', '--wait-timeout', '30', 'app', check=True)
        override = self.root / 'unhealthy.json'
        override.write_text(json.dumps({'services': {'app': {'healthcheck': {'test': ['CMD', 'false'], 'interval': '1s', 'retries': 1}}}}))
        run('docker', 'compose', '-p', self.project, '-f', str(self.root / 'compose.yaml'),
            '-f', str(override), 'up', '-d', '--no-deps', 'app', check=True)
        try:
            cid = self.compose('ps', '-q', 'app').stdout.strip()
            for _ in range(100):
                status = run('docker', 'inspect', '--format', '{{.State.Health.Status}}', cid).stdout.strip()
                if status == 'unhealthy':
                    break
                time.sleep(.1)
            self.assertEqual('unhealthy', status)
            self.assertNotEqual(0, self.validator().returncode)
        finally:
            self.compose('up', '-d', '--wait', '--wait-timeout', '30', 'app', check=True)


if __name__ == '__main__':
    unittest.main()
