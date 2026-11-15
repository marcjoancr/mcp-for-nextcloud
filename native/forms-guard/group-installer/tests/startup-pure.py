import sys,copy
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'host'))
from GroupInstallStartup import admit,Refused
p={'command_exact':True,'image_diff':[],'hooks':[],'version_equal':True,'literal_versions':True,'default_paths':True,'dangerous_env_absent':True,'apache_defaults':True,'no_preload':True,'stop_signal':'SIGWINCH','restart_policy':'no','sources':{'entry':'a'*64}}
assert admit(p,{'entry':'a'*64})
n=1
for k,v in [('command_exact',False),('image_diff',['/etc/apache2/x']),('hooks',['x']),('version_equal',False),('literal_versions',False),('default_paths',False),('dangerous_env_absent',False),('apache_defaults',False),('no_preload',False),('stop_signal','SIGKILL'),('restart_policy','always'),('sources',{'entry':'b'*64})]:
 q=copy.deepcopy(p);q[k]=v;denied=False
 try:admit(q,{'entry':'a'*64})
 except Refused:denied=True
 assert denied,k;n+=1
print({'checks':n,'native_bootstraps':0,'docker_calls':0})
