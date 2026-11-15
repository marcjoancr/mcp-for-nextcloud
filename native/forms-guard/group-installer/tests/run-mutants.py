from pathlib import Path
import hashlib,json,shutil,subprocess,tempfile
P=Path(__file__).resolve().parents[1]
cases=[
 ('host/GroupInstallStartup.py','if actual[key] is not True:','if False:','startup-pure.py'),
 ('host/GroupInstallArchive.py','if expected!=after:','if False:','archive-pure.py'),
 ('host/GroupInstallCoordinator.py','self.sink.final_invariants();self.sink.release()','self.sink.release();self.sink.final_invariants()','coordinator-pure.py'),
 ('host/GroupInstallDbState.py','if name not in (epoch,history,migrations) and sorted(rows)!=sorted(a[name]):','if False:','db-state-pure.py'),
 ('host/GroupInstallTopology.py',"out.pop('IPAddress');out.pop('IPPrefixLen')","out.pop('IPAddress');out.pop('IPPrefixLen');out.pop('MacAddress')",'network-pure.py'),
 ('host/GroupInstallAdmin.py','if result!=expected:','if False:','host-phase-pure.py'),
 ('host/GroupInstallHostIO.py','if size>limit:','if False:','host-io-pure.py'),
 ('standalone/GroupInstallProtocol.php','if($r!==$base)','if(false)','protocol-pure.php'),
 ('standalone/GroupInstallProfile.php','if($actual!==$expected)','if(false)','profile-pure.php'),
 ('standalone/GroupInstallTree.php',"$hash=hash_file('sha256',$path);","$hash=str_repeat('0',64);",'tree-pure.php'),
 ('standalone/GroupInstallStartupProbe.php','new \\RecursiveIteratorIterator','new RecursiveIteratorIterator','composed-startup-pure.py'),
 ('host/GroupInstallNetwork.py',"if not isinstance(networks,dict) or len(networks)!=1 or not isinstance(next(iter(networks.values())),dict) or next(iter(networks.values())).get('NetworkID')!=expected:",'if False:','active-networks-pure.py'),
 ('host/GroupInstallAdmin.py',"result_receipt=self.journal('helper_result',{**binding,'result':result,'container_exited':True,'foreign_client_sessions':0,'prepared_transactions':0})","result_receipt={'sha256':'0'*64}",'epoch-recovery-pure.py'),
 ('host/GroupInstallAdmin.py','intent=self._assert_epoch_intent()',"intent={'sha256':'0'*64}",'epoch-recovery-pure.py'),
]
results=[]
for source,old,new,test in cases:
 with tempfile.TemporaryDirectory(prefix='installer-mutant-',dir=P/'tests')as directory:
  target=Path(directory)
  for folder in('host','standalone','tests'):
   (target/folder).mkdir()
   for f in(P/folder).iterdir():
    if f.is_file()and f.suffix in('.php','.py'):shutil.copyfile(f,target/folder/f.name)
  original=(P/source).read_text();assert original.count(old)==1
  modified=original.replace(old,new);(target/source).write_text(modified)
  command=['php'if test.endswith('.php')else'python3',str(target/'tests'/test)]
  r=subprocess.run(command,capture_output=True,text=True,timeout=20)
  results.append({'source':source,'old':old,'new':new,'original_sha256':hashlib.sha256(original.encode()).hexdigest(),'mutant_sha256':hashlib.sha256(modified.encode()).hexdigest(),'test':test,'exit':r.returncode,'stdout':r.stdout,'stderr':r.stderr})
  assert r.returncode!=0,(source,'mutation survived')
(P/'tests/mutant-results.json').write_text(json.dumps(results,indent=2)+'\n')
print(json.dumps({'meaningful_mutants_red':len(results),'native_runtime':False,'original_sources_unchanged':True}))
