# SPDX-License-Identifier: AGPL-3.0-or-later
import sys,json
from pathlib import Path
from types import SimpleNamespace
sys.path.insert(0,str((Path(sys.argv[1])if len(sys.argv)>1 else Path(__file__).resolve().parents[1])/'host'))
from GroupInstallNetwork import LocalEngine
from GroupInstallTopology import Refused
for status,body,expected in [(200,b'',None),(204,b'',None),(201,b'{"Id":"new"}',{'Id':'new'}),(201,b'',False),(500,b'',False)]:
 e=LocalEngine();e.request=lambda *a,**k:None;e.getresponse=lambda:SimpleNamespace(status=status,read=lambda _:body)
 try:out=e.mutation('/v1.55/networks/create',{},(200,201,204))
 except Refused:
  assert expected is False,(status,'valid empty HTTP acknowledgement was rejected')
 else:assert expected is not False and out==expected
print(json.dumps({'ok':True,'actual_engine_response_cases':5,'docker_calls':0}))
