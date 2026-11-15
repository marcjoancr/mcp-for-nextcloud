import sys,json,copy
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'host'))
import GroupInstallAdmin as M
class Interval:
 def _foreign(self,value):pass
 def assert_isolated(self,*args,**kwargs):pass
checks=0
for failure in (None,'malformed','wrong_phase','nonzero_metadata','running_app'):
 a=M.Admin.__new__(M.Admin);a.package=Path(__file__).resolve().parents[1];a.bundle=a.package;a._assert_epoch_intent=lambda:{'path':'synthetic','sha256':'e'*64};a._verify_package=lambda:None;a._mirrors_exact=lambda:None;a.code_mount=a.package/'standalone';a.bundle_mount=a.bundle;a.helper_id=None;a.operation='a'*32;a.seq=0;a.net='b'*64;a.interval=Interval();a._profile=lambda:{};a.snapshot=lambda _:{'private_consumers':{key:{}for key in [M.APP,M.DB]+([a.helper_id]if a.helper_id else[])},'private_internal':True};a.journal=lambda *args:{'path':'synthetic','sha256':'e'*64}
 m={'running':False,'image':'sha256:synthetic','ports':{},'networks':{'private':{'NetworkID':a.net}},'exit_code':0,'tmpfs':{'/profiles':'rw,noexec,nosuid,size=2m,mode=0700,uid=33,gid=33'},'mounts':[{'Destination':M.ROOT,'Name':'synthetic-volume','RW':True},{'Destination':'/installer','Source':str(a.package/'standalone'),'RW':False},{'Destination':'/bundle','Source':str(a.bundle),'RW':False},{'Destination':'/profiles','Type':'tmpfs'}]}
 a.plan={'bundle_manifest':'a'*64,'containers':{M.APP:{'image':m['image'],'mounts':[{'Type':'volume','Destination':M.ROOT,'RW':True,'Name':'synthetic-volume'}]}}}
 a.before={'forms':{},'mcp':{},'circles':{},'config':{}};a.expected_config={};a.archives=lambda label:copy.deepcopy(a.before);a.action='install';calls=[]
 def metadata(ident):
  result=copy.deepcopy(m)
  if failure=='running_app'and ident==M.APP:result['running']=True
  if failure=='nonzero_metadata'and ident!=M.APP:result['exit_code']=1
  return result
 def child(command,**kw):
  calls.append((command,kw))
  if command[1]=='create':
   assert command[command.index('--entrypoint')+1]=='/usr/local/bin/php'and '--publish'not in command and '--env'not in command
   assert command[-1]=='/installer/GroupInstallController.php';return b'c'*64+b'\n'
  if command[1]=='start':
   req=json.loads(kw['input_data']);assert req=={'sequence':1,'phase':'inspect','profile':{}}
   if failure=='malformed':return b'not-json'
   return json.dumps({'ok':True,'sequence':1,'stage':'other'if failure=='wrong_phase'else'inspect'}).encode()
  if command[1]=='rm':return b'c'*64+b'\n'
  raise AssertionError('unexpected operation')
 M.container_metadata=metadata;M.child=child;M.postgres_writers=lambda _: {'superuser':True,'foreign_client_sessions':0,'prepared_transactions':0}
 refused=False
 try:a._phase('inspect')
 except M.Refused:refused=True
 assert refused==(failure is not None);checks+=1
 if failure is None:assert a.helper_id is None and calls[-1][0][1]=='rm'
 elif failure=='running_app':assert calls==[]
 else:assert a.helper_id=='c'*64 and not any(cmd[1]=='rm'for cmd,_ in calls)
 checks+=1
print({'checks':checks,'docker_calls':0,'native_bootstraps':0,'fake_sinks_only':True})
