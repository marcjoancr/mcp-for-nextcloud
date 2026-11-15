#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Deliberate invariant breaks in disposable project-local copies. Never runs a native graph."""
from pathlib import Path
import subprocess,tempfile,shutil,json,hashlib
D=Path(__file__).resolve().parent.parent
from repository_root import REPOSITORY_ROOT
WT=REPOSITORY_ROOT
mutants=[
 ('cache-identity','src/FinalShareCache.php',"(int)$row['fileid']!==$id",'false','final-cache.php'),
 ('row-overflow','src/FinalShareBudget.php','if (++$count>$maximum)','if (false)','pure.php'),
 ('activation-tuple','src/FinalShareCommitment.php','!hash_equals($this->sealed,$actual->sealed)','false','pure.php'),
 ('original-share','src/FinalShareRows.php',"if (!isset($record['core_state']) || $actual!==$record['core_state'])","if (false)",'final-rows.php'),
 ('expiration-clock','src/TransferExpiryInputs.php','if ($today>=$expiry)','if (false)','final-expiry.php'),
 ('witness-generation','standalone/GroupWitnessState.php',"$captured!==['epoch'=>$epoch,'generation'=>$row['generation']]",'false','group-witness-state.php'),
 ('canonical-gid','standalone/GroupIdentity.php',"$captured['gid']!==$current",'false','group-identity.php'),
 ('rollback-finally','standalone/GroupLeaseFlow.php','if($inTransaction())$rollback();','if(false)$rollback();','group-lease-flow.php'),
 ('registry-unknown','standalone/GroupRawRegistry.php','!in_array($class,$classes,true)','false','group-raw-registry.php'),
 ('native-normalizer','standalone/GroupIdentity.php',"$method->invoke($backend,$name)",'$name','GroupIdentityNativeTest.php'),
 ('private-frame','src/TransferTimeZoneSession.php','($this->frame)();','/* deliberate skipped frame */','TimeZoneTest.php'),
 ('projection-replay','src/FinalShareReadProvider.php','$this->used=true;','$this->used=false;','NativeDuplicateTest.php'),
 ('native-create-acl','src/FinalNativeAcl.php','($create && (!$node instanceof \\OCP\\Files\\Folder || !$node->isCreatable()))','false','FinalNativeAclTest.php'),
 ('joint-snapshot-skipped','adapters/TransferFinalFiles.php','$sharing->verifySnapshot();','/* deliberate missing final snapshot */','JointSchedulerTest.php'),
 ('read-set-mismatch','src/FinalReadSet.php','if(!self::equalRows($query[3],$actual[$i]))','if(false)','NativeFinalSnapshotTest.php'),
 ('cursor-finally','src/FinalReadWindow.php','finally{$r->closeCursor();}','finally{}','FinalReadCursorTest.php'),
 ('read-latency','src/FinalReadWindow.php','if($clock()-$start>1000000000)','if(false)','FinalReadCursorTest.php'),
 ('file-snapshot-bind','src/FinalReadFiles.php','if($node->getPermissions()!==$recorded)','if(false)','NativeFinalSnapshotTest.php'),
]
results=[]
for name,file,before,after,test in mutants:
 with tempfile.TemporaryDirectory(prefix='final-share-pure-mutant-',dir=D.parent) as temp:
  T=Path(temp)
  for folder in ['src','adapters','standalone','tests','references']:
   shutil.copytree(D/folder,T/folder,ignore=shutil.ignore_patterns('*.log','*.txt','*.json','run-mutants.py'))
  path=T/file;text=path.read_text();count=text.count(before)
  if count<1:raise RuntimeError('missing mutant site '+name)
  path.write_text(text.replace(before,after))
  if name=='joint-stale-sharing':
   text=path.read_text();text=text.replace('    $sharing->renewViews($budget);','');path.write_text(text)
  cmd=['php',str(T/'tests'/test)]
  if test.endswith('Test.php'):
   cmd=[str(WT/'mcp/vendor/bin/phpunit'),'--do-not-cache-result','--no-configuration','--bootstrap',str(WT/'mcp'/('tests/bootstrap.php' if test=='JointSchedulerTest.php' else 'vendor/autoload.php')),str(T/'tests'/test)]
  r=subprocess.run(cmd,capture_output=True,timeout=60)
  output=r.stdout+r.stderr
  meaningful=r.returncode!=0 and b'Parse error' not in output and b'Syntax error' not in output and (b'FAILURES!' in output or b'assertion failed' in output or b'test failed' in output)
  (D/'tests'/('mutant-'+name+'.log')).write_bytes(output)
  results.append({'name':name,'path':file,'before':before,'after':after,'test':test,'sites':count,'exit_code':r.returncode,'meaningful_red':meaningful,'output_sha256':hashlib.sha256(output).hexdigest()})
  print(json.dumps({'mutant':name,'meaningful_red':meaningful,'exit_code':r.returncode}))
  if not meaningful:print(output.decode());break
receipt={'all_meaningful_red':len(results)==len(mutants) and all(r['meaningful_red'] for r in results),'mutants':results}
(D/'tests/mutant-receipt.json').write_text(json.dumps(receipt,indent=2)+'\n')
raise SystemExit(0 if receipt['all_meaningful_red'] else 1)
