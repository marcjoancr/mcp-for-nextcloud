# SPDX-License-Identifier: AGPL-3.0-or-later
"""Finite PG-only adapter. Never invoked on import; no automatic recovery or DB restore."""
from pathlib import Path
import hashlib,io,json,os,re,secrets,tarfile,time
from GroupInstallHostIO import child,container_metadata,postgres_writers,database_checkpoint,private_directory,private_write,DATABASE_CHECKPOINT_LIMIT
from GroupInstallTopology import sessions,Refused,release_bindings
from GroupInstallNetwork import LocalEngine,NetworkInterval,single_network
from GroupInstallArchive import inventory,changed
from GroupInstallDbState import parse,compare,application_versions
from xml.etree import ElementTree
from GroupInstallStartup import admit
from GroupInstallEnv import template as environment_template
APP='258956e4fdfe446751b30e589d700ab473265b0d167ee68b05a197f3677f559a'
DB='e7223bd6cbd86e0acf2bfdc7daf4339386c3b9011a04b2e880d96eb63a93aee3'
NETWORK='3396c5d22ba14a3acc708b6bd956a63283aa6b536636e12fdf20e2670a9650e5'
CONSUMERS=['258956e4fdfe446751b30e589d700ab473265b0d167ee68b05a197f3677f559a', '9359b7e243e845c549ba236a0061c153cd8670902fd68a3b2136e2715aaa22cf', 'c74516b49ddcf511dc4c30377cf4b63041570d4ec6dbb9cd93e34cbe70751965', 'e7223bd6cbd86e0acf2bfdc7daf4339386c3b9011a04b2e880d96eb63a93aee3', 'f151aff6fffcf46237da6b244b8508cba2c48a80063aaba66a472d34a58abba7']
ROOT='/var/www/html'
SCOPES={'forms':'custom_apps/forms','mcp':'custom_apps/mcp','circles':'apps/circles','config':'config'}
MIGRATION='050402Date20261115020000'

def digest(data):return hashlib.sha256(data).hexdigest()
def decode(raw):
 try:return json.loads(raw)
 except (ValueError,UnicodeError):raise Refused('Bounded metadata malformed')from None

def file_bytes(archive,name):
 with tarfile.open(archive,'r:*')as tar:
  matches=[m for m in tar if m.name==name]
  if len(matches)!=1 or not matches[0].isfile() or matches[0].size>1048576:raise Refused('Private file preimage unavailable')
  return tar.extractfile(matches[0]).read()

