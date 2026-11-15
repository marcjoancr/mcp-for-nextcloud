#!/usr/bin/env python3
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Explicit disposable-profile administrator entry point; no effects on import."""
import argparse,json,sys
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parent/'host'))
from GroupInstallAdmin import Admin
from GroupInstallCoordinator import Coordinator

def main():
 p=argparse.ArgumentParser(description='Owned Forms group bundle installer: qualified disposable PG profile only')
 p.add_argument('--root',required=True);p.add_argument('--plan',required=True);p.add_argument('--checkpoint',required=True)
 p.add_argument('action',choices=['install','validate','rollback','status']);p.add_argument('--dry-run',action='store_true');a=p.parse_args()
 try:
  adapter=Admin(a.plan,a.checkpoint,a.root,a.action)
  if a.dry_run or a.action=='status':
   adapter.preflight(a.action);result={'ok':True,'state':'source_admitted','runtime_quiescence_proved':False,'activation_proved':False,'effects':'private-checkpoint-metadata-only'}
  else:result=Coordinator(adapter).execute(a.action)
  print(json.dumps(result));return 0
 except BaseException as error:
  # No command strings, config bytes, database rows, source paths, stack traces or exception messages.
  print(json.dumps({'ok':False,'state':'hold','error_class':type(error).__name__}));return 1
if __name__=='__main__':raise SystemExit(main())
