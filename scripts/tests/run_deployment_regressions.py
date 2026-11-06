import json, os, pathlib, subprocess, tempfile, time, uuid
root=pathlib.Path(__file__).resolve().parents[2]
(root/"reports/agent/deployment-remediation").mkdir(parents=True, exist_ok=True)
work=pathlib.Path(tempfile.mkdtemp(prefix='vector-deploy-regression-'))
project='vector-deploy-regression-'+uuid.uuid4().hex[:8]
compose=work/'compose.json'
compose.write_text(json.dumps({'services':{'postgres':{'image':'postgres:17-alpine','environment':{'POSTGRES_PASSWORD':'testpass','POSTGRES_DB':'bybit_test'},'ports':['127.0.0.1::5432'],'tmpfs':['/var/lib/postgresql/data']},'redis':{'image':'redis:7.4-alpine','ports':['127.0.0.1::6379'],'tmpfs':['/data']}}}))
base=['docker','compose','-p',project,'-f',str(compose)]
results=[]
def call(args,**kw):return subprocess.run(args,text=True,stdout=subprocess.PIPE,stderr=subprocess.STDOUT,**kw)
try:
 result=call(base+['up','-d']); assert result.returncode==0,result.stdout
 pgcid=call(base+['ps','-q','postgres']).stdout.strip(); rdcid=call(base+['ps','-q','redis']).stdout.strip()
 def port(cid,key):return json.loads(call(['docker','inspect',cid]).stdout)[0]['NetworkSettings']['Ports'][key][0]['HostPort']
 pgport=port(pgcid,'5432/tcp'); rdport=port(rdcid,'6379/tcp')
 for _ in range(60):
  if call(['docker','exec',pgcid,'pg_isready','-h','127.0.0.1','-U','postgres']).returncode==0:break
  time.sleep(.2)
 sql="""CREATE TABLE migrations (id serial primary key, migration text, batch integer);
INSERT INTO migrations(migration,batch) VALUES ('2026_10_20_000000_add_slot_idempotency_to_strategy_observations',1);
CREATE TABLE strategy_observations (exchange text, category text,symbol text,strategy_id text,strategy_version text,decision_time timestamp,superseded_by bigint);
CREATE UNIQUE INDEX strategy_observations_slot_unique ON strategy_observations(exchange,category,symbol,strategy_id,strategy_version,decision_time) WHERE superseded_by IS NULL;
CREATE TABLE paper_accounts(id bigint PRIMARY KEY, exchange text, status text, balance numeric(24,12)); INSERT INTO paper_accounts SELECT id, CASE WHEN id <= 13 THEN 'bybit' ELSE 'binance' END, CASE WHEN id IN (1,6,15) THEN 'BLOCKED' ELSE 'ACTIVE' END, 10000 FROM generate_series(1,26) id; CREATE TABLE virtual_positions(id bigint PRIMARY KEY); CREATE TABLE virtual_executions(id bigint PRIMARY KEY); CREATE TABLE reconciliation_runs(id bigint PRIMARY KEY);
"""
 result=call(['docker','exec','-i',pgcid,'psql','-U','postgres','-d','bybit_test','-v','ON_ERROR_STOP=1'],input=sql);assert result.returncode==0,result.stdout
 # Exercise real backup creation/restoration-list verification and exact schema guard.
 import shutil
 shutil.copytree(root/'docker',work/'docker')
 shutil.copy(compose,work/'compose.yaml')
 env_file=work/'.env'
 env_file.write_text(f'ALLOW_TEST_PROJECT=1\nCOMPOSE_PROJECT_NAME={project}\nUI_BASIC_AUTH_USER=fixture\nUI_BASIC_AUTH_PASSWORD=fixture\nPOSTGRES_USER=postgres\nPOSTGRES_DB=bybit_test\nPOSTGRES_PASSWORD=testpass\nREDIS_PASSWORD=fixture\n')
 real_env={**os.environ,'ENV_FILE':str(env_file),'DEPLOY_STATE_DIR':str(work/'state'),'BACKUP_DIR':str(work/'backups'),'ALLOW_TEST_PROJECT':'1'}
 result=call(['bash',str(work/'docker/postgres/backup.sh')],env=real_env)
 (root/'reports/agent/deployment-remediation/real-backup.log').write_text(result.stdout)
 results.append({'command':'real PostgreSQL backup.sh + pg_restore --list','exit_code':result.returncode})
 schema_probe='source "$1"; atomic_write "$DEPLOY_STATE_DIR/release-schemas/test.migrations" "$(migration_state)"; atomic_write "$DEPLOY_STATE_DIR/release-schemas/test.schema.sha256" "$(schema_state)"; assert_rollback_migration_state test'
 result=call(['bash','-c',schema_probe,'_',str(work/'docker/scripts/common.sh')],env=real_env)
 results.append({'command':'exact PostgreSQL schema/migration snapshot permits unchanged schema','exit_code':result.returncode})
 result=call(['docker','exec',pgcid,'psql','-U','postgres','-d','bybit_test','-v','ON_ERROR_STOP=1','-c','ALTER TABLE paper_accounts ADD COLUMN synthetic_schema_change integer;'])
 assert result.returncode==0,result.stdout
 result=call(['bash','-c','source "$1"; assert_rollback_migration_state test','_',str(work/'docker/scripts/common.sh')],env=real_env)
 (root/'reports/agent/deployment-remediation/real-schema-negative.log').write_text(result.stdout)
 results.append({'command':'real DDL with unchanged migration ledger blocks rollback','exit_code':0 if result.returncode!=0 and 'schema differs' in result.stdout else 1,'observed_exit_code':result.returncode})
 env={**os.environ,'TEST_DB_PORT':pgport,'REDIS_PORT':rdport,'REDIS_HOST':'127.0.0.1'}
 env.pop('REDIS_PASSWORD', None)
 for script in ['scripts/tests/test_docker_compose_image_resolution.sh','scripts/tests/test_deploy_immutable_pull_scenarios.sh','scripts/tests/test_deploy_workflow_ci_verification.sh','scripts/test_deployment_recovery_matrix.sh','scripts/test_same_sha_configuration_refresh.sh']:
  start=time.monotonic();result=call(['bash',script],cwd=root,env=env)
  evidence=root/'reports/agent/deployment-remediation'/ (pathlib.Path(script).stem+'.log'); evidence.write_text(result.stdout)
  record={'command':'bash '+script,'exit_code':result.returncode,'elapsed_seconds':round(time.monotonic()-start,3)};results.append(record);print(json.dumps(record),flush=True)
  if result.returncode: print(result.stdout[-5000:],flush=True)
finally:
 call(base+['down','--volumes','--timeout','3'])
 import shutil
 shutil.rmtree(work)
 (root/'reports/agent/deployment-remediation/shell-regressions.json').write_text(json.dumps({'project':project,'synthetic':True,'database':'minimal isolated schema; no production/account reconciliation claim','results':results},indent=2)+'\n')

if any(record["exit_code"] for record in results):
 raise SystemExit(1)
