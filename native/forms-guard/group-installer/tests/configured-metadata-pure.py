# SPDX-License-Identifier: AGPL-3.0-or-later
import copy,json,sys
from pathlib import Path
base=Path(sys.argv[1])if len(sys.argv)>1 else Path(__file__).resolve().parents[1]
sys.path.insert(0,str(base/'host'))
import GroupInstallHostIO as M
N='a'*64;calls=[]
values=['b'*64,'/task','image',False,'created','20261113',{},[],{'task-network':{'NetworkID':'','EndpointID':''}},None,0,'',0,'SIGWINCH','no',None]
def child(cmd,**kw):
 calls.append(cmd)
 if cmd[:2]==['docker','inspect']:return b'\n'.join(json.dumps(v).encode()for v in values)
 assert cmd==['docker','network','inspect','--format','{{.Id}}','task-network'];return N.encode()
M.child=child
assert M.container_metadata('task')['networks']['task-network']['NetworkID']==N
assert len(calls)==2
values[3]=True;calls.clear()
try:M.container_metadata('task')
except M.Refused:pass
else:raise AssertionError('unresolved running endpoint accepted')
assert len(calls)==1
values[3]=False;values[8]['task-network']['EndpointID']='unexpected'
try:M.container_metadata('task')
except M.Refused:pass
else:raise AssertionError('inconsistent live endpoint accepted')
print(json.dumps({'ok':True,'stopped_name_resolved':True,'running_and_inconsistent_endpoints_refused':True,'docker_calls':0}))
