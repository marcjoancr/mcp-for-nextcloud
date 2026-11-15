import sys,tempfile,tarfile,io,copy
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'host'))
from GroupInstallArchive import inventory,changed,Refused
with tempfile.TemporaryDirectory()as d:
 p=Path(d)/'x.tar'
 def make(kind=tarfile.REGTYPE,name='config/owned'):
  with tarfile.open(p,'w')as a:
   r=tarfile.TarInfo('config');r.type=tarfile.DIRTYPE;r.mode=0o700;a.addfile(r)
   f=tarfile.TarInfo(name);f.type=kind;f.size=3 if kind==tarfile.REGTYPE else 0;f.mode=0o600;f.uid=f.gid=33;a.addfile(f,io.BytesIO(b'abc')if f.size else None)
 make();v=inventory(p,'config');assert v['config/owned']['size']==3;assert changed(v,v,{})
 n=2
 for kind,name in [(tarfile.SYMTYPE,'config/owned'),(tarfile.LNKTYPE,'config/owned'),(tarfile.REGTYPE,'../escape'),(tarfile.REGTYPE,'other/x')]:
  make(kind,name);denied=False
  try:inventory(p,'config')
  except Refused:denied=True
  assert denied;n+=1
 for field,value in [('sha256','x'),('mode',0o644),('uid',0),('size',4)]:
  a=copy.deepcopy(v);a['config/owned'][field]=value;denied=False
  try:changed(v,a,{})
  except Refused:denied=True
  assert denied;n+=1
 print({'checks':n,'archive_extractions':0,'docker_calls':0})
