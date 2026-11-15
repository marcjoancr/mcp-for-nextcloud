# SPDX-License-Identifier: AGPL-3.0-or-later
"""Closed metadata policy for a disposable PostgreSQL installer interval. No Docker effects here."""
import ipaddress
class Refused(RuntimeError):
    pass

def endpoint_binding(endpoint):
    keys=('NetworkID','IPAddress','GlobalIPv6Address','IPAMConfig','Aliases','DriverOpts','GwPriority','MacAddress','Links','Gateway','IPPrefixLen','IPv6Gateway','GlobalIPv6PrefixLen','DNSNames')
    if not isinstance(endpoint,dict) or any(k not in endpoint for k in ('NetworkID','IPAddress','Aliases')):
        raise Refused('Endpoint profile unavailable')
    result={k:endpoint.get(k) for k in keys}
    if not result['NetworkID'] or result['Aliases'] is not None and (not isinstance(result['Aliases'],list) or len(result['Aliases'])>16):
        raise Refused('Endpoint profile unavailable')
    if result['Aliases'] is not None and (len(set(result['Aliases']))!=len(result['Aliases']) or not all(isinstance(x,str) and x and len(x)<=255 for x in result['Aliases'])):
        raise Refused('Endpoint profile unavailable')
    if result['IPAddress']:
        if not ipaddress.ip_address(result['IPAddress']).is_private:raise Refused('Endpoint address unqualified')
    if result['GlobalIPv6Address']:raise Refused('IPv6 restoration unqualified')
    if result['DriverOpts'] not in (None,{}):raise Refused('Endpoint driver options unqualified')
    if result['GwPriority'] not in (None,0):raise Refused('Endpoint gateway priority unqualified')
    if result['IPAMConfig'] not in (None,{}, {'IPv4Address':result['IPAddress']}):raise Refused('Endpoint IPAM profile unqualified')
    return result

def admission(app,db,network,expected):
    for role,value in [('app',app),('db',db)]:
        if set(value)!=set(('id','image','running','label','ports','mounts','networks')):raise Refused('Container metadata profile unavailable')
        if value['id']!=expected[role]['id'] or value['image']!=expected[role]['image'] or value['label']!=expected['label'] or any(value['ports'].values()):raise Refused('Container profile changed')
        if len(value['networks'])!=1 or expected['network_id'] not in value['networks']:raise Refused('Container network profile changed')
    if app['running'] is not False or db['running'] is not True:raise Refused('Stopped app boundary unavailable')
    if app['mounts']!=expected['app']['mounts'] or len(app['mounts'])!=1:raise Refused('App volume profile changed')
    mount=app['mounts'][0]
    if mount.get('Type')!='volume' or mount.get('Destination')!='/var/www/html' or mount.get('RW') is not True:raise Refused('App volume profile changed')
    if network['id']!=expected['network_id'] or network['internal'] is not True or network['consumers']!=expected['consumers']:raise Refused('Network consumer profile changed')
    return endpoint_binding(db['networks'][expected['network_id']])

def restoration(before,after,db_id):
    if before['id']!=after['id'] or before['internal']!=after['internal'] or set(before['consumers'])!=set(after['consumers']):raise Refused('Network restoration uncertain')
    for ident,prior in before['consumers'].items():
        now=after['consumers'][ident]
        if ident==db_id:
            # Docker allocates a fresh EndpointID on reconnect; it is never claimed restored.
            if endpoint_binding(prior)!=endpoint_binding(now):raise Refused('Database endpoint restoration uncertain')
        elif prior!=now:raise Refused('Foreign endpoint changed')

def sessions(value):
    if value!={'superuser':True,'foreign_client_sessions':0,'prepared_transactions':0}:raise Refused('Database writer quiescence unavailable')


def dns_dependency(dbhost,db_name,extra_hosts):
    if not isinstance(dbhost,str) or not isinstance(db_name,str) or extra_hosts not in (None,[]):raise Refused('DNS consumer profile unqualified')
    name=db_name.lstrip('/')
    if dbhost not in (name,name+':5432'):raise Refused('Database host binding unqualified')
    try:ipaddress.ip_address(name)
    except ValueError:pass
    else:raise Refused('Literal database address unqualified')
    return {'host':name,'port':5432,'extra_hosts_absent':True}

def configured_binding(endpoint,dns_proven=False):
    out=endpoint_binding(endpoint)
    if dns_proven:
        if out['IPAMConfig'] not in (None,{}):raise Refused('Static address profile requires exact restoration')
        # Only generated addressing is ignored. Requested MAC is retained explicitly during reconnect.
        out.pop('IPAddress');out.pop('IPPrefixLen')
    return out

def release_bindings(before,after,owned_ids,dns_proofs):
    if before['id']!=after['id'] or before['internal']!=after['internal'] or set(before['consumers'])!=set(after['consumers']):raise Refused('Network restoration uncertain')
    for ident,prior in before['consumers'].items():
        now=after['consumers'][ident]
        if ident not in owned_ids:
            if prior!=now:raise Refused('Foreign endpoint changed')
        elif configured_binding(prior,ident in dns_proofs)!=configured_binding(now,ident in dns_proofs):raise Refused('Configured endpoint restoration uncertain')
