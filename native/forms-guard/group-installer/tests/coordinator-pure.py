import sys
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'host'))
from GroupInstallCoordinator import Coordinator
class Fake:
 def __init__(self,fail=None):self.calls=[];self.fail=fail
 def call(self,name,*args):
  self.calls.append(name)
  if name==self.fail or name+':'+str(self.calls.count(name))==self.fail:raise RuntimeError('injected')
  return {'ok':True}
 def __getattr__(self,name):return lambda *args:self.call(name,*args)
failure_count=0
for action,stages in [('install',['schema','stage','epoch','verify']),('validate',['schema','stage','epoch','verify']),('rollback',['retire','remove_entry','verify_inactive'])]:
 f=Fake();c=Coordinator(f);c.execute(action);assert c.state=='released'
 assert f.calls.index('checkpoint')<f.calls.index('maintenance_on')
 assert f.calls.index('final_invariants')<f.calls.index('release')<f.calls.index('start_released')
 assert f.calls.count('turnover')==2
 for step in ['preflight','stop','isolate','checkpoint','maintenance_on']+stages+['maintenance_off','turnover','health','stop_final','final_invariants','release','start_released','turnover:2','health:2']:
  failure_count+=1
  f=Fake(step);c=Coordinator(f);denied=False
  try:c.execute(action)
  except RuntimeError:denied=True
  assert denied and c.state=='hold',step
  if step not in ['release','start_released','turnover:2','health:2']:assert 'release'not in f.calls,step
  denied=False
  try:c.execute(action)
  except RuntimeError:denied=True
  assert denied
print({'actions':3,'failure_points':failure_count,'replay_refused':True,'docker_calls':0})
