import sys,tempfile,os,hashlib
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'host'))
from GroupInstallAdmin import Admin,Refused
with tempfile.TemporaryDirectory()as directory:
 root=Path(directory);package=root/'source';(package/'standalone').mkdir(parents=True,mode=0o700);bundle=root/'original-bundle';bundle.mkdir(mode=0o700);private=root/'checkpoint';private.mkdir(mode=0o700)
 for path,data in [(package/'standalone/Fixed.php',b'<?php //synthetic'),(bundle/'Known.php',b'<?php //known'),(bundle/'GroupSources.json',b'{}')]:path.write_bytes(data);path.chmod(0o600)
 a=Admin.__new__(Admin);a.package=package;a.bundle=bundle;a.private=private;a.bundle_pins={'owned':{'Known.php':hashlib.sha256((bundle/'Known.php').read_bytes()).hexdigest()}}
 a._prepare_mirrors();assert (package/'standalone/Fixed.php').stat().st_mode&0o777==0o600;assert private.stat().st_mode&0o777==0o700;assert (a.code_mount/'Fixed.php').stat().st_mode&0o777==0o644
 checks=3
 for mutation in('mode','bytes','extra'):
  f=a.code_mount/'Fixed.php';saved=f.read_bytes()
  if mutation=='mode':f.chmod(0o666)
  if mutation=='bytes':f.write_bytes(b'changed')
  if mutation=='extra':(a.code_mount/'unknown.php').write_bytes(b'unknown')
  denied=False
  try:a._mirrors_exact()
  except Refused:denied=True
  assert denied;checks+=1
  f.write_bytes(saved);f.chmod(0o644)
  if mutation=='extra':(a.code_mount/'unknown.php').unlink()
  a._mirrors_exact()
 print({'checks':checks,'original_mode_preserved':True,'docker_calls':0})
