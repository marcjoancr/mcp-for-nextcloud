"""Actual preflight/isolate/helper/turnover/restore routes; all external sinks are synthetic."""
import copy,json,sys,tempfile
from pathlib import Path
from types import SimpleNamespace
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'host'))
import GroupInstallAdmin as M
from GroupInstallNetwork import NetworkInterval
from GroupInstallTopology import Refused
A=M.APP;D=M.DB;N=M.NETWORK;P='2'*64;X='3'*64;F='f'*64
class Engine:
 def __init__(self):
  self.calls=[];self.extra={A:{},D:{}};self.shared={i:{'NetworkID':N,'IPAddress':'10.1.0.'+str(n),'Aliases':None,'IPAMConfig':None,'MacAddress':str(n),'IPPrefixLen':24}for n,i in enumerate([A,D,F],2)};self.original=copy.deepcopy(self.shared);self.private={};self.app_running=False
 def mutation(self,path,payload,expected):
  self.calls.append((path,copy.deepcopy(payload)))
  if path.endswith('/create'):return {'Id':P,'Warning':''}
  net=path.split('/')[-2];target=self.shared if net==N else self.private;ident=payload['Container'];assert ident in(A,D)
  if path.endswith('/disconnect'):assert payload['Force']is False;del target[ident]
  else:target[ident]=dict(self.original[ident],NetworkID=net)
 def maps(self,ident):
  out=copy.deepcopy(self.extra[ident])
  if ident in self.shared:out['original']=copy.deepcopy(self.shared[ident])
  if ident in self.private:out['private']=copy.deepcopy(self.private[ident])
  return out
 def snapshot(self,private):
  return {'app_running':self.app_running,'app_id':A,'db_id':D,'owned_networks':{i:self.maps(i)for i in(A,D)},'network':{'id':N,'internal':True,'consumers':copy.deepcopy(self.shared)},'foreign_processes':{F:{'pid':17}},'private_consumers':copy.deepcopy(self.private),'private_network_id':private,'private_internal':True,'helper_present':False}
def build():
 e=Engine();profile={'app_id':A,'db_id':D,'network_id':N,'private_name':'synthetic','operation':'b'*32,'dns_proofs':[A,D]}
 return e,NetworkInterval(e,profile,e.snapshot,lambda:{'superuser':True,'foreign_client_sessions':0,'prepared_transactions':0},lambda *x:None)
def refused(call):
 try:call()
 except Refused as error:return str(error)=='Owned container network map differs'
 except Exception:return False
 return False
results={}
# Admin preflight must refuse an extra own endpoint before even starting its source probes.
for role in(A,D):
 with tempfile.TemporaryDirectory()as directory:
  calls=[];a=M.Admin.__new__(M.Admin);a.action='install';a.private=Path(directory);a._startup=lambda:calls.append('startup');a._sources=lambda:None;a.journal=lambda *x:None
  inventory={'forms':{'forms/mcp-guard-installed.json':{'type':'file','sha256':'a'*64,'size':0}},'mcp':{},'circles':{},'config':{}}
  a.archives=lambda _:copy.deepcopy(inventory)
  metas={i:{'id':i,'name':'synthetic','image':'same-image','mounts':[],'label':'20261113','ports':{},'running':True,'extra_hosts':[],'networks':{'original':{'NetworkID':N}}}for i in M.CONSUMERS}
  a.plan={'consumer_ids':M.CONSUMERS,'containers':{i:{k:v[k]for k in('id','name','image','mounts')}for i,v in metas.items()},'app_inventories':{k:v for k,v in inventory.items()if k!='config'},'prepared_marker':'a'*64,'config_pins':{}}
  M.container_metadata=lambda i:copy.deepcopy(metas[i]);M.os.statvfs=lambda _:SimpleNamespace(f_bavail=10**12,f_frsize=4096)
  a.preflight('install');assert calls==['startup'];calls.clear()
  metas[role]['networks']['unexpected']={'NetworkID':X}
  results['preflight-'+('app'if role==A else'db')]=refused(lambda:a.preflight('install'))and calls==[]
  M.child=lambda *args,**kw:calls.append('mutation')or b''
  results['initial-stop-'+('app'if role==A else'db')]=refused(a.stop)and calls==[]
# NetworkInterval must independently reject at its actual mutation boundary.
for role in(A,D):
 e,n=build();e.extra[role]['unexpected']={'NetworkID':X}
 results['isolate-'+('app'if role==A else'db')]=refused(n.isolate)and e.calls==[]
# An endpoint added after isolation cannot reach helper or turnover effects, or restoration.
for route in('helper','turnover','health','restore'):
 e,n=build();n.isolate();before=len(e.calls);e.extra[D]['unexpected']={'NetworkID':X};effects=[]
 a=M.Admin.__new__(M.Admin);a.interval=n;a.net=P;a.helper_id=None;a._verify_package=lambda:None;a._mirrors_exact=lambda:None;a.snapshot=e.snapshot;a._assert_epoch_intent=lambda:None
 a._profile=lambda:effects.append('profile')or {};a._startup=lambda:None;a.journal=lambda *x:None
 M.container_metadata=lambda i:{'running':False};M.postgres_writers=lambda _:{'superuser':True,'foreign_client_sessions':0,'prepared_transactions':0}
 def child(command,**kw):effects.append(command);return b'200'
 M.child=child
 call={'helper':lambda:a._phase('inspect'),'turnover':a.turnover,'health':a.health,'restore':n.restore}[route]
 results['isolated-'+route]=refused(call)and effects==[]and len(e.calls)==before
# Compose actual Admin.snapshot with actual NetworkInterval as well: full maps must survive collection.
e=Engine();a=M.Admin.__new__(M.Admin);a.plan={'consumer_ids':[A,D,F]};a.helper_id=None
profile={'app_id':A,'db_id':D,'network_id':N,'private_name':'synthetic','operation':'b'*32,'dns_proofs':[A,D]}
def metadata(ident):
 maps=e.maps(ident)if ident in(A,D)else{'original':copy.deepcopy(e.shared[F])}
 return {'running':ident!=A,'networks':maps,'pid':17}
def inspect(command,**kw):
 assert command[:3]==['docker','network','inspect']
 fmt=command[4];network=command[5]
 if network==N:return b'true'if fmt=='{{json .Internal}}'else json.dumps({key:{}for key in e.shared}).encode()
 assert network==P;return b'true\n'+json.dumps(e.private).encode()+b'\n'
M.container_metadata=metadata;M.child=inspect
n=NetworkInterval(e,profile,a.snapshot,lambda:{'superuser':True,'foreign_client_sessions':0,'prepared_transactions':0},lambda *args:None)
original=a.snapshot(None)
results['actual-snapshot-full-maps']=original['owned_networks']=={A:e.maps(A),D:e.maps(D)}
n.isolate();results['actual-snapshot-positive-isolation']=set(e.private)=={A,D}
e.extra[D]['unexpected']={'NetworkID':X};before=len(e.calls)
results['actual-snapshot-extra-private-refusal']=refused(n.restore)and len(e.calls)==before
print(json.dumps({'controls':results,'docker_calls':0,'native_bootstraps':0}))
assert all(results.values()),'active route did not reject the full extra endpoint map'
