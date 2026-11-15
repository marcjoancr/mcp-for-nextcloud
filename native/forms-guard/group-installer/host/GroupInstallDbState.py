# SPDX-License-Identifier: AGPL-3.0-or-later
"""Complete private pg_dump COPY-state comparison, never prints data or rewinds a database."""
import re
class Refused(RuntimeError):pass
LIMIT=512*1024*1024

def parse(data):
    if not isinstance(data,bytes) or len(data)>LIMIT:raise Refused('Private database snapshot limit')
    tables={};sequences=[];key=None;rows=[]
    for line in data.splitlines():
        if key is not None:
            if line==b'\\.':tables[key]=rows;key=None
            else:rows.append(line)
        elif line.startswith(b'COPY '):
            identifier=rb'(?:[a-zA-Z_][a-zA-Z0-9_]*|"[a-zA-Z_][a-zA-Z0-9_]*")'
            if not re.fullmatch(rb'COPY public\.[a-zA-Z_][a-zA-Z0-9_]* \('+identifier+rb'(?:, '+identifier+rb')*\) FROM stdin;',line):raise Refused('Private snapshot grammar unqualified')
            if line in tables:raise Refused('Private snapshot duplicate table')
            key=line;rows=[]
        elif line.startswith(b'SELECT pg_catalog.setval('):sequences.append(line)
        elif line and not line.startswith((b'--',b'SET ',b'SELECT pg_catalog.set_config(',b'\\restrict ',b'\\unrestrict ')):
            raise Refused('Private snapshot grammar unqualified')
    if key is not None or not tables or len(tables)>2000:raise Refused('Private snapshot incomplete')
    if len(set(sequences))!=len(sequences):raise Refused('Private snapshot duplicate sequence')
    return tables,sorted(sequences)

def compare(before,after,next_epoch,migrated,prefix='oc_'):
    if not re.fullmatch('[a-f0-9]{64}',next_epoch) or not re.fullmatch('[a-zA-Z_][a-zA-Z0-9_]*',prefix):raise Refused('Installer state binding invalid')
    b,bs=before;a,ass=after
    if bs!=ass:raise Refused('Installer sequence changed')
    table=lambda name,cols:f'COPY public.{prefix}{name} ({cols}) FROM stdin;'.encode()
    epoch=table('forms_mcp_group_epoch','id, epoch');history=table('forms_mcp_group_history','gid_key, gid, epoch, generation, state');migrations=table('migrations','app, version')
    expected=set(b)
    if migrated:
        if epoch in expected or history in expected:raise Refused('Installer schema preimage changed')
        expected|={epoch,history}
    if set(a)!=expected or migrations not in b:raise Refused('Installer table inventory changed')
    if a.get(epoch)!=[b'1\t'+next_epoch.encode()]:raise Refused('Installer epoch delta changed')
    if epoch in b and b[epoch]!=[] and (len(b[epoch])!=1 or not re.fullmatch(rb'1\t[a-f0-9]{64}',b[epoch][0]) or b[epoch]==a[epoch]):raise Refused('Installer prior epoch invalid')
    if a.get(history)!=b.get(history,[]):raise Refused('Installer histories changed')
    expected_migrations=list(b[migrations]);owned=b'forms\t050402Date20261115020000'
    if migrated:
        if owned in expected_migrations:raise Refused('Installer migration preimage changed')
        expected_migrations.append(owned)
    if sorted(a[migrations])!=sorted(expected_migrations):raise Refused('Installer migration delta changed')
    for name,rows in b.items():
        if name not in (epoch,history,migrations) and sorted(rows)!=sorted(a[name]):raise Refused('Installer protected state changed')
    return {'all_tables':len(a),'sequences_exact':True,'other_rows_exact':True,'histories_exact':True,'epoch_changed':True,'migration_added':migrated}


def application_versions(state,expected,prefix='oc_'):
    keys=[k for k in state[0] if k.startswith(('COPY public.'+prefix+'appconfig (').encode())]
    if len(keys)!=1:raise Refused('Application version state unavailable')
    key=keys[0];columns=key.decode().split(' (',1)[1].removesuffix(') FROM stdin;').split(', ');columns=[x.strip('"')for x in columns]
    if not all(x in columns for x in('appid','configkey','configvalue')):raise Refused('Application version columns unavailable')
    for app,version in expected.items():
        values=[]
        for line in state[0][key]:
            fields=line.split(b'\t')
            if len(fields)!=len(columns):raise Refused('Application config row malformed')
            if fields[columns.index('appid')]==app.encode()and fields[columns.index('configkey')]==b'installed_version':values.append(fields[columns.index('configvalue')])
        if values!=[version.encode()]:raise Refused('Installed application version differs')
    return True
