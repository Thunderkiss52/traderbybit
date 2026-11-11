import datetime,json,pathlib,subprocess,sys,time
base=pathlib.Path(__file__).parent
name=sys.argv[1]; command=sys.argv[2:]
start=time.monotonic(); timestamp=datetime.datetime.now(datetime.timezone.utc).isoformat()
with (base/(name+'.log')).open('w') as out:
    result=subprocess.run(command,stdout=out,stderr=subprocess.STDOUT)
record={'name':name,'command':command,'started_utc':timestamp,'duration_seconds':round(time.monotonic()-start,3),'exit_code':result.returncode}
(base/(name+'.json')).write_text(json.dumps(record,indent=2)+'\n')
print(json.dumps(record))
print((base/(name+'.log')).read_text()[-6000:])
sys.exit(result.returncode)
