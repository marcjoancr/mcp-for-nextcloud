# SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
from pathlib import Path
import subprocess,json,sys,os
if os.environ.get('MCP_FORMS_LOCAL_IO_BOUNDARY')!='1':raise SystemExit(2)
def require(value,message):
 if not value:raise RuntimeError(message)
E=Path(__file__).parent;cell='forms-guard-nc-maria-20261113';position=int(sys.argv[1]);require(position in [1,2],'Choose exact fault position1or2.')
def run(args,input=None):
 p=subprocess.run(args,input=input,text=True,capture_output=True,timeout=80)
 if p.returncode:raise RuntimeError('Disposable fixture command failed: '+args[0])
 return p.stdout
meta=json.loads(run(['docker','inspect',cell]))[0]
require(meta['Config']['Labels'].get('forms.guard.test')=='20261113','Exact disposable cell label required.')
for name in ['response-local-io-http.php','response-local-io-permissions.php','response-local-io-control.php']:
 run(['docker','cp',str(E/name),cell+':/tmp/'+name])
run(['docker','exec',cell,'chmod','a+r','/tmp/response-local-io-http.php','/tmp/response-local-io-permissions.php','/tmp/response-local-io-control.php'])
def helper(script,actor,request):
 r=json.loads(run(['docker','exec','-i','-u',actor,'-e','MCP_FORMS_LOCAL_IO_BOUNDARY=1',cell,'php','/tmp/'+script],json.dumps(request)))
 if not r.get('ok'):raise RuntimeError('Exact fixture helper refused: '+script)
 return r
p=subprocess.Popen(['docker','exec','-i','-u','www-data','-e','MCP_FORMS_LOCAL_IO_BOUNDARY=1','-e','MCP_FORMS_HTTP_BOUNDARY=1','-e','MCP_FORMS_FILE_FAULT_POSITION='+str(position),cell,'php','/tmp/response-local-io-http.php'],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
preimage=None;request=None;result=None;injected=False;restored=False
try:
 for line in p.stdout:
  item=json.loads(line)
  if item.get('control')=='inject':
   require(request is None,'Duplicate injection request.');request=item
   preimage=helper('response-local-io-permissions.php','root',dict(request,mode='capture'))['preimage']
   negative=subprocess.run(['docker','exec','-i','-u','root','-e','MCP_FORMS_LOCAL_IO_BOUNDARY=1',cell,'php','/tmp/response-local-io-permissions.php'],input=json.dumps(dict(request,mode='apply',preimage=preimage,fail_after_parent=True)),text=True,capture_output=True,timeout=30)
   negative_result=json.loads(negative.stdout)
   require(negative.returncode==1 and negative_result.get('ok') is False and negative_result.get('injected_failure') is True,'Exact partial injection negative control missing.')
   require(helper('response-local-io-permissions.php','root',dict(request,mode='capture'))['preimage']==preimage,'Partial injection failed to restore exact preimage.')
   helper('response-local-io-permissions.php','root',dict(request,mode='apply',preimage=preimage))
   proof=helper('response-local-io-control.php','www-data',request)
   require(all(proof.get(k) is True for k in ['native_and_posix_writable','sticky_dir_root_owned','target_root_owned']),'Native writable positive prerequisite missing.')
   injected=True;p.stdin.write('injected\n');p.stdin.flush()
  elif item.get('control')=='restore':
   require(preimage is not None,'OS restore preimage unavailable.')
   helper('response-local-io-permissions.php','root',dict(request,mode='restore',preimage=preimage));preimage=None;restored=True
   p.stdin.write('restored\n');p.stdin.flush()
  else:result=item
 p.stdin.close();code=p.wait(timeout=30);errors=p.stderr.read()
 require(code==0 and result and result.get('ok') and injected and restored,'Native I/O fixture failed: '+json.dumps({'code':code,'result':result,'stderr_present':bool(errors)}))
 result['checks']['native_write_permission_positive_before_kernel_failure']=True
 result['checks']['exact_os_preimages_restored']=restored
 result['checks']['partial_injection_failure_restores_preimage']=True
 print(json.dumps(result))
finally:
 if preimage is not None:
  helper('response-local-io-permissions.php','root',dict(request,mode='restore',preimage=preimage));preimage=None
 if p.poll() is None:
  if not p.stdin.closed:p.stdin.close()
  try:p.wait(timeout=5)
  except subprocess.TimeoutExpired:p.kill();p.wait()
