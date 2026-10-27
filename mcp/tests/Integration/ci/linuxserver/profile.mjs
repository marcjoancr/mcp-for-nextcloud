// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import fs from 'node:fs/promises';
import { constants } from 'node:fs';
import { createHash } from 'node:crypto';

const sha256 = /^[a-f0-9]{64}$/;
const sha1 = /^[a-f0-9]{40}$/;
const version = /^\d+\.\d+\.\d+$/;
function requireValue(valid) { if (!valid) throw new Error('Invalid profile: unsupported, missing or unsafe field'); }
function fields(value, names) {
  requireValue(value !== null && typeof value === 'object' && !Array.isArray(value)
    && Object.keys(value).sort().join('|') === [...names].sort().join('|'));
}
function digest(value) { requireValue(typeof value === 'string' && sha256.test(value)); }
function integer(value, min, max) { requireValue(Number.isSafeInteger(value) && value >= min && value <= max); }
function bounds(value, target) {
  fields(value, ['min-version', 'max-version']);
  for (const key of ['min-version', 'max-version']) requireValue(typeof value[key] === 'string' && /^\d+(?:\.\d+)?$/.test(value[key]));
  requireValue(Number(value['min-version']) <= target && Number(value['max-version']) >= target);
}

/** Fixed local qualification topology, with no endpoints, commands or existing resource selectors. */
export function validateProfile(p) {
  fields(p, ['schemaVersion', 'id', 'platform', 'nextcloudVersion', 'baseline', 'candidate', 'images', 'apps', 'isolation', 'objectStore', 'sourceDelivery', 'limits']);
  requireValue(p.schemaVersion === 1 && p.id === 'linuxserver-nc35-upstream120' && p.platform === 'linux/amd64' && p.nextcloudVersion === '35.0.2');
  fields(p.baseline, ['version', 'sourceCommit', 'archiveSha256', 'archiveBytes', 'inventorySha256', 'runtimeFileCount']);
  requireValue(p.baseline.version === '1.2.0' && p.baseline.sourceCommit === '02526119b845ccc1d8399be002da3e30b5527df6');
  digest(p.baseline.archiveSha256); digest(p.baseline.inventorySha256);
  integer(p.baseline.archiveBytes, 1, 256 * 1024 * 1024); integer(p.baseline.runtimeFileCount, 1, 10000);
  fields(p.candidate, ['version', 'sourceCommit', 'manifestSha256']);
  requireValue(p.candidate.version === '2.0.0' && typeof p.candidate.sourceCommit === 'string' && sha1.test(p.candidate.sourceCommit));
  digest(p.candidate.manifestSha256);
  fields(p.images, ['nextcloud', 'postgres', 'seaweedfs']);
  const references = {
    nextcloud: /^lscr\.io\/linuxserver\/nextcloud:version-35\.0\.2@sha256:[a-f0-9]{64}$/,
    postgres: /^postgres:16\.15@sha256:[a-f0-9]{64}$/,
    seaweedfs: /^chrislusf\/seaweedfs:\d+\.\d+(?:\.\d+)?@sha256:[a-f0-9]{64}$/,
  };
  for (const [name, pattern] of Object.entries(references)) {
    fields(p.images[name], ['reference', 'platformDigest']);
    requireValue(typeof p.images[name].reference === 'string' && pattern.test(p.images[name].reference));
    requireValue(typeof p.images[name].platformDigest === 'string' && /^sha256:[a-f0-9]{64}$/.test(p.images[name].platformDigest));
  }
  const apps = { calendar: '6.6.3', contacts: '8.9.2', deck: '1.19.0', notes: '6.1.0', spreed: '25.0.6' };
  requireValue(Array.isArray(p.apps) && p.apps.length === 5);
  const seen = new Set();
  for (const app of p.apps) {
    fields(app, ['id', 'version', 'url', 'sha256', 'bytes', 'nextcloud', 'php']);
    requireValue(Object.hasOwn(apps, app.id) && !seen.has(app.id) && app.version === apps[app.id]);
    seen.add(app.id);
    requireValue(typeof app.version === 'string' && version.test(app.version));
    requireValue(app.url === `https://github.com/nextcloud-releases/${app.id}/releases/download/v${app.version}/${app.id}-v${app.version}.tar.gz`);
    digest(app.sha256); integer(app.bytes, 1, 256 * 1024 * 1024); bounds(app.nextcloud, 35);
    if (app.php !== null) bounds(app.php, 8.5);
  }
  fields(p.isolation, ['internalNetwork', 'publishedPorts', 'productionInputs', 'resourceOwnership', 'runtimeEgress']);
  requireValue(p.isolation.internalNetwork === true && Array.isArray(p.isolation.publishedPorts) && p.isolation.publishedPorts.length === 0
    && p.isolation.productionInputs === false && p.isolation.resourceOwnership === 'generated-per-run' && p.isolation.runtimeEgress === false);
  fields(p.objectStore, ['implementation', 'mode', 'authentication', 'versioning', 'export', 'persistence', 'volumeSizeLimitMiB', 'volumeCount', 'filerStore', 'volumeGrowthCount']);
  requireValue(p.objectStore.implementation === 'seaweedfs' && p.objectStore.mode === 'single-node' && p.objectStore.authentication === 'required'
    && p.objectStore.versioning === false && p.objectStore.export === 'quiescent-s3-objects-and-metadata' && p.objectStore.persistence === 'master-volume-filer');
  requireValue(p.objectStore.filerStore === 'leveldb2' && p.objectStore.volumeGrowthCount === 1);
  integer(p.objectStore.volumeSizeLimitMiB, 1, 512); integer(p.objectStore.volumeCount, 1, 4);
  fields(p.sourceDelivery, ['server', 'scheme', 'readOnly', 'requiresMcpAuthentication']);
  requireValue(p.sourceDelivery.server === 'nextcloud-nginx' && p.sourceDelivery.scheme === 'https'
    && p.sourceDelivery.readOnly === true && p.sourceDelivery.requiresMcpAuthentication === false);
  fields(p.limits, ['cpus', 'memoryMiB', 'diskBudgetGiB', 'minimumFreeGiB', 'seedObjectMiB', 'runSeconds', 'readinessSeconds']);
  for (const [name, max] of Object.entries({ cpus: 4, memoryMiB: 8192, diskBudgetGiB: 30, seedObjectMiB: 256, runSeconds: 3600, readinessSeconds: 300 })) integer(p.limits[name], 1, max);
  integer(p.limits.minimumFreeGiB, 40, 1024);
  requireValue(p.limits.readinessSeconds <= p.limits.runSeconds);
  return p;
}

export async function readProfile(file) {
  let handle;
  try {
    // Reject special files before opening (FIFO reads must not hang), then prevent a symlink substitution.
    const stat = await fs.lstat(file);
    requireValue(stat.isFile() && stat.size <= 64 * 1024);
    handle = await fs.open(file, constants.O_RDONLY | constants.O_NOFOLLOW | constants.O_NONBLOCK);
    const opened = await handle.stat();
    requireValue(opened.isFile() && opened.size <= 64 * 1024);
    const bytes = Buffer.alloc(64 * 1024 + 1);
    const { bytesRead } = await handle.read(bytes, 0, bytes.length, 0);
    requireValue(bytesRead <= 64 * 1024);
    const content = bytes.subarray(0, bytesRead);
    return { profile: validateProfile(JSON.parse(content.toString('utf8'))), profileSha256: createHash('sha256').update(content).digest('hex') };
  } catch {
    throw new Error('Invalid profile: unreadable, unsupported, missing or unsafe field');
  } finally { await handle?.close(); }
}
