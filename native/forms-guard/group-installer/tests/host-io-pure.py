from pathlib import Path
import sys,tempfile,os
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'host'))
from GroupInstallHostIO import private_directory,private_write,child,Refused
checks=0
def check(v):
 global checks;checks+=1
 if not v:raise AssertionError('test failed')
def deny(f):
 try:f()
 except (Refused,FileExistsError):check(True)
 else:check(False)
with tempfile.TemporaryDirectory(prefix='installer-io-',dir=Path(__file__).parent)as t:
 d=private_directory(Path(t)/'private');check(d.stat().st_mode&0o777==0o700)
 p=d/'checkpoint';private_write(p,b'synthetic');check(p.stat().st_mode&0o777==0o600);deny(lambda:private_write(p,b'replaced'));check(p.read_bytes()==b'synthetic')
 check(child([sys.executable,'-c','print("synthetic")'])==b'synthetic\n')
 deny(lambda:child([sys.executable,'-c','print("x"*2000)'],limit=100))
 deny(lambda:child([sys.executable,'-c','import time;time.sleep(5)'],timeout=.05))
 deny(lambda:child([sys.executable,'-c','raise RuntimeError("private synthetic error")']))
 out=d/'stream';r=child([sys.executable,'-c','print("private synthetic")'],output_path=out);check(r['private']and out.stat().st_mode&0o777==0o600)
print({'ok':True,'checks':checks,'pure_only':True,'docker_or_database_calls':0})
