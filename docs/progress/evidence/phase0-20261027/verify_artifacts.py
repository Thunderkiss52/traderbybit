"""Read-only verification of this retained evidence packet; no Laravel bootstrap."""
import hashlib
import json
from pathlib import Path
import sys
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[4]
OUT = Path(__file__).resolve().parent
sys.path.insert(0, str(ROOT / 'scripts'))
import vector_progress as vp

registry = json.loads((ROOT / 'docs/progress/vector-requirements.json').read_text())
original = json.loads(vp.git(ROOT, 'show', 'c8d5115:docs/progress/vector-requirements.json'))
assert vp.scope(registry) == vp.scope(original)
assert len(registry['requirements']) == 97
assert sum(len(r['obligations']) for r in registry['requirements']) == 229
phase0 = [r for r in registry['requirements'] if r['phase'] == 0]
assert len(phase0) == 45
assert sum(len(r['obligations']) for r in phase0) == 99
assert {f'VEC-P0-{100+i}' for i in range(1, 28)} <= {r['id'] for r in phase0}
assert {f'VEC-P0-{200+i}' for i in range(1, 10)} <= {r['id'] for r in phase0}
commands = [json.loads(line) for line in (OUT / 'commands.jsonl').read_text().splitlines()]
for command in commands:
    assert hashlib.sha256((ROOT / command['artifact']).read_bytes()).hexdigest() == command['sha256'], command['label']
cases = {c.get('class') + '::' + c.get('name'): c for c in ET.parse(OUT / 'full.xml').iter('testcase')}
assert len(cases) == 903
assert sum(c.find('skipped') is not None for c in cases.values()) == 1
assert not any(c.find('failure') is not None or c.find('error') is not None for c in cases.values())
matrix = {row['requirement_id']: row for row in json.loads((OUT / 'matrix.json').read_text())}
assert set(matrix) == {r['id'] for r in phase0}
passed = failed = 0
for requirement in phase0:
    for obligation in requirement['obligations']:
        if not obligation['evidence']:
            continue
        e = obligation['evidence'][-1]
        artifact = ROOT / e['artifact']
        assert hashlib.sha256(artifact.read_bytes()).hexdigest() == e['artifact_sha256']
        assert e['dependency_hash'] == vp.fingerprint(ROOT, requirement['watch_paths'])
        assert e['environment'] == 'isolated_postgresql' and e['synthetic'] is True
        data = json.loads(artifact.read_text())
        assert data['requirement_id'] == requirement['id'] and data['obligation_id'] == obligation['id']
        assert data['result'] == e['result']
        if e['result'] == 'PASS':
            passed += 1
            assert matrix[requirement['id']]['local_status'] == 'PASS'
            assert data['tests'] and data['why_this_proves_condition']
            for case in data['tests']:
                actual = cases[case['test']]
                assert len(actual) == 0 and int(actual.get('assertions')) == case['assertions']
            for log in data['logs']:
                assert hashlib.sha256((ROOT / log['path']).read_bytes()).hexdigest() == log['sha256']
            for path, expected in data['test_source_hashes'].items():
                assert hashlib.sha256((ROOT / path).read_bytes()).hexdigest() == expected
        elif e['result'] == 'FAIL':
            failed += 1
            assert requirement['id'] == 'VEC-P0-009'
            assert data['actual_p95_ms'] >= data['threshold_ms_exclusive']
assert (passed, failed) == (18, 1)
assert not any(r['product_evidence'] for r in registry['requirements'])
print('PASS: 97 requirements, 229 obligations, unchanged scope; all 45 Phase 0 mappings; 18 applicable passes and 1 measured failure; logs, case IDs, source hashes and evidence digests match. No product acceptance.')
