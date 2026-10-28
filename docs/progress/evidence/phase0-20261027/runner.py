import datetime, hashlib, json, os, pathlib, shutil, subprocess, sys, time
ROOT = pathlib.Path('/home/thunder/work/traderbybit')
OUT = ROOT / 'docs/progress/evidence/phase0-20261027'
WORK = pathlib.Path('/tmp/vector-phase0-20261027')
RTK = '/home/thunder/.headroom/bin/rtk'
PG = 'vector-phase0-20261027-pg'
REDIS = 'vector-phase0-20261027-redis'
IMAGE = 'traderbybit-php85-test:local'
ENV = {'APP_ENV':'testing','APP_DEBUG':'false','APP_KEY':'base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=',
 'APP_URL':'http://127.0.0.1:8000','DB_CONNECTION':'pgsql','DB_HOST':'127.0.0.1','DB_PORT':'5432','DB_DATABASE':'bybit_test',
 'DB_USERNAME':'postgres','DB_PASSWORD':'testpass','DB_URL':'','TEST_DB_HOST':'127.0.0.1','TEST_DB_PORT':'5432',
 'TEST_DB_DATABASE':'bybit_test','TEST_DB_USERNAME':'postgres','TEST_DB_PASSWORD':'testpass','CACHE_STORE':'array',
 'SESSION_DRIVER':'array','QUEUE_CONNECTION':'sync','REDIS_HOST':'127.0.0.1','REDIS_PORT':'6379','REDIS_PASSWORD':'null',
 'LOG_CHANNEL':'stderr','GOCACHE':'/tmp/go-cache','GOPATH':'/tmp/go','RUN_BYBIT_INTEGRATION':'0','TZ':'UTC'}
def run(label, command, cwd=ROOT):
 OUT.mkdir(parents=True, exist_ok=True)
 start = datetime.datetime.now(datetime.timezone.utc)
 with (OUT/(label+'.log')).open('w') as f:
  p=subprocess.run([RTK,'proxy',*command],cwd=cwd,stdout=f,stderr=subprocess.STDOUT)
 record={'label':label,'command':[RTK,'proxy',*command],'cwd':str(cwd),'started_at':start.isoformat(),
 'finished_at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'exit_code':p.returncode,
 'git_sha':subprocess.check_output([RTK,'proxy','git','rev-parse','HEAD'],cwd=ROOT,text=True).strip(),
 'artifact':str((OUT/(label+'.log')).relative_to(ROOT)), 'sha256':hashlib.sha256((OUT/(label+'.log')).read_bytes()).hexdigest()}
 with (OUT/'commands.jsonl').open('a') as f: f.write(json.dumps(record)+'\n')
 print(json.dumps(record),flush=True)
 print((OUT/(label+'.log')).read_text()[-1800:],flush=True)
 return p.returncode
def container(command):
 cmd=['docker','run','--rm','--label','io.traderbybit.task=phase0-evidence-20261027','--network','container:'+PG,
 '--user',str(os.getuid())+':'+str(os.getgid()),'--entrypoint','','-v',str(WORK)+':/var/www/html','-w','/var/www/html']
 for k,v in ENV.items(): cmd+=['-e',k+'='+v]
 return cmd+[IMAGE]+command
if sys.argv[1]=='init':
 assert not WORK.exists(), 'Do not overwrite an existing workspace'
 WORK.mkdir()
 paths=subprocess.check_output([RTK,'proxy','git','ls-files','-z'],cwd=ROOT).decode().split('\0')
 for s in paths:
  if not s or s.startswith(('reports/','.env','research-go/bin/')): continue
  p=ROOT/s
  if p.is_file():
   q=WORK/s; q.parent.mkdir(parents=True,exist_ok=True); shutil.copy2(p,q)
 shutil.copytree(ROOT/'vendor',WORK/'vendor')
 for s in ['storage/framework/cache/data','storage/framework/sessions','storage/framework/views','storage/logs','bootstrap/cache','bin','research-go/bin']:
  (WORK/s).mkdir(parents=True,exist_ok=True)
 shutil.copy2(ROOT/'.env.example',WORK/'.env')
 for label,cmd in [
  ('postgres-start',['docker','run','-d','--name',PG,'--label','io.traderbybit.task=phase0-evidence-20261027','--network','none','-e','POSTGRES_DB=bybit_test','-e','POSTGRES_PASSWORD=testpass','postgres:17-alpine']),
  ('redis-start',['docker','run','-d','--name',REDIS,'--label','io.traderbybit.task=phase0-evidence-20261027','--network','container:'+PG,'redis:7.4-alpine'])]:
  if run(label,cmd): sys.exit(1)
elif sys.argv[1]=='php': sys.exit(run(sys.argv[2],container(sys.argv[3:])))
elif sys.argv[1]=='host': sys.exit(run(sys.argv[2],sys.argv[3:]))
