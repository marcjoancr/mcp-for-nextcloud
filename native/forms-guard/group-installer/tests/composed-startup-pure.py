"""Run Admin's exact composition locally; only literal filesystem roots are fixture-relocated."""
import hashlib,json,os,shutil,subprocess,sys,tempfile
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'host'))
import GroupInstallAdmin as M
from GroupInstallStartup import Refused as StartupRefused
P=Path(__file__).resolve().parents[1]
PATHS=['/entrypoint.sh','/usr/local/bin/apache2-foreground','/etc/apache2/envvars','/usr/src/nextcloud/version.php','/var/www/html/version.php']
with tempfile.TemporaryDirectory()as directory:
 root=Path(directory);config=root/'var/www/html/config/config.php';config.parent.mkdir(parents=True)
 config.write_text("<?php\n$CONFIG = array ('dbhost'=>'forms-guard-db-20261113','dbtype'=>'pgsql','maintenance'=>false,'version'=>'35.0.2.2');\n")
 pins={}
 for path in PATHS:
  f=root/path.lstrip('/');f.parent.mkdir(parents=True,exist_ok=True);f.write_bytes(b'<?php $OC_Version=array(35,0,2,2);\n'if path.endswith('version.php')else b'synthetic-stock-source\n');pins[path]=M.digest(f.read_bytes())
 a=M.Admin.__new__(M.Admin);a.package=P;a.plan={'startup_pins':pins,'literal_version_sha256':pins['/var/www/html/version.php']}
 expected=(P/'standalone/GroupInstallLiteralConfig.php').read_text()+'\n'+(P/'standalone/GroupInstallStartupProbe.php').read_text().removeprefix('<?php')
 outputs=[]
 def child(command,**kw):
  if command[1]=='inspect':return b'true\n'
  if command[1]=='diff':return b''
  assert command==['docker','exec','-i',M.APP,'/usr/local/bin/php']
  code=kw['input_data'].decode();assert code==expected
  # No class/namespace/call transformation: only fixture path literals, with exact occurrence guards.
  for path in PATHS+['/docker-entrypoint-hooks.d','/var/www/html/config/config.php']:
   literal="'"+path+"'";assert code.count(literal)==(2 if path in('/docker-entrypoint-hooks.d','/etc/apache2/envvars')else 1)
   replacement="'"+str(root/path.lstrip('/'))+"'"
   if path=='/etc/apache2/envvars':code=replacement.join(code.rsplit(literal,1))
   else:code=code.replace(literal,replacement)
  env={'PATH':'/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin','APACHE_CONFDIR':'/etc/apache2','APACHE_ENVVARS':'/etc/apache2/envvars'}
  r=subprocess.run([shutil.which('php')],input=code.encode(),capture_output=True,env=env);outputs.append({'exit':r.returncode,'stdout':r.stdout.decode(),'stderr':r.stderr.decode()})
  assert r.returncode==0,('actual composed probe failed',r.returncode,r.stdout.decode(),r.stderr.decode())
  value=json.loads(r.stdout);value['sources']={'/'+str(Path(path).relative_to(root)):sha for path,sha in value['sources'].items()}
  return json.dumps(value).encode()
 M.child=child;M.container_metadata=lambda _:{'stop_signal':'SIGWINCH','restart_policy':'no'}
 a._startup();assert a.maintenance_prior is False
 hooks=root/'docker-entrypoint-hooks.d'
 for name in('before-starting','before-installation','after-installation','before-upgrade','after-upgrade'):(hooks/name).mkdir(parents=True)
 a._startup();assert a.maintenance_prior is False
 (hooks/'before-starting/unknown.sh').write_text('synthetic-hook')
 refused=False
 try:a._startup()
 except StartupRefused as error:refused=str(error)=='Startup source profile changed'
 assert refused
 print(json.dumps({'checks':3,'actual_admin_composition':True,'stock_empty_hook_directories':5,'unknown_hook_refused':True,'nextcloud_bootstraps':0,'docker_calls':0,'composition_sha256':hashlib.sha256(expected.encode()).hexdigest()}))
