"""Real checkpoint/journal/phase paths with synthetic DB/container sinks and real private fsync files."""
import copy,hashlib,json,os,stat,sys,tempfile
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'host'))
import GroupInstallAdmin as M
import GroupInstallHostIO as IO
class Crash(BaseException):pass
class Interval:
 def _foreign(self,*args,**kwargs):pass
 def assert_isolated(self,*args,**kwargs):pass
results={};synced=[];active=[None];fault=[None]
original_fsync=IO.os.fsync;original_write=M.private_write

def fsync(fd):
 kind=stat.S_IFMT(os.fstat(fd).st_mode)
 if active[0]and active[0].endswith('-helper_result.json')and ((fault[0]=='result_file_fsync_failure'and kind==stat.S_IFREG)or(fault[0]=='result_directory_fsync_failure'and kind==stat.S_IFDIR)):raise Crash('result-fsync')
 if active[0]and active[0].endswith('-epoch_intent.json')and fault[0]=='intent_directory_fsync_failure'and kind==stat.S_IFDIR:raise Crash('intent-fsync')
 result=original_fsync(fd);synced.append((active[0],kind));return result
def private_write(path,data):
 active[0]=str(Path(path))
 try:return original_write(path,data)
 finally:active[0]=None
IO.os.fsync=fsync;M.private_write=private_write
M.postgres_writers=lambda _:{'superuser':True,'foreign_client_sessions':0,'prepared_transactions':0}
def intent(a):return {'format':1,'operation':a.operation,'action':a.action,'bundle_manifest_sha256':a.plan['bundle_manifest'],'prior_epoch_sha256':M.digest(a.prior.encode())if a.prior is not None else None,'next_epoch_sha256':M.digest(a.next.encode())}
def records(a,stage):return sorted(a.private.glob('*-'+stage+'.json'))
def durable_intent(a):
 paths=records(a,'epoch_intent')
 if len(paths)!=1:return False
 raw=paths[0].read_bytes();value=json.loads(raw);kinds=[kind for name,kind in synced if name==str(paths[0])]
 return value==intent(a)and paths[0].stat().st_mode&0o777==0o600 and stat.S_IFREG in kinds and stat.S_IFDIR in kinds and a.next.encode()not in raw
# Actual checkpoint must fsync the binding before its first helper phase.
for intent_fault in(None,'intent_directory_fsync_failure'):
 with tempfile.TemporaryDirectory()as directory:
  fault[0]=intent_fault
  a=M.Admin.__new__(M.Admin);a.private=Path(directory);a.seq=0;a.operation='1'*32;a.action='install';a.plan={'bundle_manifest':'b'*64};a.bundle_pins={'owned':{'Owned.php':'d'*64}}
  a.initial={'forms':{},'mcp':{},'circles':{},'config':{'config/config.php':{'sha256':'e'*64}}};a.archives=lambda _:copy.deepcopy(a.initial);a._sources=lambda:None
  data=b'COPY public.oc_migrations (app, version) FROM stdin;\nforms\told\n\\.\nCOPY public.oc_appconfig (appid, configkey, configvalue) FROM stdin;\nforms\tinstalled_version\t1.0\nmcp\tinstalled_version\t1.0\ncircles\tinstalled_version\t1.0\n\\.\n'
  M.database_checkpoint=lambda db,path,custom:private_write(path,data)
  M.file_bytes=lambda path,name:b'<info><version>1.0</version></info>'if name.endswith('info.xml')else b'<?php //synthetic'
  observed=[]
  def phase(name):observed.append(name);assert durable_intent(a),'intended epoch was not durable before first phase';return {'data_digest':'0'*64}
  a._phase=phase
  if intent_fault is None:
   try:a.checkpoint();results['checkpoint-fsync-before-phase']=observed==['snapshot']and durable_intent(a)
   except AssertionError:results['checkpoint-fsync-before-phase']=False
  else:
   failed=False
   try:a.checkpoint()
   except Crash:failed=True
   results['intent-fsync-failure-no-phase']=failed and observed==[]and not hasattr(a,'epoch_intent_receipt')

