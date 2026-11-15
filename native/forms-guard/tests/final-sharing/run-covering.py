#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Pure/synthetic/declaration checks only. No service bootstrap or graph execution."""
import subprocess,json,hashlib
from pathlib import Path
ROOT=Path(__file__).resolve().parent.parent
from repository_root import REPOSITORY_ROOT
WT=REPOSITORY_ROOT
commands=[]
for test in ['final-read-set.php','pure.php','final-cache.php','final-rows.php','final-expiry.php','final-check-lifecycle.php','group-witness-state.php','group-lease-flow.php','group-raw-registry.php','group-identity.php','declarations.php']:
 commands.append((test,['php',str(ROOT/'tests'/test)]))
for test in ['FinalReadQueryTest.php','FinalReadCursorTest.php','NativeFinalSnapshotTest.php','NativePolicyTest.php','TimeZoneTest.php','NativeDuplicateTest.php','FinalNativeAclTest.php','GroupIdentityNativeTest.php','JointSchedulerTest.php']:
 bootstrap=WT/'mcp'/('tests/bootstrap.php' if test=='JointSchedulerTest.php' else 'vendor/autoload.php')
 commands.append((test,[str(WT/'mcp/vendor/bin/phpunit'),'--do-not-cache-result','--no-configuration','--bootstrap',str(bootstrap),str(ROOT/'tests'/test)]))
results=[]
for name,cmd in commands:
 r=subprocess.run(cmd,cwd=ROOT,capture_output=True,timeout=60)
 log=r.stdout+r.stderr;(ROOT/'tests'/('cover-'+name+'.log')).write_bytes(log)
 results.append({'test':name,'command':cmd,'exit_code':r.returncode,'output_sha256':hashlib.sha256(log).hexdigest()})
 print(json.dumps({'test':name,'exit_code':r.returncode}))
 if r.returncode: print(log.decode());break
lint=[]
for folder in ['src','adapters','standalone','migration']:
 for p in sorted((ROOT/folder).glob('*.php')):
  r=subprocess.run(['php','-l',str(p)],capture_output=True)
  lint.append({'path':str(p.relative_to(ROOT)),'exit_code':r.returncode})
  if r.returncode: print(r.stdout.decode()+r.stderr.decode())
receipt={'pure_only':True,'checks':results,'syntax':lint,'ok':len(results)==len(commands) and all(r['exit_code']==0 for r in results+lint)}
(ROOT/'tests/covering-receipt.json').write_text(json.dumps(receipt,indent=2)+'\n')
print(json.dumps({'ok':receipt['ok'],'families':len(results),'syntax_files':len(lint)}))
raise SystemExit(0 if receipt['ok'] else 1)
