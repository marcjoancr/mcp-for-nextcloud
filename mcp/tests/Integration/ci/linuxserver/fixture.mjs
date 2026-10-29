// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import fs from 'node:fs/promises';
import path from 'node:path';
import { randomBytes } from 'node:crypto';
import { command } from './command.mjs';

export async function fixture(root, clientIp, profile) {
  const secrets = { db: randomBytes(24).toString('hex'), admin: randomBytes(24).toString('hex'), key: randomBytes(12).toString('hex'), secret: randomBytes(24).toString('hex') };
  const put = async (name, value, mode = 0o600) => {
    await fs.mkdir(path.dirname(path.join(root, name)), { recursive: true, mode: 0o755 });
    await fs.writeFile(path.join(root, name), value, { mode });
  };
  await put('pg.env', `POSTGRES_DB=nextcloud\nPOSTGRES_USER=nextcloud\nPOSTGRES_PASSWORD=${secrets.db}\n`);
  await put('app.env', `PUID=1000\nPGID=1000\nTZ=UTC\nMCP_S3_KEY=${secrets.key}\nMCP_S3_SECRET=${secrets.secret}\nMCP_DB_PASSWORD=${secrets.db}\nMCP_ADMIN_PASSWORD=${secrets.admin}\n`);
  await put('s3/s3.json', JSON.stringify({ identities: [{ name: 'fixture', credentials: [{ accessKey: secrets.key, secretKey: secrets.secret }], actions: ['Admin', 'Read', 'Write', 'List', 'Tagging'] }] }));
  await put('s3/filer.toml', '[leveldb2]\nenabled = true\ndir = "/data/filerldb2"\n');
  await put('s3/master.toml', `[master.volume_growth]\ncopy_1 = ${profile.objectStore.volumeGrowthCount}\ncopy_2 = 1\ncopy_3 = 1\ncopy_other = 1\n[master.maintenance]\nscripts = ""\n`);
  await put('config/mcp-l1-closed', 'closed\n');
  await put('config/crontabs/abc', ''); await put('config/crontabs/root', '');
  await put('config/php/www2.conf', '[www]\nclear_env = no\n');
  await put('config/www/nextcloud/config/fixture.config.php', `<?php
// Synthetic fixture only. Missing object-store credentials must fail closed.
if (!getenv('MCP_S3_KEY') || !getenv('MCP_S3_SECRET')) { throw new RuntimeException('Missing fixture object-store environment'); }
$CONFIG = [
 'appstoreenabled' => false, 'upgrade.disable-web' => true, 'updatechecker' => false,
 'trusted_domains' => ['nc.fixture.test'], 'overwrite.cli.url' => 'https://nc.fixture.test',
 'objectstore' => ['class' => '\\OC\\Files\\ObjectStore\\S3', 'arguments' => [
  'bucket' => 'mcp-l1', 'autocreate' => true, 'key' => getenv('MCP_S3_KEY'), 'secret' => getenv('MCP_S3_SECRET'),
  'hostname' => 's3', 'port' => 8333, 'use_ssl' => false, 'use_path_style' => true, 'region' => 'us-east-1',
 ]],
];
`, 0o644);
  const tls = path.join(root, 'config/tls'); await fs.mkdir(tls, { recursive: true });
  for (const args of [
    ['req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', `${tls}/ca.key`, '-out', `${tls}/ca.crt`, '-days', '1', '-subj', '/CN=MCP L1 private CA'],
    ['req', '-new', '-newkey', 'rsa:2048', '-nodes', '-keyout', `${tls}/server.key`, '-out', `${tls}/server.csr`, '-subj', '/CN=nc.fixture.test'],
  ]) { if ((await command('openssl', args)).code) throw new Error('Fixture certificate generation failed'); }
  await put('config/tls/extensions', 'subjectAltName=DNS:nc.fixture.test\nextendedKeyUsage=serverAuth\n');
  if ((await command('openssl', ['x509', '-req', '-in', `${tls}/server.csr`, '-CA', `${tls}/ca.crt`, '-CAkey', `${tls}/ca.key`, '-CAcreateserial', '-out', `${tls}/server.crt`, '-days', '1', '-extfile', `${tls}/extensions`])).code) throw new Error('Fixture signing failed');
  await fs.chmod(`${tls}/server.key`, 0o600); await fs.chmod(`${tls}/ca.key`, 0o600);
  // Server-level gates run before any PHP/location handling. Never trust proxy headers.
  await put('config/nginx/site-confs/default.conf', `map $remote_addr $mcp_admitted { default 0; ${clientIp} 1; }
server {
 listen 443 ssl;
 server_name nc.fixture.test;
 ssl_certificate /config/tls/server.crt;
 ssl_certificate_key /config/tls/server.key;
 root /app/www/public;
 if (-f /config/mcp-l1-closed) { return 503; }
 if ($mcp_admitted = 0) { return 403; }
 location ^~ /source/ { alias /fixture-source/; autoindex off; limit_except GET { deny all; } }
 location ~ ^/(?:config|data|lib|3rdparty|tests|build)(?:/|$) { return 404; }
 location ~ /\\. { return 404; }
 location = /occ { return 404; }
 location ~ ^/(?:index|status|remote|public|ocs/v[12]|mcp-l1-health)\\.php(?:/|$) {
  fastcgi_split_path_info ^(.+?\\.php)(/.*)$;
  include /etc/nginx/fastcgi_params;
  fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
  fastcgi_param PATH_INFO $fastcgi_path_info;
  fastcgi_pass 127.0.0.1:9000;
 }
 location ~ \\.php { return 404; }
 location / { try_files $uri $uri/ /index.php$request_uri; }
}
`, 0o644);
  return secrets;
}

export async function startPausedApp(checked, root, app) {
  // The pinned image imports /etc/crontabs directly at startup.
  await checked(['cp', `${root}/config/crontabs/.`, `${app}:/etc/crontabs`]);
  await checked(['start', app]);
}

// Query effective tables; cron.update is scheduler metadata, not a user's jobs.
export const emptyCronTablesCommand = 'mcp_abc_cron=$(crontab -l -u abc) && mcp_root_cron=$(crontab -l -u root) && test -z "$mcp_abc_cron$mcp_root_cron"';
