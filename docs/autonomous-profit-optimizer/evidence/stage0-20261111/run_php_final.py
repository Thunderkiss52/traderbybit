import os,pathlib,sys
base=pathlib.Path(__file__).parent
name=sys.argv[1]; database=sys.argv[2]; args=sys.argv[3:]
cmd=['docker','run','--rm','--label','vector.task=apo-stage0-20261111','--network','host','--memory','2g','--cpus','2','--entrypoint','php','-v',str(base/'source-final')+':/app','-v','/home/thunder/work/traderbybit/vendor:/app/vendor:ro','-v',str(base)+':/evidence','-v','/usr/bin/docker:/usr/local/bin/docker:ro','-v','/usr/libexec/docker/cli-plugins/docker-compose:/usr/local/lib/docker/cli-plugins/docker-compose:ro','-w','/app']
env={'APP_ENV':'testing','APP_CONFIG_CACHE':'/tmp/vector-config.php','DB_CONNECTION':'pgsql','DB_HOST':'127.0.0.1','DB_PORT':'44620','DB_DATABASE':database,'DB_USERNAME':'postgres','DB_PASSWORD':'testpass','DB_URL':'','TEST_DB_HOST':'127.0.0.1','TEST_DB_PORT':'44620','TEST_DB_DATABASE':database,'TEST_DB_USERNAME':'postgres','TEST_DB_PASSWORD':'testpass','REDIS_HOST':'127.0.0.1','REDIS_PORT':'44621','REDIS_URL':'','REDIS_CLIENT':'predis','REDIS_PREFIX':'vector-apo-stage0-'+name+'-','XDEBUG_MODE':'off','CACHE_STORE':'array'}
for key,value in env.items():cmd+=['-e',key+'='+value]
cmd+=['traderbybit-php85-test:local']+args
os.execv(sys.executable,[sys.executable,str(base/'run_check.py'),name]+cmd)