for point in('missing_intent','changed_operation','changed_next','changed_intent_action','truncated_intent','after_commit','result_file_fsync_failure','result_directory_fsync_failure','before_result_write','after_result_write','before_remove','after_remove','after_removed_receipt','phase_complete','success'):
 with tempfile.TemporaryDirectory()as directory:
  fault[0]=point
  a=M.Admin.__new__(M.Admin);a.private=Path(directory);a.seq=0;a.operation='2'*32;a.action='rollback';a.prior='a'*64;a.next='c'*64;a.plan={'bundle_manifest':'b'*64,'containers':{M.APP:{'image':'same','mounts':[{'Type':'volume','Destination':M.ROOT,'RW':True,'Name':'volume'}]}}}
  a.package=a.private;a.bundle=a.private;a.code_mount=a.private/'code';a.bundle_mount=a.private/'bundle';a._verify_package=lambda:None;a._mirrors_exact=lambda:None;a.helper_id=None;a.net='f'*64;a.interval=Interval();a.snapshot=lambda _:{'private_consumers':{i:{}for i in[M.APP,M.DB]+([a.helper_id]if a.helper_id else[])},'private_internal':True}
  a.before={'forms':{},'mcp':{},'circles':{},'config':{'config/mcp_forms_group_install.json':{'sha256':'old-metadata'}}};a.expected_config=copy.deepcopy(a.before['config']);a.archives=lambda _:copy.deepcopy(a.before)
  a._profile=lambda:{'operation':a.operation,'action':a.action,'prior_epoch':a.prior,'next_epoch':a.next}
  if point!='missing_intent':
   a.journal('epoch_intent',intent(a));f=records(a,'epoch_intent')[0];a.epoch_intent_receipt={'path':f.name,'sha256':M.digest(f.read_bytes())}
  if point=='changed_operation':a.operation='3'*32
  if point=='changed_next':a.next='e'*64
  if point=='changed_intent_action':
   f=records(a,'epoch_intent')[0];value=json.loads(f.read_bytes());value['action']='install';f.write_text(json.dumps(value));a.epoch_intent_receipt['sha256']=M.digest(f.read_bytes())
  if point=='truncated_intent':records(a,'epoch_intent')[0].write_bytes(b'{')
  real_journal=a.journal
  def journal(stage,value):
   if stage=='helper_result'and point=='before_result_write':raise Crash('before-result')
   result=real_journal(stage,value)
   if stage=='helper_result'and point=='after_result_write':raise Crash('after-result')
   if stage=='helper_removed'and point=='after_removed_receipt':raise Crash('after-removed-receipt')
   if stage=='phase_complete'and point=='phase_complete':raise Crash('after-phase-receipt')
   return result
  a.journal=journal;db={'epoch':a.prior};events=[];helper={'exists':False}
  meta={'running':False,'image':'same','ports':{},'networks':{'private':{'NetworkID':a.net}},'exit_code':0,'tmpfs':{'/profiles':'rw,noexec,nosuid,size=2m,mode=0700,uid=33,gid=33'},'mounts':[{'Destination':M.ROOT,'Name':'volume','RW':True},{'Destination':'/installer','Source':str(a.code_mount),'RW':False},{'Destination':'/bundle','Source':str(a.bundle_mount),'RW':False},{'Destination':'/profiles','Type':'tmpfs'}]}
  M.container_metadata=lambda _:copy.deepcopy(meta)
  def child(command,**kw):
   events.append(command[1])
   if command[1]=='create':helper['exists']=True;return b'd'*64+b'\n'
   if command[1]=='start':
    profile=json.loads(kw['input_data'])['profile'];assert profile['next_epoch']==a.next
    db['epoch']=profile['next_epoch']
    if point=='after_commit':raise Crash('commit-ack-lost')
    return json.dumps({'ok':True,'sequence':1,'stage':'retire'}).encode()
   if command[1]=='rm':
    assert records(a,'helper_result'),'helper deleted before durable result receipt'
    receipt=records(a,'helper_result')[0];kinds=[kind for name,kind in synced if name==str(receipt)]
    assert stat.S_IFREG in kinds and stat.S_IFDIR in kinds and receipt.stat().st_mode&0o777==0o600,'result was not fsynced before helper deletion'
    if point=='before_remove':raise Crash('before-remove')
    helper['exists']=False
    if point=='after_remove':raise Crash('remove-ack-lost')
    return b'd'*64+b'\n'
   raise AssertionError('unexpected external call')
  M.child=child;error=None
  try:a._phase('retire')
  except BaseException as caught:error=caught
  if point in('missing_intent','changed_operation','changed_next','changed_intent_action','truncated_intent'):results[point]=isinstance(error,M.Refused)and str(error)=='Durable epoch intent unavailable'and events==[]and db['epoch']==a.prior;continue
  bound=durable_intent(a)and M.digest(db['epoch'].encode())==intent(a)['next_epoch_sha256']
  has_result=bool(records(a,'helper_result'));removed=not helper['exists']
  if point in('after_commit','before_result_write'):ok=isinstance(error,Crash)and not has_result and not removed
  elif point in('result_file_fsync_failure','result_directory_fsync_failure'):
   receipt=records(a,'helper_result')[0];kinds=[kind for name,kind in synced if name==str(receipt)]
   ok=isinstance(error,Crash)and not removed and stat.S_IFDIR not in kinds
  elif point in('after_result_write','before_remove'):ok=isinstance(error,Crash)and has_result and not removed
  elif point in('after_remove','after_removed_receipt','phase_complete'):ok=isinstance(error,Crash)and has_result and removed
  else:ok=error is None and has_result and removed and bool(records(a,'phase_complete'))
  if has_result:
   result_path=records(a,'helper_result')[0];value=json.loads(result_path.read_bytes());ok=ok and value.get('epoch_intent_sha256')==a.epoch_intent_receipt['sha256']and value.get('helper_id')=='d'*64 and value.get('phase')=='retire'
  results[point]=bound and ok
IO.os.fsync=original_fsync
print(json.dumps({'controls':results,'actual_private_fsync':True,'docker_calls':0,'native_bootstraps':0,'raw_epoch_values_emitted':0}))
assert all(results.values()),'durable recovery binding or result-before-removal ordering missing'
