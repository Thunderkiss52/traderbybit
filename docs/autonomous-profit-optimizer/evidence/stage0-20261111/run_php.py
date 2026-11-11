import os,pathlib,sys
base=pathlib.Path(__file__).parent
name=sys.argv[1]; database=sys.argv[2]; args=sys.argv[3:]
cmd=['docker','run','--rm','--label','vector.task=apo-stage0-20261111','--network','vector-apo-stage0-checks-20261111','--memory','2g','--cpus','2','--entrypoint','php','-v',str(base/'source')+':/app','-v','/home/thunder/work/traderbybit/vendor:/app/vendor:ro','-v',str(base)+':/evidence','-w','/app']
env={'APP_ENV':'testing','APP_CONFIG_CACHE':'/tmp/vector-config.php','DB_CONNECTION':'pgsql','DB_HOST':'postgres','DB_PORT':'5432','DB_DATABASE':database,'DB_USERNAME':'postgres','DB_PASSWORD':'testpass','DB_URL':'','TEST_DB_HOST':'postgres','TEST_DB_PORT':'5432','TEST_DB_DATABASE':database,'TEST_DB_USERNAME':'postgres','TEST_DB_PASSWORD':'testpass','REDIS_HOST':'redis','REDIS_PORT':'6379','REDIS_URL':'','REDIS_CLIENT':'predis','REDIS_PREFIX':'vector-apo-stage0-'+name+'-','XDEBUG_MODE':'off','CACHE_STORE':'array'}
for key,value in env.items():cmd+=['-e',key+'='+value]
cmd+=['traderbybit-php85-test:local']+args
os.execv(sys.executable,[sys.executable,str(base/'run_check.py'),name]+cmd)
