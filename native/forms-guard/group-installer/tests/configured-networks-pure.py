# SPDX-License-Identifier: AGPL-3.0-or-later
"""Compose real snapshot/network/start routes with Docker's configured/active lifecycle."""
import copy,json,sys
from pathlib import Path
base=Path(sys.argv[1])if len(sys.argv)>1 else Path(__file__).resolve().parents[1]
sys.path.insert(0,str(base/'host'))
import GroupInstallAdmin as M
from GroupInstallNetwork import NetworkInterval
from GroupInstallTopology import Refused
A,D,N=M.APP,M.DB,M.NETWORK;F='f'*64;P='2'*64;H='4'*64
class Engine:
 def __init__(self):
  self.running={A:True,D:True,F:True};self.where={A:N,D:N,F:N};self.requested={};self.unknown=set();self.calls=[]
 def endpoint(self,i):
  running=self.running[i];original='02:00:00:00:00:'+('01'if i==A else'02')
  return {'NetworkID':self.where[i],'IPAddress':'10.1.0.2'if running else'','Aliases':None,'IPAMConfig':None,'MacAddress':self.requested.get(i,original)if running else'','IPPrefixLen':24 if running else 0,'Gateway':'','GlobalIPv6Address':'','GlobalIPv6PrefixLen':0,'IPv6Gateway':'','DriverOpts':None,'GwPriority':0,'Links':None,'DNSNames':[i]}
 def meta(self,i):return {'running':self.running[i],'networks':{'configured':self.endpoint(i)}if i in self.where else{},'pid':17 if self.running[i]else 0,'exit_code':0}
 def child(self,cmd,**kw):
  if cmd[:3]==['docker','network','inspect']:
   fmt,network=cmd[4:6]
   if fmt=='{{json .Internal}}':return b'true'
   assert fmt=='{{json .Containers}}'
   active={i:{}for i,n in self.where.items()if n==network and self.running[i]}
   if network==P:active.update({i:{}for i in self.unknown})
   return json.dumps(active).encode()
  assert cmd[0]=='docker'and cmd[1]in('start','stop');self.running[cmd[-1]]=cmd[1]=='start';return cmd[-1].encode()
 def mutation(self,path,payload,expected):
  self.calls.append((path,copy.deepcopy(payload)))
  if path.endswith('/create'):
   assert payload['IPAM']=={'Driver':'default','Config':[{'Subnet':'10.239.114.0/24'}]}
   return {'Id':P,'Warning':''}
  i=payload['Container'];assert i in(A,D)
  if path.endswith('/disconnect'):del self.where[i]
  else:
   self.where[i]=path.split('/')[-2]
   if'MacAddress'in payload['EndpointConfig']:self.requested[i]=payload['EndpointConfig']['MacAddress']
e=Engine();M.container_metadata=e.meta;M.child=e.child;M.postgres_writers=lambda _: {'superuser':True,'foreign_client_sessions':0,'prepared_transactions':0}
a=M.Admin.__new__(M.Admin);a.plan={'consumer_ids':[A,D,F]};a.helper_id=None;a.journal=lambda *args:None;a.operation='a'*32;a.action='install'
initial=a.snapshot(None);assert set(initial['network']['consumers'])=={A,D,F}
a.stop();stopped=a.snapshot(None);assert set(stopped['network']['consumers'])=={A,D,F}and not stopped['app_running'];assert a.running_network==initial
profile={'app_id':A,'db_id':D,'network_id':N,'private_name':'synthetic','operation':a.operation,'dns_proofs':[A,D]}
n=NetworkInterval(e,profile,a.snapshot,lambda:M.postgres_writers(D),a.journal,before_stop=a.running_network);a.interval=n;a.net=n.isolate();n.assert_isolated(a.snapshot(P))
# Created, running and exited helpers retain configuration but have different active membership.
e.running[H]=False;e.where[H]=P;a.helper_id=H
for running in(False,True,False):
 e.running[H]=running;n.assert_isolated(a.snapshot(P),helper_id=H)
del e.where[H];del e.running[H];a.helper_id=None
# An unknown active endpoint is rejected even though known stopped endpoints are admitted.
e.unknown.add('9'*64)
try:a.snapshot(P)
except Refused:pass
else:raise AssertionError('unknown active endpoint admitted')
e.unknown.clear();e.running[A]=True;n.assert_isolated(a.snapshot(P),require_stopped=False);e.running[A]=False
n.restore();assert n.phase=='restored';assert e.requested[A]==initial['network']['consumers'][A]['MacAddress'];assert e.requested[D]==initial['network']['consumers'][D]['MacAddress']
a.released=True;a.final_proved=True;a.start_released();assert e.running[A]
# A driver that ignores the requested MAC must fail the actual final-start route.
e.requested[A]='02:00:00:00:ff:ff'
try:a.start_released()
except Refused:pass
else:raise AssertionError('final wire MAC drift admitted')
print(json.dumps({'ok':True,'configured_active_lifecycle':True,'helper_states':3,'unknown_endpoint_and_final_mac_refused':True,'docker_calls':0}))
