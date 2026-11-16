# SPDX-License-Identifier: AGPL-3.0-or-later
"""Unexecuted installer-owned network transition. No fallback, forced disconnect or blind replay."""
import http.client,json,re,socket
from GroupInstallTopology import endpoint_binding,restoration,sessions,Refused
def single_network(networks,expected):
    if not isinstance(networks,dict) or len(networks)!=1 or not isinstance(next(iter(networks.values())),dict) or next(iter(networks.values())).get('NetworkID')!=expected:raise Refused('Owned container network map differs')

class LocalEngine(http.client.HTTPConnection):
    def __init__(self):super().__init__('localhost',timeout=30)
    def connect(self):
        self.sock=socket.socket(socket.AF_UNIX,socket.SOCK_STREAM);self.sock.settimeout(30);self.sock.connect('/var/run/docker.sock')
    def mutation(self,path,payload,expected):
        if not re.fullmatch(r'/v1\.55/networks/(?:create|[a-f0-9]{64}/(?:connect|disconnect))',path):raise Refused('Engine mutation path refused')
        data=json.dumps(payload,separators=(',',':')).encode();self.request('POST',path,body=data,headers={'Content-Type':'application/json'})
        r=self.getresponse();body=r.read(65537)
        if len(body)>65536 or r.status not in expected:raise Refused('Engine mutation uncertain')
        if r.status==204 or r.status==200 and not body.strip():return None
        try:return json.loads(body)
        except (ValueError,UnicodeError):raise Refused('Engine response malformed') from None

class NetworkInterval:
    """Two owned endpoints only. Any ambiguous mutation poisons the object: no automatic retry/release."""
    def __init__(self,engine,profile,snapshot,writer_probe,journal,before_stop=None):
        self.engine=engine;self.profile=profile;self.snapshot=snapshot;self.writer_probe=writer_probe;self.journal=journal;self.phase='new';self.private=None;self.original=None;self.before_stop=before_stop
    def _owned_identity(self,current,expected,require_stopped=True):
        if (require_stopped and current['app_running'] is not False) or current['app_id']!=self.profile['app_id'] or current['db_id']!=self.profile['db_id'] or current['network']['id']!=self.profile['network_id']:raise Refused('Network interval identity changed')
        maps=current.get('owned_networks')
        if not isinstance(maps,dict) or set(maps)!={self.profile['app_id'],self.profile['db_id']}:raise Refused('Owned container network map differs')
        for networks in maps.values():single_network(networks,expected)
    def _identity(self,expected):
        current=self.snapshot(self.private);self._owned_identity(current,expected);return current
    def assert_isolated(self,current,helper_id=None,require_stopped=True):
        if self.phase!='isolated':raise Refused('Network interval is not admitted')
        self._owned_identity(current,self.private,require_stopped);self._foreign(current)
        wanted=set(self.owned)|({helper_id}if helper_id is not None else set())
        if set(current['private_consumers'])!=wanted or current['private_network_id']!=self.private or current['private_internal'] is not True or current['helper_present'] is not (helper_id is not None):raise Refused('Private interval consumers changed')
    def _foreign(self,current):
        expected={k:v for k,v in self.original['consumers'].items() if k not in self.owned}
        if current['network']['consumers']!=expected:raise Refused('Foreign endpoint changed')
        if current['foreign_processes']!=self.foreign:raise Refused('Foreign process changed')
    def isolate(self):
        if self.phase!='new':raise Refused('Network interval replay refused')
        self.phase='hold';before=self._identity(self.profile['network_id']);sessions(self.writer_probe());self.original=before['network'];self.foreign=before['foreign_processes'];self.owned=(self.profile['app_id'],self.profile['db_id'])
        if self.before_stop is not None:
            from GroupInstallTopology import release_bindings
            self._owned_identity(self.before_stop,self.profile['network_id'],require_stopped=False)
            if self.before_stop['app_running']is not True or self.before_stop['foreign_processes']!=self.foreign:raise Refused('Running endpoint provenance changed')
            release_bindings(self.before_stop['network'],before['network'],self.owned,self.profile['dns_proofs'],stopped_ids=(self.profile['app_id'],))
            self.original=self.before_stop['network']
        if set(self.profile['dns_proofs'])!=set(self.owned):raise Refused('Configured dynamic endpoint provenance missing')
        for ident in self.owned:
            endpoint=endpoint_binding(self.original['consumers'][ident])
            if endpoint['Links'] not in (None,[]) or endpoint['IPAMConfig'] is not None:raise Refused('Only original null-IPAM profile qualified')
        self.journal('isolation_intent',{'original':self.original})
        created=self.engine.mutation('/v1.55/networks/create',{'Name':self.profile['private_name'],'Driver':'bridge','Internal':True,'Attachable':False,'IPAM':{'Driver':'default','Config':[{'Subnet':'10.239.114.0/24'}]},'Labels':{'forms.guard.group-installer':self.profile['operation']}},(201,))
        if not isinstance(created,dict) or not re.fullmatch('[a-f0-9]{64}',created.get('Id','')) or created.get('Warning') not in (None,''):raise Refused('Private network creation uncertain')
        self.private=created['Id'];self.journal('private_network_created',{'id':self.private})
        for ident in self.owned:
            self.journal('disconnect_intent',{'container':ident})
            self.engine.mutation('/v1.55/networks/'+self.profile['network_id']+'/disconnect',{'Container':ident,'Force':False},(200,204))
            self.engine.mutation('/v1.55/networks/'+self.private+'/connect',{'Container':ident,'EndpointConfig':{'Aliases':self.original['consumers'][ident]['Aliases']}},(200,204))
        after=self._identity(self.private);self._foreign(after)
        if set(after['private_consumers'])!=set(self.owned) or after['private_network_id']!=self.private or after['private_internal'] is not True:raise Refused('Private isolation unproved')
        sessions(self.writer_probe());self.phase='isolated';self.journal('isolated',{'network':self.private})
        return self.private
    def restore(self):
        from GroupInstallTopology import release_bindings
        if self.phase!='isolated':raise Refused('Network restoration state refused')
        self.phase='hold';current=self._identity(self.private);self._foreign(current)
        if set(current['private_consumers'])!=set(self.owned) or current['helper_present'] is not False:raise Refused('Installer helper still present')
        sessions(self.writer_probe())
        # Caller has already proved epoch/schema and full interval invariants; this object proves endpoint effects only.
        for ident in self.owned:
            self.journal('release_intent',{'container':ident,'original_endpoint':self.original['consumers'][ident]})
            self.engine.mutation('/v1.55/networks/'+self.private+'/disconnect',{'Container':ident,'Force':False},(200,204))
            prior=self.original['consumers'][ident]
            config={key:prior.get(key) for key in ('IPAMConfig','Links','MacAddress','Aliases','DriverOpts','GwPriority')}
            self.journal('restore_request',{'container':ident,'endpoint_config':config})
            self.engine.mutation('/v1.55/networks/'+self.profile['network_id']+'/connect',{'Container':ident,'EndpointConfig':config},(200,204))
        after=self._identity(self.profile['network_id']);release_bindings(self.original,after['network'],self.owned,self.profile['dns_proofs'],stopped_ids=(self.profile['app_id'],))
        if after['foreign_processes']!=self.foreign or after['private_consumers']!={}:raise Refused('Release cleanup uncertain')
        self.phase='restored';self.journal('network_released',{'configured_bindings_exact':True,'actual_endpoints':after['network']['consumers'],'observed_mac_explicitly_preserved':True})
