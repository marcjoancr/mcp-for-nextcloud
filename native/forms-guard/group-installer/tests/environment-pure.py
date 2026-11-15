import sys,json,subprocess
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'host'))
from GroupInstallEnv import template,FORBIDDEN,DEFAULTS
base=['PATH='+DEFAULTS['PATH'],'APACHE_CONFDIR=/etc/apache2','APACHE_ENVVARS=/etc/apache2/envvars']
cases=[base]+[base+[name+'=SYNTHETIC_PRIVATE_VALUE']for name in FORBIDDEN]+[base+['NC_dbpassword=SYNTHETIC_PRIVATE_VALUE'],base[1:],base[:1]+base[2:],base[:2],base+['PATH=/unqualified']]
r=subprocess.run(['go','run',str(Path(__file__).with_name('environment-template.go'))],input=json.dumps({'Template':template(),'Cases':cases}).encode(),capture_output=True,timeout=60)
assert r.returncode==0,(r.returncode,r.stderr.decode())
assert b'SYNTHETIC_PRIVATE_VALUE'not in r.stdout+r.stderr
assert json.loads(r.stdout)==[True]+[False]*(len(cases)-1)
print({'checks':len(cases),'native_Go_template':True,'environment_values_emitted':0,'docker_calls':0})
