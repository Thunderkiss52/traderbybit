"""Admit explicitly reviewed stage cases using the existing VECTOR scorer."""
import copy, datetime, hashlib, json, pathlib, shutil, subprocess, sys
import xml.etree.ElementTree as ET
ROOT = pathlib.Path(__file__).resolve().parents[4]
OUT = pathlib.Path(__file__).resolve().parent
sys.path.insert(0, str(ROOT / 'scripts'))
import vector_progress as vp
stage = sys.argv[1]
full_label = sys.argv[2] if len(sys.argv) > 2 else stage + '-full'
plan = json.loads((OUT / (stage + '-admission.json')).read_text())
registry_path = ROOT / 'docs/progress/vector-requirements.json'
registry = json.loads(registry_path.read_text())
sha = vp.git(ROOT, 'rev-parse', 'HEAD')
xml_path = OUT / (full_label + '.xml')
assert not xml_path.exists(), 'Do not overwrite retained execution evidence'
shutil.copy2(pathlib.Path('/tmp/vector-phase0-local-20261028') / xml_path.name, xml_path)
cases = {c.get('class') + '::' + c.get('name'): c for c in ET.parse(xml_path).iter('testcase')}
assert not any(c.find('failure') is not None or c.find('error') is not None for c in cases.values())
commands = [json.loads(x) for x in (OUT / 'commands.jsonl').read_text().splitlines()]
command = next(x for x in commands if x['label'] == full_label)
assert command['exit_code'] == 0
hashfile = lambda p: hashlib.sha256(p.read_bytes()).hexdigest()
date = command['finished_at'][:10]
for r in registry['requirements']:
    dep = vp.fingerprint(ROOT, r['watch_paths'])
    # Reaffirm existing narrow source atoms only when source paths are unchanged.
    for c in r['criteria']:
        if c['status'] != 'IMPLEMENTED': continue
        e = c['evidence']
        for ref in e['references']:
            old = subprocess.check_output(['git', '-C', str(ROOT), 'show', sha + ':' + ref['path']])
            assert old == (ROOT / ref['path']).read_bytes(), 'Changed source needs separate review: ' + ref['path']
        e.update(git_sha=sha, date=date, dependency_hash=dep)
        e['limitations'] = 'Prior limited source atoms unchanged at stage review; watched tests updated. No additional implementation or product credit.'
    if r['id'] not in plan: continue
    o = next(o for o in r['obligations'] if o['id'].endswith('-V1'))
    item = plan[r['id']]
    selected = []
    for prefix in item['test_prefixes']:
        matches = [(key, c) for key,c in cases.items() if key.startswith(prefix)]
        assert matches, prefix
        for key,c in matches:
            assert len(c) == 0, key
            source = 'tests/' + key.split('::')[0].removeprefix('Tests\\').replace('\\', '/') + '.php'
            selected.append({'test':key, 'assertions':int(c.get('assertions')), 'source':source})
    selected = list({x['test']:x for x in selected}.values())
    artifact = OUT / (stage + '-' + o['id'] + '.json')
    assert not artifact.exists()
    payload = {'requirement_id':r['id'], 'obligation_id':o['id'], 'result':'PASS',
        'command':command, 'git_sha':sha, 'dependency_hash':dep, 'synthetic':True,
        'environment':'isolated_postgresql', 'why_this_proves_condition':item['rationale'],
        'tests':selected, 'test_source_hashes':{x['source']:hashfile(ROOT / x['source']) for x in selected},
        'junit':str(xml_path.relative_to(ROOT)), 'junit_sha256':hashfile(xml_path),
        'limitations':'Synthetic local fixtures; production, browser and product acceptance remain pending.'}
    artifact.write_text(json.dumps(payload,indent=2)+'\n')
    o['evidence'].append({'requirement_id':r['id'],'obligation_id':o['id'],'type':o['type'],
        'result':'PASS','environment':o['environment'],'fixture':'Exact cases in retained JUnit on task-owned PostgreSQL 17; no production data.',
        'synthetic':True,'git_sha':sha,'date':date,'execution_timestamp':command['finished_at'],
        'dependency_hash':dep,'reviewer':'Codex stage-specific QA review','artifact':str(artifact.relative_to(ROOT)),
        'artifact_sha256':hashfile(artifact),'limitations':payload['limitations'],'rationale':item['rationale']})
    r['test_paths'] = sorted(set(r['test_paths'] + [x['source'] for x in selected]))
    r['last_verified_commit']=sha
    r['last_verified_environment']='isolated_postgresql'
    r['last_evidence_update']=date
    r['verification_assessment']='PARTIAL_LOCAL'
    r['blocking_issues']=['Local V1 passed; production V2, applicable browser V3 and explicit product acceptance remain pending.']
    r['notes'].append(stage + ': ' + item['rationale'])
registry_path.write_text(json.dumps(registry,indent=2,ensure_ascii=False)+'\n')
print('Admitted',len(plan),'reviewed obligations;',len(cases),'executed cases,',sum(c.find('skipped') is not None for c in cases.values()),'skips retained')
