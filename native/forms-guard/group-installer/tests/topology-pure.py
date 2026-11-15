from pathlib import Path
import sys,copy
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'host'))
from GroupInstallTopology import endpoint_binding,restoration,sessions,dns_dependency,release_bindings,Refused
n=0
def check(ok):
 global n;n+=1
 if not ok:raise AssertionError('test failed')
def deny(fn,message):
 got=None
 try:fn()
 except Refused as e:got=str(e)
 check(got==message)
e={'NetworkID':'synthetic-network','IPAddress':'172.30.1.2','Aliases':['db'],'IPAMConfig':None,'DriverOpts':None,'GwPriority':0,'MacAddress':'02:42:ac:1e:01:02','EndpointID':'before'}
check(endpoint_binding(e)['IPAddress']=='172.30.1.2')
b={'id':'synthetic-network','internal':True,'consumers':{'db':e,'other':{'opaque':'exact'}}};a=copy.deepcopy(b);a['consumers']['db']['EndpointID']='new';restoration(b,a,'db');check(True)
a['consumers']['other']['opaque']='changed';deny(lambda:restoration(b,a,'db'),'Foreign endpoint changed')
a=copy.deepcopy(b);a['consumers']['db']['IPAddress']='172.30.1.3';deny(lambda:restoration(b,a,'db'),'Database endpoint restoration uncertain')
for field,value in [('GlobalIPv6Address','fd00::1'),('DriverOpts',{'anything':'value'}),('GwPriority',1),('Aliases',['db','db'])]:
 x=copy.deepcopy(e);x[field]=value
 try:endpoint_binding(x)
 except Refused:check(True)
 else:check(False)
sessions({'superuser':True,'foreign_client_sessions':0,'prepared_transactions':0});check(True)
for field,value in [('superuser',False),('foreign_client_sessions',1),('prepared_transactions',1)]:
 x={'superuser':True,'foreign_client_sessions':0,'prepared_transactions':0};x[field]=value;deny(lambda:sessions(x),'Database writer quiescence unavailable')
check(dns_dependency('synthetic-db:5432','/synthetic-db',[])['host']=='synthetic-db')
deny(lambda:dns_dependency('172.30.1.2','/synthetic-db',[]),'Database host binding unqualified')
deny(lambda:dns_dependency('synthetic-db','/synthetic-db',['synthetic-db:172.30.1.2']),'DNS consumer profile unqualified')
a=copy.deepcopy(b);a['consumers']['db']['IPAddress']='172.30.1.8';a['consumers']['db']['EndpointID']='new';release_bindings(b,a,{'db'},{'db'});check(True)
a['consumers']['db']['IPAMConfig']={'IPv4Address':'172.30.1.8'};deny(lambda:release_bindings(b,a,{'db'},{'db'}),'Static address profile requires exact restoration')
print({'ok':True,'checks':n,'pure_only':True})
