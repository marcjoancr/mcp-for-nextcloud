# SPDX-License-Identifier: AGPL-3.0-or-later
"""One-shot host state machine. The concrete sink supplies observed effects, never caller proof flags."""
class Refused(RuntimeError):pass
class Coordinator:
 def __init__(self,sink):self.sink=sink;self.state='new'
 def execute(self,action):
  if self.state!='new' or action not in ('install','validate','rollback'):raise Refused('Installer replay/action refused')
  self.state='running'
  try:
   for method,args in [('preflight',(action,)),('stop',()),('isolate',()),('checkpoint',()),('maintenance_on',())]:getattr(self.sink,method)(*args)
   phases=('retire','remove_entry','verify_inactive')if action=='rollback'else('schema','stage','epoch','verify')
   for phase in phases:getattr(self.sink,phase)()
   self.sink.maintenance_off()
   for _ in range(2):self.sink.turnover();self.sink.health()
   self.sink.stop_final();self.sink.final_invariants();self.sink.release();self.sink.start_released()
   self.state='released';return {'ok':True,'state':'released','action':action}
  except BaseException:
   self.state='hold'
   # Never reopen ingress or replay a phase after any uncertain effect, even an uncertain release.
   try:self.sink.hold()
   except BaseException:pass
   raise
