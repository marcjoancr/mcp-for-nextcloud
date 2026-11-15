import sys,copy
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'host'))
from GroupInstallNetwork import NetworkInterval
from GroupInstallTopology import Refused
A='a'*64;D='d'*64;F='f'*64;N='1'*64;P='2'*64
class Engine:
 def __init__(self,fail=None):
  self.calls=[];self.fail=fail;self.shared={i:{'NetworkID':N,'IPAddress':'10.1.0.'+str(n),'Aliases':None,'IPAMConfig':None,'MacAddress':str(n),'IPPrefixLen':24}for n,i in enumerate([A,D,F],2)};self.original=copy.deepcopy(self.shared);self.private={};self.created=False
 def mutation(self,path,payload,expected):
  self.calls.append((path,copy.deepcopy(payload)))
  if len(self.calls)==self.fail:raise Refused('injected')
  if path.endswith('/create'):self.created=True;return {'Id':P,'Warning':''}
  net=path.split('/')[-2];target=self.shared if net==N else self.private;ident=payload['Container']
  assert ident in(A,D)
  if path.endswith('/disconnect'):assert payload['Force']is False;del target[ident]
  else:
   config=payload['EndpointConfig'];assert ('MacAddress'in config)==(net==N)
   target[ident]=copy.deepcopy(self.original[ident]);target[ident]['NetworkID']=net
   if net==N:target[ident]['IPAddress']='10.1.0.99';target[ident]['MacAddress']=config['MacAddress']
 def snapshot(self,private):
  return {'app_running':False,'app_id':A,'db_id':D,'owned_networks':{i:{**({'original':copy.deepcopy(self.shared[i])}if i in self.shared else{}),**({'private':copy.deepcopy(self.private[i])}if i in self.private else{})}for i in(A,D)},'network':{'id':N,'internal':True,'consumers':copy.deepcopy(self.shared)},'foreign_processes':{F:{'pid':17}},'private_consumers':copy.deepcopy(self.private),'private_network_id':private,'private_internal':True,'helper_present':False}
def build(fail=None):
 e=Engine(fail);log=[];p={'app_id':A,'db_id':D,'network_id':N,'private_name':'synthetic','operation':'b'*32,'dns_proofs':[A,D]};n=NetworkInterval(e,p,e.snapshot,lambda:{'superuser':True,'foreign_client_sessions':0,'prepared_transactions':0},lambda *x:log.append(x));return e,n,log
e,n,log=build();assert n.isolate()==P;assert set(e.private)=={A,D};n.restore();assert n.phase=='restored'and set(e.shared)=={A,D,F};assert e.shared[F]==e.original[F]
checks=4
for failure in range(1,10):
 e,n,_=build(failure);denied=False
 try:n.isolate();n.restore()
 except Refused:denied=True
 assert denied and n.phase=='hold',failure;checks+=1
 denied=False
 try:n.restore()
 except Refused:denied=True
 assert denied;checks+=1
# Foreign endpoint drift and new helper both block release before any reconnect.
e,n,_=build();n.isolate();e.shared[F]['IPAddress']='10.1.0.18';before=len(e.calls);denied=False
try:n.restore()
except Refused:denied=True
assert denied and len(e.calls)==before;checks+=1
print({'checks':checks,'engine_calls':'synthetic-only','foreign_mutations':0})

# Driver ignoring requested observed MAC must refuse; never silently accept a new wire address.
e,n,_=build();n.isolate();original=e.mutation
def ignores(path,payload,expected):
 result=original(path,payload,expected)
 if path.endswith(N+'/connect'):e.shared[payload['Container']]['MacAddress']='different'
 return result
e.mutation=ignores;denied=False
try:n.restore()
except Refused:denied=True
assert denied and n.phase=='hold'
print({'additional_mac_mismatch_checks':1})