class Admin:
 def __init__(self,plan_path,checkpoint,root,action):
  self.package=Path(__file__).resolve().parents[1];self.plan=decode(Path(plan_path).read_bytes());self.action=action;self.operation=secrets.token_hex(16);self.seq=0;self.helper_id=None;self.final_proved=False;self.released=False
  expected_keys={'format','source_head','app_id','db_id','network_id','prepared_marker','checkpoint_parent','bundle','bundle_manifest','installer_manifest_sha256','native_pins','startup_pins','literal_version_sha256','consumer_ids','containers','app_inventories','config_pins'}
  if not isinstance(self.plan,dict) or set(self.plan)!=expected_keys:raise Refused('Installer plan schema differs')
  if root!=ROOT or self.plan.get('format')!=1 or self.plan.get('source_head')!='08412737206b597c03b24569ec44ab82653987e7' or self.plan.get('app_id')!=APP or self.plan.get('db_id')!=DB or self.plan.get('network_id')!=NETWORK:raise Refused('Selected disposable profile differs')
  if not isinstance(self.plan['consumer_ids'],list) or sorted(self.plan['consumer_ids'])!=CONSUMERS or set(self.plan['containers'])!=set(CONSUMERS):raise Refused('Disposable consumer identities differ')
  if self.plan.get('prepared_marker')!='c724ab77a46fa70e1de92570f90020aaf8ef7a4c8d048555b295c319be332a68':raise Refused('Required prepared app profile differs')
  parent=Path(checkpoint).parent
  if not Path(checkpoint).is_absolute() or parent!=Path(self.plan['checkpoint_parent']) or parent.resolve(strict=True)!=parent or parent.stat().st_mode&0o777!=0o700:raise Refused('Private checkpoint parent differs')
  self.private=private_directory(checkpoint);self.bundle=Path(self.plan['bundle']).resolve(strict=True)
  if not re.fullmatch(r'/[A-Za-z0-9_./-]+',str(self.package)) or not re.fullmatch(r'/[A-Za-z0-9_./-]+',str(self.bundle)):raise Refused('Installer mount path unqualified')
  if self.bundle.is_symlink() or self.bundle!=Path(self.plan['bundle']):raise Refused('Bundle path differs')
  manifest=(self.bundle/'GroupSources.json').read_bytes()
  if digest(manifest)!=self.plan['bundle_manifest']:raise Refused('Bundle manifest differs')
  self.bundle_pins=decode(manifest)
  for name,sha in self.bundle_pins['owned'].items():
   if Path(name).name!=name or (self.bundle/name).is_symlink() or digest((self.bundle/name).read_bytes())!=sha:raise Refused('Bundle source differs')
  self._verify_package()
  deps=decode((self.package/'runtime-dependencies.json').read_bytes())
  if self.plan['bundle_manifest']!=deps['bundle_manifest'] or self.plan['native_pins']!=deps['native_pins']:raise Refused('Closed native dependency profile differs')
  self._prepare_mirrors()
  self.journal('created',{'operation':self.operation,'action':action,'plan_sha256':digest(Path(plan_path).read_bytes())})
 def _prepare_mirrors(self):
  # Host source/checkpoints remain private. Only public reviewed code is copied into read-only service-readable mounts.
  self.code_mount=self.private/'installer-code';self.bundle_mount=self.private/'bundle-code';self.mirror_pins={}
  for destination,source,names in [(self.code_mount,self.package/'standalone',[f.name for f in(self.package/'standalone').glob('*.php')]),(self.bundle_mount,self.bundle,list(self.bundle_pins['owned'])+['GroupSources.json'])]:
   destination.mkdir(mode=0o700);os.chmod(destination,0o755)
   for name in names:
    data=(source/name).read_bytes();private_write(destination/name,data);os.chmod(destination/name,0o644);self.mirror_pins[str((destination/name).relative_to(self.private))]=digest(data)
  self._mirrors_exact()
 def _mirrors_exact(self):
  actual=set()
  for directory in(self.code_mount,self.bundle_mount):
   if directory.is_symlink()or directory.stat().st_mode&0o777!=0o755:raise Refused('Code mirror directory changed')
   for file in directory.iterdir():
    name=str(file.relative_to(self.private));actual.add(name)
    if file.is_symlink()or not file.is_file()or file.stat().st_mode&0o777!=0o644 or digest(file.read_bytes())!=self.mirror_pins.get(name):raise Refused('Code mirror changed')
  if actual!=set(self.mirror_pins):raise Refused('Code mirror inventory changed')
 def _verify_package(self):
  manifest=(self.package/'installer-source-pins.json').read_bytes()
  if digest(manifest)!=self.plan['installer_manifest_sha256']:raise Refused('Installer package manifest differs')
  pins=decode(manifest)
  actual={str(f.relative_to(self.package))for folder in ('host','standalone')for f in (self.package/folder).glob('*')if f.suffix in('.py','.php')}|{'install-groups.py','runtime-dependencies.json'}
  if set(pins)!=actual:raise Refused('Installer package inventory differs')
  for name,sha in pins.items():
   f=self.package/name
   if '..'in Path(name).parts or f.is_symlink() or not f.is_file() or digest(f.read_bytes())!=sha:raise Refused('Installer source differs')
 def journal(self,stage,value):
  self.seq+=1;name=f'{self.seq:03d}-{stage}.json';data=(json.dumps(value,sort_keys=True)+'\n').encode();sha=private_write(self.private/name,data)
  return {'path':name,'sha256':sha}
 def _intended_epoch_hashes(self):
  if not isinstance(self.operation,str) or not re.fullmatch('[a-f0-9]{32}',self.operation) or self.action not in('install','validate','rollback') or not re.fullmatch('[a-f0-9]{64}',self.plan['bundle_manifest']) or not isinstance(self.next,str) or not re.fullmatch('[a-f0-9]{64}',self.next) or self.prior is not None and (not isinstance(self.prior,str)or not re.fullmatch('[a-f0-9]{64}',self.prior)) or self.prior==self.next:raise Refused('Intended epoch binding invalid')
  return {'format':1,'operation':self.operation,'action':self.action,'bundle_manifest_sha256':self.plan['bundle_manifest'],'prior_epoch_sha256':digest(self.prior.encode())if self.prior is not None else None,'next_epoch_sha256':digest(self.next.encode())}
 def _assert_epoch_intent(self):
  receipt=getattr(self,'epoch_intent_receipt',None)
  if not isinstance(receipt,dict) or set(receipt)!={'path','sha256'} or not isinstance(receipt['path'],str) or not re.fullmatch(r'[0-9]{3}-epoch_intent\.json',receipt['path']) or not isinstance(receipt['sha256'],str) or not re.fullmatch('[a-f0-9]{64}',receipt['sha256']):raise Refused('Durable epoch intent unavailable')
  path=self.private/receipt['path']
  if path.is_symlink()or not path.is_file()or path.stat().st_mode&0o777!=0o600 or path.stat().st_size>4096:raise Refused('Durable epoch intent unavailable')
  raw=path.read_bytes()
  if digest(raw)!=receipt['sha256']or decode(raw)!=self._intended_epoch_hashes():raise Refused('Durable epoch intent unavailable')
  return receipt
 def _cp(self,path,destination):
  child(['docker','cp',APP+':'+path,'-'],limit=1073741824,timeout=120,output_path=destination)
 def _sources(self):
  # All expected regular app files plus exact native dependencies; unknown app files are checked by archives.
  for rel,sha in self.plan['native_pins'].items():
   if not re.fullmatch(r'[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*',rel) or '..'in rel.split('/'):raise Refused('Native source path refused')
   raw=child(['docker','cp',APP+':'+ROOT+'/'+rel,'-'],limit=8388608)
   with tarfile.open(fileobj=io.BytesIO(raw))as tar:
    ms=tar.getmembers()
    if len(ms)!=1 or not ms[0].isfile() or ms[0].name!=Path(rel).name or digest(tar.extractfile(ms[0]).read())!=sha:raise Refused('Native source changed')
 def archives(self,label):
  out={}
  for name,rel in SCOPES.items():
   dest=self.private/f'{label}-{name}.tar';self._cp(ROOT+'/'+rel,dest);out[name]=inventory(dest,name)
   if label not in('before','final'):dest.unlink()
  self.journal('archive_inventory',{'label':label,'inventories':out})
  return out
 def _startup(self):
  if child(['docker','inspect','--format',environment_template(),APP],limit=32).strip()!=b'true':raise Refused('Startup environment unqualified before PHP')
  command=child(['docker','inspect','--format','{{and (eq .Path "/entrypoint.sh") (eq (len .Args) 1) (eq (index .Args 0) "apache2-foreground")}}',APP],limit=128).strip()==b'true'
  diff=child(['docker','diff',APP],limit=1048576).decode().splitlines();critical=('/entrypoint.sh','/usr/local/bin','/usr/local/lib/php','/usr/sbin/apache2','/usr/lib/apache2','/etc/apache2','/usr/local/etc/php','/docker-entrypoint-hooks.d')
  filtered=[line for line in diff if any(line[2:]==p or line[2:].startswith(p+'/') for p in critical)]
  # PHP probe executes only our closed literal parser, hashes and environment predicates; no native bootstrap.
  literal=(self.package/'standalone/GroupInstallLiteralConfig.php').read_text();probe=(self.package/'standalone/GroupInstallStartupProbe.php').read_text()
  code=literal+'\n'+probe.removeprefix('<?php')
  actual=decode(child(['docker','exec','-i',APP,'/usr/local/bin/php'],limit=16384,input_data=code.encode()))
  self.maintenance_prior=actual.pop('maintenance');self.dns=actual.pop('dbhost_dns')
  if self.maintenance_prior is not False or self.dns is not True:raise Refused('Initial native maintenance/DNS profile unavailable')
  meta=container_metadata(APP)
  actual.update(command_exact=command,image_diff=filtered,version_equal=actual['sources']['/usr/src/nextcloud/version.php']==actual['sources']['/var/www/html/version.php'],literal_versions=actual['sources']['/var/www/html/version.php']==self.plan['literal_version_sha256'],stop_signal=meta['stop_signal'],restart_policy=meta['restart_policy'])
  admit(actual,self.plan['startup_pins'])
 def snapshot(self,private_id):
  identities=list(self.plan['consumer_ids'])+([self.helper_id]if self.helper_id is not None else[])
  metas={ident:container_metadata(ident)for ident in identities}
  def collect(network):
   configured={ident:ep for ident,m in metas.items()for ep in m['networks'].values()if ep['NetworkID']==network}
   active=decode(child(['docker','network','inspect','--format','{{json .Containers}}',network],limit=65536))
   expected={ident for ident,m in metas.items()if m['running']and ident in configured}
   if set(active)!=expected:raise Refused('Unknown or missing active network consumer')
   return configured
  net=decode(child(['docker','network','inspect','--format','{{json .Internal}}',NETWORK],limit=128))
  endpoints=collect(NETWORK)
  private_consumers={};internal=False
  if private_id:
   internal=decode(child(['docker','network','inspect','--format','{{json .Internal}}',private_id],limit=128));private_consumers=collect(private_id)
  return {'app_running':metas[APP]['running'],'app_id':APP,'db_id':DB,'owned_networks':{i:metas[i]['networks']for i in(APP,DB)},'network':{'id':NETWORK,'internal':net,'consumers':endpoints},'foreign_processes':{k:metas[k]for k in self.plan['consumer_ids']if k not in(APP,DB)},'private_consumers':private_consumers,'private_network_id':private_id,'private_internal':internal,'helper_present':self.helper_id is not None}
 def preflight(self,action):
  if action!=self.action:raise Refused('Action changed')
  for ident in self.plan['consumer_ids']:
   m=container_metadata(ident)
   if ident in(APP,DB):single_network(m['networks'],NETWORK)
   if m['label']!='20261113' or any(m['ports'].values()) or m['running']is not True or m['extra_hosts']not in(None,[]):raise Refused('Disposable consumer profile changed')
   if {k:m[k]for k in ('id','name','image','mounts')}!=self.plan['containers'][ident]:raise Refused('Container source/volume identity changed')
  self._startup();self._sources();self.initial=self.archives('preflight')
  for app in ('forms','mcp','circles'):
   if self.initial[app]!=self.plan['app_inventories'][app]:raise Refused('Complete installed app inventory differs')
  if self.initial['forms'].get('forms/mcp-guard-installed.json',{}).get('sha256')!=self.plan['prepared_marker']:raise Refused('Required native app980 is not installed')
  actual_config={k.removeprefix('config/'):v['sha256']for k,v in self.initial['config'].items()if v['type']=='file'}
  if actual_config!=self.plan['config_pins']:raise Refused('Complete config profile differs')
  required=sum(v['size']for files in self.initial.values()for v in files.values())*2+3*DATABASE_CHECKPOINT_LIMIT+104857600
  stat=os.statvfs(self.private)
  if stat.f_bavail*stat.f_frsize<required:raise Refused('Private checkpoint capacity insufficient')
  self.journal('preflight',{'source_exact':True,'startup_admitted':True,'native_bootstrap':False})
 def stop(self):
  if hasattr(self,'interval'):self.interval.assert_isolated(self.snapshot(self.net),require_stopped=False)
  else:
   for ident in(APP,DB):single_network(container_metadata(ident)['networks'],NETWORK)
   self.running_network=self.snapshot(None)
   if self.running_network['app_running']is not True:raise Refused('Original running endpoint proof unavailable')
   self.journal('running_network_before_stop',self.running_network)
  self.journal('stop_intent',{'app':APP});child(['docker','stop','--time','30',APP],limit=1024,timeout=40)
  m=container_metadata(APP)
  if m['running']is not False or m['exit_code']!=0:raise Refused('App graceful shutdown unproved')
  sessions(postgres_writers(DB))
 def isolate(self):
  profile={'app_id':APP,'db_id':DB,'network_id':NETWORK,'private_name':'forms-group-install-'+self.operation,'operation':self.operation,'dns_proofs':[APP,DB]}
  self.interval=NetworkInterval(LocalEngine(),profile,self.snapshot,lambda:postgres_writers(DB),self.journal,before_stop=self.running_network);self.net=self.interval.isolate()
 def checkpoint(self):
  self.before=self.archives('before')
  if self.before!=self.initial:raise Refused('Source changed while stopping app')
  self._sources();sessions(postgres_writers(DB));database_checkpoint(DB,self.private/'database.dump',True);database_checkpoint(DB,self.private/'before.sql',False);sessions(postgres_writers(DB))
  self.db_before=parse((self.private/'before.sql').read_bytes());tables=self.db_before[0]
  versions={}
  for app in('forms','mcp','circles'):
   xml=file_bytes(self.private/('before-'+app+'.tar'),app+'/appinfo/info.xml')
   if b'<!DOCTYPE'in xml or b'<!ENTITY'in xml:raise Refused('App metadata grammar unqualified')
   version=ElementTree.fromstring(xml).findtext('version')
   if not isinstance(version,str)or not re.fullmatch(r'[0-9]+(?:\.[0-9]+){1,4}',version):raise Refused('App version metadata unqualified')
   versions[app]=version
  application_versions(self.db_before,versions)
  key=b'COPY public.oc_forms_mcp_group_epoch (id, epoch) FROM stdin;';rows=tables.get(key,[])
  if len(rows)>1 or rows and not re.fullmatch(rb'1\t[a-f0-9]{64}',rows[0]):raise Refused('Prior epoch malformed')
  self.prior=rows[0][2:].decode()if rows else None;self.next=secrets.token_hex(32)
  migrations=tables.get(b'COPY public.oc_migrations (app, version) FROM stdin;')
  if migrations is None:raise Refused('Core migration table absent')
  self.versions=[row[6:].decode()for row in migrations if row.startswith(b'forms\t')]
  self.migrated=MIGRATION not in self.versions
  self.expected_config=dict(self.before['config']);self.main_original=file_bytes(self.private/'before-config.tar','config/config.php')
  self.preimages={name:self.expected_config.get('config/'+name,{}).get('sha256')for name in list(self.bundle_pins['owned'])+['GroupSources.json','mcp_forms_group_install.json']}
  for name,sha in self.bundle_pins['owned'].items():
   if self.preimages[name]not in(None,sha):raise Refused('Foreign owned-name replacement')
  if self.preimages['GroupSources.json']not in(None,self.plan['bundle_manifest']):raise Refused('Foreign bundle manifest')
  meta=self.preimages['mcp_forms_group_install.json']
  if meta is not None:
   value=decode(file_bytes(self.private/'before-config.tar','config/mcp_forms_group_install.json'))
   if set(value)!={'format','active','epoch','manifest_sha256'}or value['format']!=1 or value['active']is not True or value['manifest_sha256']!=self.plan['bundle_manifest']or not re.fullmatch('[a-f0-9]{64}',value['epoch']):raise Refused('Prior activation source incompatible')
   if self.action in('validate','rollback')and value['epoch']!=self.prior:raise Refused('Prior active epoch incompatible')
  if self.action in('validate','rollback')and (meta is None or any(self.preimages[name]!=sha for name,sha in self.bundle_pins['owned'].items())):raise Refused('Compatible owned entry unavailable')
  self.journal('checkpoint',{'private_complete':True,'schema_pending':self.migrated,'prior_epoch_present':self.prior is not None})
  self.epoch_intent_receipt=self.journal('epoch_intent',self._intended_epoch_hashes())
  self.data_before=self._phase('snapshot')
 def _profile(self):
  source=dict(self.plan['native_pins'])
  for app in ('forms','mcp','circles'):
   for name,meta in self.before[app].items():
    if meta['type']=='file':source[SCOPES[app]+name[len(app):]]=meta['sha256']
  return dict(format=1,root=ROOT,action=self.action,operation=self.operation,source_pins=source,config_pins={k.removeprefix('config/'):v['sha256']for k,v in self.expected_config.items()if v['type']=='file'},migration_versions=self.versions,prior_epoch=self.prior,next_epoch=self.next,bundle_manifest=self.plan['bundle_manifest'],bundle_preimages=self.preimages,maintenance_prior=self.maintenance_prior)
 def _phase(self,phase):
  self._verify_package();self._mirrors_exact();intent=self._assert_epoch_intent()
  if self.helper_id is not None:raise Refused('Previous helper uncertain')
  m=container_metadata(APP)
  if m['running']is not False:raise Refused('App must remain stopped during helper')
  sessions(postgres_writers(DB));current=self.snapshot(self.net);self.interval.assert_isolated(current)
  profile=self._profile()
  mount=self.plan['containers'][APP]['mounts']
  if len(mount)!=1 or mount[0]['Type']!='volume' or mount[0]['Destination']!=ROOT or mount[0]['RW']is not True:raise Refused('Native volume binding changed')
  name='forms-group-helper-'+self.operation+'-'+str(self.seq)
  cmd=['docker','create','--pull','never','--name',name,'--interactive','--network',self.net,'--entrypoint','/usr/local/bin/php','--no-healthcheck','--restart','no','--label','forms.guard.group-installer='+self.operation,'--mount','type=volume,src='+mount[0]['Name']+',dst='+ROOT,'--mount','type=bind,src='+str(self.code_mount)+',dst=/installer,readonly','--mount','type=bind,src='+str(self.bundle_mount)+',dst=/bundle,readonly','--tmpfs','/profiles:rw,noexec,nosuid,size=2m,mode=0700,uid=33,gid=33',self.plan['containers'][APP]['image'],'/installer/GroupInstallController.php']
  self.journal('helper_create_intent',{'phase':phase,'name':name});ident=child(cmd,limit=1024).decode().strip()
  if not re.fullmatch('[a-f0-9]{64}',ident):raise Refused('Helper creation uncertain')
  self.helper_id=ident;hm=container_metadata(ident)
  if hm['image']!=m['image']or any(hm['ports'].values())or len(hm['networks'])!=1 or next(iter(hm['networks'].values()))['NetworkID']!=self.net:raise Refused('Finite helper binding differs')
  mounts={x['Destination']:x for x in hm['mounts']}
  if set(mounts)not in({ROOT,'/installer','/bundle'},{ROOT,'/installer','/bundle','/profiles'}) or mounts[ROOT].get('Name')!=mount[0]['Name'] or mounts[ROOT].get('RW')is not True or mounts['/installer'].get('Source')!=str(self.code_mount) or mounts['/installer'].get('RW')is not False or mounts['/bundle'].get('Source')!=str(self.bundle_mount) or mounts['/bundle'].get('RW')is not False or ('/profiles'in mounts and mounts['/profiles'].get('Type')!='tmpfs') or hm.get('tmpfs')!={'/profiles':'rw,noexec,nosuid,size=2m,mode=0700,uid=33,gid=33'}:raise Refused('Finite helper mounts differ')
  current=self.snapshot(self.net)
  self.interval.assert_isolated(current,helper_id=ident)
  request=(json.dumps({'sequence':1,'phase':phase,'profile':profile},separators=(',',':'))+'\n').encode()
  binding={'operation':self.operation,'action':self.action,'bundle_manifest_sha256':self.plan['bundle_manifest'],'phase':phase,'helper_id':ident,'epoch_intent_sha256':intent['sha256'],'request_sha256':digest(request)}
  self.journal('helper_started_intent',binding)
  result=decode(child(['docker','start','--attach','--interactive',ident],limit=16384,timeout=130,input_data=request))
  expected={'ok':True,'sequence':1,'stage':phase}
  if phase=='snapshot':
   if set(result)!=set(expected)|{'data_digest','data_count','data_bytes'} or not re.fullmatch('[a-f0-9]{64}',str(result.get('data_digest',''))) or type(result.get('data_count'))is not int or not 1<=result['data_count']<=32768 or type(result.get('data_bytes'))is not int or not 0<=result['data_bytes']<=2147483648:raise Refused('Finite data proof malformed')
   expected.update({k:result[k]for k in('data_digest','data_count','data_bytes')})
  if result!=expected:raise Refused('Finite helper did not acknowledge phase')
  hm=container_metadata(ident)
  if hm['running']is not False or hm['exit_code']!=0:raise Refused('Finite helper exit unproved')
  sessions(postgres_writers(DB))
  result_receipt=self.journal('helper_result',{**binding,'result':result,'container_exited':True,'foreign_client_sessions':0,'prepared_transactions':0})
  removed=child(['docker','rm',ident],limit=1024).decode().strip()
  if removed!=ident:raise Refused('Helper removal acknowledgement differs')
  self.helper_id=None
  self.journal('helper_removed',{'helper_id':ident,'epoch_intent_sha256':intent['sha256'],'helper_result_sha256':result_receipt['sha256']})
  after=self.archives('phase-'+str(self.seq))
  for app in ('forms','mcp','circles'):changed(self.before[app],after[app],{})
  expected=dict(self.expected_config)
  if phase in('maintenance','maintenance_on'):
   # Child independently checked exact pinned native merged-cache serializer; only main file may change.
   expected['config/config.php']=after['config']['config/config.php']
  if phase=='stage':
   names=list(self.bundle_pins['owned'])+['GroupSources.json']if self.action=='install'else[]
   for name in names:
    data=(self.bundle/name).read_bytes();expected['config/'+name]={'type':'file','sha256':digest(data),'size':len(data),'mode':0o600,'uid':33,'gid':33}
   data=(json.dumps({'format':1,'active':True,'epoch':self.next,'manifest_sha256':self.plan['bundle_manifest']},indent=4)+'\n').encode();expected['config/mcp_forms_group_install.json']={'type':'file','sha256':digest(data),'size':len(data),'mode':0o600,'uid':33,'gid':33}
  if phase=='remove_entry':expected.pop('config/mcp-forms-groups.config.php')
  changed(expected,after['config'],{});self.expected_config=expected
  self.journal('phase_complete',{'phase':phase,'helper_exited':True,'source_exact':True,'epoch_intent_sha256':intent['sha256'],'helper_result_sha256':result_receipt['sha256']})
  return {k:result[k]for k in('data_digest','data_count','data_bytes')}if phase=='snapshot'else None
 def maintenance_on(self):self._phase('maintenance_on')
 def schema(self):self._phase('schema')
 def stage(self):self._phase('stage')
 def epoch(self):self._phase('epoch')
 def verify(self):self._phase('verify')
 def retire(self):self._phase('retire')
 def remove_entry(self):self._phase('remove_entry')
 def verify_inactive(self):self._phase('verify_inactive')
 def maintenance_off(self):
  self._phase('maintenance')
  # Restore only original main-config bytes/mode after exact expected native setter effect, with app/helper stopped.
  sessions(postgres_writers(DB));actual=self.archives('before-main-restore')
  changed(self.expected_config,actual['config'],{})
  old=self.before['config']['config/config.php'];stream=io.BytesIO()
  with tarfile.open(fileobj=stream,mode='w')as tar:
   member=tarfile.TarInfo('config.php');member.mode=old['mode'];member.uid=old['uid'];member.gid=old['gid'];member.size=len(self.main_original);tar.addfile(member,io.BytesIO(self.main_original))
  self.interval.assert_isolated(self.snapshot(self.net))
  child(['docker','cp','--archive','-',APP+':'+ROOT+'/config'],limit=1024,input_data=stream.getvalue());self.expected_config['config/config.php']=old
  actual=self.archives('after-main-restore');changed(self.expected_config,actual['config'],{})
 def turnover(self):
  self.interval.assert_isolated(self.snapshot(self.net),require_stopped=False)
  if container_metadata(APP)['running']:self.stop()
  self.interval.assert_isolated(self.snapshot(self.net));sessions(postgres_writers(DB));self.journal('start_isolated_intent',{})
  child(['docker','start',APP],limit=1024)
  if container_metadata(APP)['running']is not True:raise Refused('Isolated app start failed')
  self.interval.assert_isolated(self.snapshot(self.net),require_stopped=False);self._startup()
 def health(self):
  self.interval.assert_isolated(self.snapshot(self.net),require_stopped=False)
  until=time.monotonic()+15
  while True:
   code=child(['docker','exec',APP,'curl','--silent','--show-error','--max-time','5','--output','/dev/null','--write-out','%{http_code}','http://127.0.0.1/status.php'],limit=32,timeout=8)
   if code==b'200':break
   if time.monotonic()>until:raise Refused('Loopback health unavailable')
   time.sleep(.2)
  self.interval.assert_isolated(self.snapshot(self.net),require_stopped=False)
  self.journal('isolated_health',{'status_200':True})
 def stop_final(self):
  self.interval.assert_isolated(self.snapshot(self.net),require_stopped=False);self.stop();self.interval.assert_isolated(self.snapshot(self.net))
 def final_invariants(self):
  if self._phase('snapshot')!=self.data_before:raise Refused('Native bootstrap changed local data')
  sessions(postgres_writers(DB));after=self.archives('final');self._sources()
  for app in ('forms','mcp','circles'):changed(self.before[app],after[app],{})
  changed(self.expected_config,after['config'],{});database_checkpoint(DB,self.private/'after.sql',False);sessions(postgres_writers(DB))
  result=compare(self.db_before,parse((self.private/'after.sql').read_bytes()),self.next,self.migrated if self.action!='rollback'else False)
  self.interval.assert_isolated(self.snapshot(self.net));self.journal('final_invariants',result);self.final_proved=True
 def release(self):
  if not self.final_proved:raise Refused('Full interval not proved')
  self.interval.restore();self.journal('private_network_remove_intent',{'id':self.net})
  removed=child(['docker','network','rm',self.net],limit=1024).decode().strip()
  if removed!=self.net:raise Refused('Private network removal uncertain')
  self.released=True
 def start_released(self):
  if not self.released or not self.final_proved:raise Refused('Release proof absent')
  for ident in(APP,DB):single_network(container_metadata(ident)['networks'],NETWORK)
  child(['docker','start',APP],limit=1024)
  if container_metadata(APP)['running']is not True:raise Refused('Released app start failed')
  restored=self.snapshot(None)
  release_bindings(self.interval.original,restored['network'],(APP,DB),(APP,DB))
  if restored['foreign_processes']!=self.interval.foreign:raise Refused('Foreign process changed on final start')
  self.journal('released',{'configured_endpoints_restored':True,'all_frozen_interval_invariants':True,'active':self.action!='rollback'})
 def hold(self):
  # Preserve exact topology/process/checkpoint uncertainty. No compensating network release or DB rewind.
  self.journal('hold',{'helper_known':self.helper_id is not None,'release_started':self.released,'manual_independent_recovery_required':True})
