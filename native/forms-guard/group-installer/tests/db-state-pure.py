from pathlib import Path
import sys
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'host'))
from GroupInstallDbState import parse,compare,Refused
base=b'COPY public.oc_migrations (app, version) FROM stdin;\nforms\told\n\\.\nCOPY public.oc_groups (gid) FROM stdin;\nsynthetic\n\\.\nSELECT pg_catalog.setval(\'public.s\', 5, true);\n'
epoch='a'*64
new=base+b'COPY public.oc_forms_mcp_group_history (gid_key, gid, epoch, generation, state) FROM stdin;\n\\.\nCOPY public.oc_forms_mcp_group_epoch (id, epoch) FROM stdin;\n1\t'+epoch.encode()+b'\n\\.\n'
new=new.replace(b'forms\told\n',b'forms\told\nforms\t050402Date20261115020000\n')
n=0
def check(v):
 global n;n+=1
 if not v:raise AssertionError('test failed')
def deny(fn):
 try:fn()
 except Refused:check(True)
 else:check(False)
compare(parse(base),parse(new),epoch,True);check(True)
for a,b in [(b'synthetic',b'recreated'),(b"5, true",b"6, true"),(b'forms\t050402Date20261115020000',b'other\t050402Date20261115020000'),(b'1\t'+epoch.encode(),b'2\t'+epoch.encode())]:deny(lambda a=a,b=b:compare(parse(base),parse(new.replace(a,b)),epoch,True))
deny(lambda:parse(base+b'COPY public.oc_groups (gid) FROM stdin;\n\\.\n'))
deny(lambda:parse(base+b'COPY public.oc_extra (id) FROM stdin;\nunterminated'))
old=parse(new);next_epoch='b'*64;changed=new.replace(b'1\t'+epoch.encode(),b'1\t'+next_epoch.encode());compare(old,parse(changed),next_epoch,False);check(True)
deny(lambda:compare(old,parse(changed.replace(b'synthetic',b'new')),next_epoch,False))
print({'ok':True,'checks':n,'pure_only':True})

quoted=base+b'COPY public.oc_forms_question (id, "order") FROM stdin;\n1\t0\n\\.\n'
assert len(parse(quoted)[0])==3
empty=new.replace(b'1\t'+epoch.encode()+b'\n',b'')
compare(parse(empty),parse(new.replace(epoch.encode(),b'b'*64)),'b'*64,False)
print({'additional_quoted_identifier_and_empty_epoch_checks':2})

from GroupInstallDbState import application_versions
app_state=parse(base+b'COPY public.oc_appconfig (appid, configkey, configvalue, type, lazy) FROM stdin;\nforms\tinstalled_version\t5.4.2\t2\t0\n\\.\n')
assert application_versions(app_state,{'forms':'5.4.2'})
refused=False
try:application_versions(app_state,{'forms':'5.4.1'})
except Refused:refused=True
assert refused
print({'additional_prebootstrap_version_checks':2})
