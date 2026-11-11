import csv,hashlib,json,pathlib,re,subprocess
root=pathlib.Path('/home/thunder/work/traderbybit')
audit=root/'docs/autonomous-profit-optimizer'
rows=list(csv.DictReader((audit/'REQUIREMENT_MATRIX.csv').open()))
expected=set(re.findall(r'^#{1,3} (\d+(?:\.\d+)?)(?:\.)?\s',(audit/'SPECIFICATION_EN.md').read_text(),re.M))
assert len(rows)==len(expected)==78
assert expected=={r['specification_id'] for r in rows}
for r in rows:
 assert r['status'] in {'IMPLEMENTED_AND_VERIFIED','IMPLEMENTED_BUT_INCOMPLETE','IMPLEMENTED_BUT_DEFECTIVE','MISSING','BLOCKED_BY_DEPENDENCY'}
 for k in ['source_references','test_references']:
  for name in r[k].split(';'):assert (root/name).exists(),name
prior=list(csv.DictReader((audit/'PRIOR_FINDINGS_RECHECK.csv').open()))
assert {r['finding_id'] for r in prior}=={f'F{i:02d}' for i in range(1,25)}
manifest=json.loads((audit/'evidence/stage0-20261111/source-sha256.json').read_text())
for name,digest in manifest.items():assert hashlib.sha256((root/name).read_bytes()).hexdigest()==digest,name
staged=subprocess.check_output(['git','diff','--cached','--binary','--','.gitignore'],cwd=root)
assert staged==(audit/'evidence/stage0-20261111/initial-staged.patch').read_bytes()
print('PASS: all 78 specification headings; all 24 prior findings; existing references; 1304 inherited runtime file hashes; original staged ignore patch preserved')
