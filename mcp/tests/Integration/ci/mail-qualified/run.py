# SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Disposable qualified Mail profile. Requires exact clean source kits; never deploys.

Private logs, credentials, SQL and certificates stay outside the upload allowlist.
A failed run is retained for diagnosis. Cleanup is explicit and label guarded.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import subprocess
import time
import sys
sys.dont_write_bytecode = True

import catalog
from prepare import digest

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[4]
CI = HERE.parent
PROFILE = json.loads((HERE / 'profile.json').read_text())
BOOT = "<?php define('OC_CONSOLE',true);require '/app/www/public/lib/base.php';"

class Fixture:
    def __init__(self, descriptor):
        self.inputs = json.loads(descriptor.read_text())
        if set(self.inputs) != {'schemaVersion', 'sourceCommit', 'kit', 'manifestSha256', 'native', 'nativeManifestSha256'} or self.inputs['schemaVersion'] != 1:
            raise ValueError('Invalid immutable inputs')
        self.sha = self.inputs['sourceCommit']
        if not re.fullmatch('[0-9a-f]{40}', self.sha):
            raise ValueError('Exact source commit required')
        self.run = 'mcp-it-' + time.strftime('%Y%m%d%H%M%S', time.gmtime()) + '-' + secrets.token_hex(6)
        self.work = ROOT / 'mcp/build/integration' / self.run
        self.work.mkdir(parents=True, mode=0o700)
        self.w = self.work / 'mcp-it'
        self.tools = self.w / 'tools'
        self.public = self.work / 'qualified-evidence'
        self.private = self.work / 'private'
        for path in [self.tools, self.public, self.private]:
            path.mkdir(parents=True)
        self.tools.chmod(0o755)
        self.names = {name: self.run + '-' + name for name in ['nc', 'db', 'imap', 's3']}
        self.net = self.run + '-net'
        self.volumes = []
        self.sequence = 0
        self.stage = 'inputs'
        self.started = time.monotonic()
        self.coverage = {}
        self.inventory_records = {}
        self.cat = catalog.validate(catalog.load())
        self.env = dict(os.environ)
        for name in list(self.env):
            if name.startswith('MCP_IT_') or name.startswith('MCP_LINUXSERVER_'):
                del self.env[name]
        self.env.update(MCP_IT_LOCAL='1', MCP_IT_RUN_ID=self.run, MCP_IT_NC='35', MCP_IT_DB='pgsql', MCP_IT_MODE='fresh', MCP_IT_BASELINE='', MCP_IT_NET=self.net,
                        MCP_IT_NC_NAME=self.names['nc'], MCP_IT_DB_NAME=self.names['db'], MCP_IT_IMAP_NAME=self.names['imap'], MCP_IT_WITH_MAIL='1', MCP_IT_WITH_FORMS='1',
                        MCP_IT_NC_IMAGE=PROFILE['images']['nextcloud'], MCP_IT_DB_IMAGE=PROFILE['images']['postgres'], MCP_IT_MAIL_GUARD='0',
                        MCP_IT_MAIL_UI='0', MCP_IT_MAIL_CREDENTIAL_UI='0', RUNNER_TEMP=str(self.work))
        self.kit = Path(self.inputs['kit']).resolve()
        self.native = Path(self.inputs['native']).resolve()
        for directory in [self.kit, self.native]:
            if not directory.is_relative_to((ROOT / 'mcp/build').resolve()) or not directory.is_dir():
                raise ValueError('Source kit inputs must be existing directories under this checkout mcp/build')
        self.result = {'schemaVersion': 1, 'profile': PROFILE['id'], 'run': self.run, 'sourceCommit': self.sha, 'status': 'running', 'operatorEnabled': None}

    def call(self, args, data=None, timeout=300, allow_failure=False):
        self.sequence += 1
        if time.monotonic() - self.started > PROFILE['limits']['seconds']:
            raise RuntimeError('Qualification deadline exceeded')
        result = subprocess.run(list(map(str, args)), input=data, capture_output=True, env=self.env, cwd=ROOT, timeout=timeout)
        path = self.private / f'{self.sequence:04d}.log'
        path.write_bytes(result.stdout + result.stderr)
        if result.returncode and not allow_failure:
            raise RuntimeError(f'Qualification command failed at private log {path.name}, exit {result.returncode}')
        return result

    def docker(self, *args, **kwargs):
        return self.call(['docker', *args], **kwargs).stdout

    def occ(self, *args):
        return self.docker('exec', '-u', '1000:1000', self.names['nc'], 'php', '-d', 'apc.enable_cli=1', '/app/www/public/occ', *args)

    def php(self, source):
        return self.docker('exec', '-i', '-u', '1000:1000', self.names['nc'], 'php', '-d', 'apc.enable_cli=1', data=(BOOT + source).encode())

    def probe(self, action, payload=None):
        if action == 'off':
            self.result['operatorEnabled'] = None
        raw = self.docker('exec', '-i', '-u', '1000:1000', self.names['nc'], 'php', '-d', 'apc.enable_cli=1',
                          '/mcp-it-src/mcp/tests/Integration/ci/mail-qualified/probe.php', action, data=json.dumps(payload or {}).encode())
        value = json.loads(raw)
        if value.get('ok') is not True:
            raise RuntimeError('Missing successful probe marker')
        if action == 'off':
            if value.get('result', {}).get('operatorEnabled') is not False:
                raise RuntimeError('Operator OFF observation was not confirmed')
            self.result['operatorEnabled'] = False
        return value['result']

    def phase(self, name):
        # A previous phase's observation cannot establish the state after new work begins.
        self.result['operatorEnabled'] = None
        self.stage = name
        print('Qualified phase:', name, flush=True)

    def step(self, name, script='harness.sh'):
        self.call(['sh', CI / script, name], timeout=1800)

    def volume(self, suffix):
        name = self.run + '-' + suffix
        self.docker('volume', 'create', '--label', 'mcp.integration.run=' + self.run, name)
        row = json.loads(self.docker('volume', 'inspect', name))[0]
        if row.get('Labels', {}).get('mcp.integration.run') != self.run:
            raise RuntimeError('Volume ownership mismatch before mount')
        self.volumes.append(name)
        return name

    def verify_inputs(self):
        head = self.call(['git', 'rev-parse', 'HEAD']).stdout.decode().strip()
        dirty = self.call(['git', 'status', '--porcelain', '--untracked-files=normal']).stdout
        if head != self.sha or dirty:
            raise RuntimeError('Runner checkout must equal the clean frozen source')
        cli = ROOT / 'mcp/scripts/release/cli.mjs'
        self.call(['node', cli, 'verify', self.kit, self.inputs['manifestSha256']])
        self.call(['node', cli, 'verify-native', self.native, self.inputs['nativeManifestSha256'], self.sha])
        self.m = json.loads((self.kit / 'manifest.json').read_text())
        self.n = json.loads((self.native / 'native-manifest.json').read_text())
        if self.m['sourceCommit'] != self.sha or self.n['sourceCommit'] != self.sha or self.n['nativeBuild'] != PROFILE['nativeBuild']:
            raise RuntimeError('Source identity mismatch')
        if self.n['upstreamRuntimeSha256'] != PROFILE['upstreamRuntime']['sha256']:
            raise RuntimeError('Native upstream identity mismatch')
        self.inventories = {'mcp': self.m['runtimeFiles'], 'mail': self.n['runtimeFiles']}
        plan = json.loads(self.call(['php', CI / 'matrix.php', '35', 'pgsql', 'fresh', '--mail']).stdout)
        plan.update(nc_image=PROFILE['images']['nextcloud'], db_image=PROFILE['images']['postgres'], qualified_profile=PROFILE['id'])
        (self.work / 'plan.json').write_text(json.dumps(plan))
        shutil.copyfile(self.kit / ('mcp-' + self.m['version'] + '.tar.gz'), self.w / 'mcp-branch.tar.gz')
        source = self.w / 'test-source/mcp/tests'
        source.mkdir(parents=True)
        shutil.copytree(ROOT / 'mcp/tests/Integration', source / 'Integration', ignore=shutil.ignore_patterns('__pycache__'))
        (source / 'Support/Access/Policy').mkdir(parents=True)
        shutil.copyfile(ROOT / 'mcp/tests/Support/Access/Policy/PolicyServices.php', source / 'Support/Access/Policy/PolicyServices.php')
        for path in (self.w / 'test-source').rglob('*'):
            path.chmod(0o755 if path.is_dir() else 0o644)
        (self.w / 'test-source').chmod(0o755)
        self.call(['python3', CI / 'linuxserver/extract.py', self.native / 'mail-assembled-runtime.tar.gz', self.tools / 'native-stage', 'mail'])

    def images(self):
        self.phase('pinned-images')
        self.result['images'] = {}
        for name, reference in PROFILE['images'].items():
            self.docker('pull', '--platform', PROFILE['platform'], reference, timeout=900)
            row = json.loads(self.docker('image', 'inspect', reference))[0]
            if row['Os'] + '/' + row['Architecture'] != PROFILE['platform'] or reference.split('@')[1] not in ' '.join(row.get('RepoDigests', [])):
                raise RuntimeError('Image digest/platform mismatch')
            self.result['images'][name] = {'reference': reference, 'imageId': row['Id'], 'platform': PROFILE['platform']}

    def start_s3(self, volume, key, secret):
        # Same credentials, persistent filer and bounded volume layout as linuxserver/fixture.mjs.
        directory = self.private / 's3'
        directory.mkdir(mode=0o700)
        files = {
            's3.json': json.dumps({'identities': [{'name': 'fixture', 'credentials': [{'accessKey': key, 'secretKey': secret}], 'actions': ['Admin', 'Read', 'Write', 'List', 'Tagging']}]}),
            'filer.toml': '[leveldb2]\nenabled = true\ndir = "/data/filerldb2"\n',
            'master.toml': '[master.volume_growth]\ncopy_1 = 1\ncopy_2 = 1\ncopy_3 = 1\ncopy_other = 1\n[master.maintenance]\nscripts = ""\n',
        }
        for name, content in files.items():
            path = directory / name
            path.write_text(content)
            path.chmod(0o600)
        env = self.private / 'nc-s3.env'
        env.write_text(f'MCP_S3_KEY={key}\nMCP_S3_SECRET={secret}\n')
        env.chmod(0o600)
        probes = self.tools / 's3-probes'
        probes.mkdir()
        probes.chmod(0o755)
        for source in [CI / 'linuxserver/probe.php', CI.parent / 'Support/ProbeCompletion.php']:
            destination = probes / source.name
            shutil.copyfile(source, destination)
            destination.chmod(0o644)
        self.docker('run', '-d', '--platform', PROFILE['platform'], '--pull', 'never', '--label', 'mcp.integration.run=' + self.run, '--name', self.names['s3'], '--network', self.net,
                    '--network-alias', 's3', '--cpus', '.5', '--memory', '1024m', '--memory-swap', '1024m', '--pids-limit', '256', '--entrypoint', 'weed',
                    '-v', volume + ':/data', '-v', str(directory) + ':/etc/seaweedfs:ro', PROFILE['images']['s3'],
                    'server', '-dir=/data', '-master.dir=/data', '-ip=s3', '-ip.bind=0.0.0.0', '-filer', '-s3', '-s3.config=/etc/seaweedfs/s3.json',
                    '-volume.max=4', '-master.volumeSizeLimitMB=512', '-volume.preStopSeconds=1')
        return env

    def s3_ready(self):
        # Reuse the historical S3 protocol checks, including its explicit completion marker.
        curl = ['docker', 'exec', self.names['nc'], 'curl', '--silent', '--max-time', '3', '-o', '/dev/null', '-w', '%{http_code}']
        for _ in range(PROFILE['limits']['readinessSeconds'] // 2):
            reply = self.call([*curl, 'http://s3:8333/'], allow_failure=True)
            if reply.returncode == 0 and reply.stdout == b'403':
                break
            time.sleep(2)
        else:
            raise RuntimeError('Authenticated S3 readiness deadline')
        denied = self.call([*curl, '--aws-sigv4', 'aws:amz:us-east-1:s3', '--user', 'invalid:invalid', 'http://s3:8333/mcp-l1/'], allow_failure=True)
        if denied.returncode or denied.stdout != b'403':
            raise RuntimeError('S3 accepted invalid signing credentials')
        modes = ['s3-create', 's3-read', 's3-delete']
        for mode in modes:
            raw = self.docker('exec', '-u', '1000:1000', self.names['nc'], 'php', '/fixture-probes/probe.php', mode)
            markers = [line.removeprefix(b'MCP_L1_COMPLETE=') for line in raw.splitlines() if line.startswith(b'MCP_L1_COMPLETE=')]
            if len(markers) != 1:
                raise RuntimeError('Missing S3 probe completion')
            result = json.loads(markers[0])
            if result.get('ok') is not True or type(result.get('checks')) is not int or result['checks'] < 1:
                raise RuntimeError('Incomplete S3 protocol checks')
        self.result['s3'] = {'implementation': 'seaweedfs', 'anonymousDenied': True, 'invalidSignatureDenied': True, 'authenticatedProbes': modes}

    def bootstrap(self):
        self.phase('bootstrap-pg16-s3')
        self.step('tools')
        self.step('start-db')
        appvol, cfgvol, datavol, s3vol = [self.volume(s) for s in ['app', 'config', 'data', 's3data']]
        s3key, s3secret = secrets.token_hex(12), secrets.token_hex(24)
        s3env = self.start_s3(s3vol, s3key, s3secret)
        self.docker('run', '-d', '--platform', PROFILE['platform'], '--pull', 'never', '--label', 'mcp.integration.run=' + self.run, '--name', self.names['nc'], '--network', self.net,
                    '-e', 'PUID=1000', '-e', 'PGID=1000', '-e', 'TZ=UTC', '--env-file', s3env, '-v', str(self.tools / 's3-probes') + ':/fixture-probes:ro', '-v', appvol + ':/app/www/public', '-v', cfgvol + ':/config', '-v', datavol + ':/data',
                    '-v', str(self.w / 'test-source') + ':/mcp-it-src:ro', '-v', str(self.tools) + ':/mcp-it-tools:ro', '-v', str(self.w / 'pglog') + ':/mcp-it-pglog:ro', PROFILE['images']['nextcloud'])
        ready = False
        for _ in range(PROFILE['limits']['readinessSeconds'] // 2):
            r = self.call(['docker', 'exec', self.names['nc'], 'sh', '-c', 'test -f /app/www/public/occ && s6-svstat /run/service/svc-php-fpm | grep -q ^up'], allow_failure=True)
            if r.returncode == 0:
                ready = True
                break
            time.sleep(2)
        if not ready:
            raise RuntimeError('LinuxServer readiness deadline')
        self.docker('exec', self.names['nc'], 's6-rc', '-d', 'change', 'svc-cron')
        self.docker('exec', self.names['nc'], 'sh', '-c', "mkdir -p /var/www; ln -s /app/www/public /var/www/html; ln -s /config/www/nextcloud/apps /app/www/public/custom_apps; printf '\nwww-data:x:1000:1000:fixture:/config:/bin/sh\n' >> /etc/passwd")
        cfg = {'appstoreenabled': False, 'updatechecker': False, 'allow_local_remote_servers': True,
               'apps_paths': [{'path': '/var/www/html/custom_apps', 'url': '/custom_apps', 'writable': True}],
               'objectstore': {'class': '\\OC\\Files\\ObjectStore\\S3', 'arguments': {'bucket': 'mail-qualified', 'hostname': 's3', 'port': 8333, 'use_ssl': False, 'use_path_style': True, 'region': 'us-east-1', 'key': s3key, 'secret': s3secret, 'autocreate': True}}}
        encoded = __import__("base64").b64encode(json.dumps(cfg).encode()).decode()
        config_php = "<?php $CONFIG=json_decode(base64_decode('" + encoded + "'),true,512,JSON_THROW_ON_ERROR);"
        self.docker('exec', '-i', self.names['nc'], 'sh', '-c', 'cat > /config/www/nextcloud/config/fixture.config.php; chown 1000:1000 /config/www/nextcloud/config/fixture.config.php', data=config_php.encode())
        self.s3_ready()
        credentials = dict(line.split('=', 1) for line in (self.w / 'credentials').read_text().splitlines())
        self.occ('maintenance:install', '--database', 'pgsql', '--database-host', self.names['db'], '--database-name', 'nextcloud', '--database-user', 'nextcloud', '--database-pass', credentials['DB_PASSWORD'], '--admin-user', 'admin', '--admin-pass', credentials['ADMIN_PASSWORD'], '--data-dir', '/data')
        self.occ('config:system:set', 'trusted_domains', '1', '--value=localhost')
        self.occ('config:system:set', 'memcache.local', '--value=\\OC\\Memcache\\APCu')
        self.occ('config:system:set', 'filelocking.enabled', '--value=true', '--type=boolean')
        self.occ('config:system:set', 'app.mail.mcp-transmission.enabled', '--value=false', '--type=boolean')
        self.step('install-app')
        self.step('fixtures')
        self.step('provision')
        self.step('prepare', 'mail-cell.sh')
        self.phase('stock-legacy-native-sync')
        raw = self.occ('mail:account:create-imap', 'mcp-it-legacy', 'Synthetic legacy', 'legacy@example.test', self.names['imap'], '31143', 'none', 'alice', 'mcp-it-imap-password', self.names['imap'], '31587', 'none', 'alice', 'mcp-it-imap-password')
        match = re.search(rb'Account (\d+) for ', raw)
        if not match:
            raise RuntimeError('Missing legacy account creation marker')
        self.occ('mail:account:sync', match.group(1).decode())
        self.result['legacyBefore'] = self.probe('legacy-before')

    def install_native(self):
        self.phase('full-native-v12-migration')
        self.docker('exec', self.names['nc'], 's6-rc', '-d', 'change', 'svc-php-fpm', 'svc-cron')
        self.docker('exec', self.names['nc'], 'mkdir', '-p', '/config/mcp-qualified-retained')
        self.docker('exec', self.names['nc'], 'mv', '/config/www/nextcloud/apps/mail', '/config/mcp-qualified-retained/mail-stock')
        self.docker('exec', self.names['nc'], 'cp', '-a', '/mcp-it-tools/native-stage/mail', '/config/www/nextcloud/apps/mail')
        self.docker('exec', self.names['nc'], 'chown', '-R', '1000:1000', '/config/www/nextcloud/apps/mail')
        self.result['migration'] = self.probe('migrate')
        self.result['legacyAfter'] = self.probe('legacy-after')
        self.inventory_records['installed'] = self.probe('inventory', self.inventories)
        self.env['MCP_IT_MAIL_GUARD'] = '1'
        self.step('accounts', 'mail-cell.sh')
        self.docker('exec', self.names['nc'], 's6-rc', '-u', 'change', 'svc-php-fpm', 'svc-nginx')

    def services(self):
        self.phase('synthetic-tls-and-source-services')
        cert = self.private / 'delivery-cert'
        cert.mkdir()
        self.call(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '2', '-subj', '/CN=127.0.0.1', '-addext', 'subjectAltName=IP:127.0.0.1,DNS:localhost', '-keyout', cert / 'key.pem', '-out', cert / 'certificate.pem'])
        (cert / 'mode.json').write_text(json.dumps({'fixture': 'mcp-synthetic-delivery-v1', 'port': 33466, 'mode': 'accept', 'pipelining': True}))
        self.docker('exec', self.names['nc'], 'mkdir', '/tmp/mcp-mail-delivery-fixture')
        for name in ['key.pem', 'certificate.pem', 'mode.json']:
            self.docker('cp', cert / name, self.names['nc'] + ':/tmp/mcp-mail-delivery-fixture/' + name)
        self.docker('cp', cert / 'certificate.pem', self.names['nc'] + ':/usr/local/share/ca-certificates/mcp-delivery-fixture.crt')
        self.docker('exec', self.names['nc'], 'update-ca-certificates')
        self.docker('exec', self.names['nc'], 'chown', '-R', '1000:1000', '/tmp/mcp-mail-delivery-fixture')
        self.occ('config:system:set', 'default_certificates_bundle_path', '--value=/etc/ssl/certs/ca-certificates.crt')
        self.docker('exec', '-d', '-u', '1000:1000', self.names['nc'], 'sh', '-c', 'php /mcp-it-src/mcp/tests/Integration/ci/mail/delivery-server.php /tmp/mcp-mail-delivery-fixture > /tmp/mcp-mail-delivery-fixture/private.log 2>&1')
        source = self.tools / 'source-offer'
        source.mkdir(mode=0o755)
        source.chmod(0o755)
        assets = {}
        for directory, manifest, name, trusted in [(self.kit, self.m, 'manifest.json', self.inputs['manifestSha256']), (self.native, self.n, 'native-manifest.json', self.inputs['nativeManifestSha256'])]:
            rows = dict(manifest['files'])
            rows[name] = {'sha256': trusted, 'bytes': (directory / name).stat().st_size}
            for filename, record in rows.items():
                if filename in assets:
                    raise RuntimeError('Source filename collision')
                shutil.copyfile(directory / filename, source / filename)
                (source / filename).chmod(0o644)
                assets[filename] = record
        self.docker('exec', '-d', '-u', '1000:1000', self.names['nc'], 'sh', '-c', 'php -S 127.0.0.1:8765 -t /mcp-it-tools/source-offer > /tmp/mcp-qualified-source.private.log 2>&1')
        time.sleep(1)
        self.probe('source', {'mcp_source_offer': {'version': self.m['version'], 'sourceCommit': self.sha, 'manifestSha256': self.inputs['manifestSha256'], 'baseUrl': 'http://127.0.0.1:8765'},
                              'mcp_native_source_offer': {'schemaVersion': 1, 'sourceCommit': self.sha, 'nativeBuild': PROFILE['nativeBuild'], 'nativeManifestSha256': self.n['nativeManifestSha256'], 'manifestSha256': self.inputs['nativeManifestSha256'], 'baseUrl': 'http://127.0.0.1:8765'}})
        self.downloads = self.probe('downloads', assets)
        if len(self.downloads) != 11:
            raise RuntimeError('Both exact source kits are required')
        self.docker('exec', self.names['nc'], 's6-rc', '-d', 'change', 'svc-php-fpm')
        self.docker('exec', self.names['nc'], 's6-rc', '-u', 'change', 'svc-php-fpm')

    def prepare_test_runtime(self):
        self.phase('test-cli-runtime')
        pin = PROFILE['testRuntime']['tokenizer']
        archive = self.tools / 'tokenizer.apk'
        self.call(['sh', CI / 'fetch-release.sh', pin['url'], archive])
        if digest(archive) != pin['sha256']:
            raise RuntimeError('Test tokenizer archive integrity mismatch')
        archive.chmod(0o644)
        self.docker('exec', self.names['nc'], 'apk', '--no-network', 'verify', '/mcp-it-tools/tokenizer.apk')
        self.docker('exec', self.names['nc'], 'mkdir', '-p', '/tmp/mcp-qualified-tokenizer', '/tmp/mcp-qualified-php')
        self.docker('exec', self.names['nc'], 'apk', '--no-network', 'extract', '--destination', '/tmp/mcp-qualified-tokenizer', '/mcp-it-tools/tokenizer.apk')
        self.docker('exec', '-i', self.names['nc'], 'sh', '-c', 'cat > /tmp/mcp-qualified-php/tokenizer.ini; chmod 755 /tmp/mcp-qualified-php /tmp/mcp-qualified-tokenizer; chmod 644 /tmp/mcp-qualified-php/tokenizer.ini',
                    data=b'extension=/tmp/mcp-qualified-tokenizer/usr/lib/php85/modules/tokenizer.so\n')
        self.docker('exec', '-u', '1000:1000', '-e', 'PHP_INI_SCAN_DIR=/etc/php85/conf.d:/tmp/mcp-qualified-php', self.names['nc'], 'php', '-r',
                    'if (!extension_loaded("tokenizer") || !function_exists("token_get_all") || PHP_VERSION !== ' + json.dumps(PROFILE['testRuntime']['phpVersion']) + ') exit(1);')
        self.result['testRuntime'] = {'phpVersion': PROFILE['testRuntime']['phpVersion'], 'tokenizerSha256': pin['sha256'], 'alpineSignature': 'verified', 'scope': 'test CLI only'}

    def test_env(self):
        return {'PHP_INI_SCAN_DIR': '/etc/php85/conf.d:/tmp/mcp-qualified-php', 'NEXTCLOUD_ROOT': '/var/www/html', 'MCP_IT_NC': '35', 'MCP_IT_DB': 'pgsql', 'MCP_IT_MODE': 'fresh', 'MCP_IT_WITH_MAIL': '1', 'MCP_IT_WITH_FORMS': '1',
                'MCP_IT_MAIL_GUARD': '1', 'MCP_IT_EXPECTED_VERSION': self.m['version'], 'MCP_IT_SOURCE_COMMIT': self.sha, 'MCP_IT_CLEAN_MAIL': '1',
                'MCP_IT_QUALIFIED_PROFILE': PROFILE['id'], 'MCP_IT_LEGACY_UNKNOWN': '1', 'MCP_IT_LEGACY_UID': 'mcp-it-legacy', 'MCP_IT_NATIVE_SOURCE_OFFER': '1', 'MCP_IT_PROJECTION_HTTP': '1',
                'MCP_IT_PG_LOG': '/mcp-it-pglog/postgresql.log', 'MCP_IT_IMAP_HOST': self.names['imap'], 'MCP_IT_IMAP_PORT': '31143', 'MCP_IT_IMAP_PASSWORD': 'mcp-it-imap-password',
                'MCP_IT_DELIVERY_DIRECTORY': '/tmp/mcp-mail-delivery-fixture', 'MCP_IT_PROJECTION_UPSTREAM_HOST': self.names['imap'], 'MCP_IT_PROJECTION_UPSTREAM_PORT': '31143', 'MCP_IT_GF_NAME': 'mcp-it-gf'}

    def tests(self):
        environment = [item for k, v in self.test_env().items() for item in ['-e', k + '=' + v]]
        for phase in ['policy', 'legacy', 'regression', 'core', 'extras', 'clean', 'source', 'projection']:
            self.phase(phase)
            if phase in ['clean', 'projection']:
                self.inventory_records['before-' + phase] = self.probe('inventory', self.inventories)
            config = self.tools / (phase + '.xml')
            if phase == 'policy':
                config.write_text('<phpunit bootstrap="/mcp-it-src/mcp/tests/Integration/ci/mail-qualified/bootstrap.php" cacheResult="false" failOnWarning="true" failOnRisky="true" failOnSkipped="true" failOnIncomplete="true"><testsuites><testsuite name="policy"><file>/mcp-it-src/mcp/tests/Integration/Policy/ResourceScopeWriterTest.php</file></testsuite></testsuites></phpunit>')
                required = {'ResourceScopeWriterTest::' + method for method in catalog.methods(CI.parent / 'Policy/ResourceScopeWriterTest.php')}
            else:
                catalog.suite(self.cat, phase, config)
                required = catalog.expected(self.cat, phase)
            config.chmod(0o644)
            result = self.call(['docker', 'exec', '-u', '1000:1000', *environment, self.names['nc'], 'php', '-d', 'memory_limit=1G', '/mcp-it-tools/phpunit.phar', '-c', '/mcp-it-tools/' + config.name,
                                '--fail-on-empty-test-suite', '--log-junit', '/tmp/mcp-qualified-' + phase + '.xml'], timeout=3600, allow_failure=True)
            junit = self.private / (phase + '.xml')
            self.docker('cp', self.names['nc'] + ':/tmp/mcp-qualified-' + phase + '.xml', junit)
            self.probe('off')
            self.coverage[phase] = catalog.result(junit, required)
            if result.returncode:
                raise RuntimeError('Qualified PHPUnit nonzero exit')
            if phase in ['clean', 'projection']:
                self.inventory_records['after-' + phase] = self.probe('inventory', self.inventories)
            print('Qualified methods passed:', self.coverage[phase]['methods'], flush=True)
        self.result['legacyFinal'] = self.probe('legacy-after')

    def cleanup(self):
        for kind, names in [('container', list(self.names.values())), ('volume', self.volumes), ('network', [self.net])]:
            for name in names:
                inspected = self.call(['docker', kind, 'inspect', name], allow_failure=True)
                if inspected.returncode:
                    raise RuntimeError('Owned cleanup target could not be inspected')
                row = json.loads(inspected.stdout)[0]
                labels = row.get('Config', {}).get('Labels', {}) if kind == 'container' else row.get('Labels', {})
                if labels.get('mcp.integration.run') != self.run:
                    raise RuntimeError('Cleanup ownership mismatch')
                self.docker(kind, 'rm', *(['-f', '-v'] if kind == 'container' else []), name)

    def publish(self):
        # This is the sole upload directory. No native/raw test output is copied here.
        self.result.update(stage=self.stage, elapsedSeconds=round(time.monotonic() - self.started), manifestSha256=self.inputs['manifestSha256'], nativeManifestSha256=self.inputs['nativeManifestSha256'])
        records = {'result.json': self.result, 'coverage.json': self.coverage, 'inventories.json': self.inventory_records, 'downloads.json': getattr(self, 'downloads', {})}
        for name, value in records.items():
            (self.public / name).write_text(json.dumps(value, indent=2) + '\n')
        (self.public / 'test-summary.log').write_text('\n'.join(f"{phase}: {value['tests']} tests, {value['methods']} methods, {value['assertions']} assertions; no skips" for phase, value in self.coverage.items()) + '\n')
        if set(p.name for p in self.public.iterdir()) != set(PROFILE['artifactAllowlist']):
            raise RuntimeError('Unexpected upload artifact')
        print('Sanitized qualification evidence:', self.public, flush=True)

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--plan', action='store_true')
    parser.add_argument('--inputs', type=Path)
    parser.add_argument('--cleanup', action='store_true', help='remove only this owned successful fixture; failures remain retained')
    args = parser.parse_args()
    if args.plan:
        c = catalog.validate(catalog.load())
        print(json.dumps({'profile': PROFILE, 'phases': {p: len(catalog.expected(c, p)) for p in catalog.PHASES}}, indent=2))
        return
    if not args.inputs or not args.inputs.is_file():
        parser.error('--inputs must name canonical immutable input descriptor')
    os.umask(0o077)
    fixture = Fixture(args.inputs)
    try:
        fixture.verify_inputs()
        fixture.images()
        fixture.bootstrap()
        fixture.install_native()
        fixture.services()
        fixture.prepare_test_runtime()
        fixture.tests()
        if args.cleanup:
            fixture.cleanup()
        fixture.result['status'] = 'passed'
    except Exception as error:
        fixture.result.update(status='failed', failureType=type(error).__name__)
        print('Qualified fixture FAILED at', fixture.stage, '; private evidence retained. No automatic replay.', flush=True)
        raise SystemExit(1) from None
    finally:
        fixture.publish()

if __name__ == '__main__':
    main()
