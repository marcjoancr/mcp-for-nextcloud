#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Prepare fixed offline inputs for the LinuxServer update-protection trial.

Fetch only on the host, before the isolated run:
  https://apps.nextcloud.com/api/v1/platform/35.0.2/apps.json
  https://github.com/nextcloud-releases/notes/releases/download/v6.0.0/notes-v6.0.0.tar.gz
  https://github.com/nextcloud-releases/notes/releases/download/v6.1.0/notes-v6.1.0.tar.gz

Save the catalog as <inputs>/protection/apps-control-source.json and the archives
as <inputs>/protection/apps/notes-{6.0.0,6.1.0}.tar.gz, then invoke this script
with <inputs>. It writes only reproducible catalogs, signature material and pins
under inputs/protection. The catalog and archive SHA-256 pins are fixed below.
The runtime consumes those files from an isolated static server and has no egress.
"""
from __future__ import annotations

import argparse
import base64
import hashlib
import json
import pathlib
import subprocess

CATALOG_URL = 'https://apps.nextcloud.com/api/v1/platform/35.0.2/apps.json'
ARCHIVE_URLS = {
    '6.0.0': 'https://github.com/nextcloud-releases/notes/releases/download/v6.0.0/notes-v6.0.0.tar.gz',
    '6.1.0': 'https://github.com/nextcloud-releases/notes/releases/download/v6.1.0/notes-v6.1.0.tar.gz',
}
PINS = {
    'catalog': '730f4bbb1c0678a271f11ea41b967312cca81265af68ee5cafec52a6b448db1c',
    'archive': {
        '6.0.0': 'c62017cbb09e9d8355c60bbd2af5531f75c22632e946aab15eff9852c39a6fdd',
        '6.1.0': '03c493f45cb17a5005a0dce91c4e59b294ef91d4520c3ef44a21d5e61e419526',
    },
    'certificate': 'db732d6a81d5ae2240fbd2035788149a13d88a0a87613eb426b424eccff15fea',
    'publicKey': '9d00eb86c9c46a6498c36cb7ef25d173fa3aea815c8718205a8ff14adad6984f',
    'signature': {
        '6.0.0': '1b519430c2900bb621d7efd52dc29ca0f27d9dbc06242f4b03dd177df444fc4a',
        '6.1.0': 'a1e11ebc70e8ccdbe312d70fa47035574c7b3352d049244a4f275d0fcab81d33',
    },
}


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def checked_digest(file: pathlib.Path, expected: str) -> bytes:
    data = file.read_bytes()
    if sha256(data) != expected:
        raise ValueError(f'Pinned SHA-256 mismatch: {file.name}')
    return data


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('input_dir', type=pathlib.Path, help='private LinuxServer inputs directory')
    args = parser.parse_args()
    root = args.input_dir.resolve() / 'protection'
    apps = root / 'apps'
    catalogs = root / 'catalogs'
    root.mkdir(mode=0o700, parents=True, exist_ok=True)
    apps.mkdir(mode=0o700, exist_ok=True)
    catalog_bytes = checked_digest(root / 'apps-control-source.json', PINS['catalog'])
    rows = json.loads(catalog_bytes)
    by_id = {row['id']: row for row in rows}
    notes_original = by_id['notes']
    mcp_original = by_id['mcp']
    notes_releases = {row['version']: dict(row) for row in notes_original['releases']}
    notes_meta = dict(notes_original)

    cert = notes_original['certificate'].encode()
    if sha256(cert) != PINS['certificate']:
        raise ValueError('Public Notes signing certificate does not match its pin')
    cert_path = root / 'notes-cert.pem'
    key_path = root / 'notes-public.pem'
    cert_path.write_bytes(cert)
    subprocess.run(['openssl', 'x509', '-in', str(cert_path), '-pubkey', '-noout', '-out', str(key_path)], check=True)
    if sha256(key_path.read_bytes()) != PINS['publicKey']:
        raise ValueError('Derived Notes public key does not match its pin')

    signed = {}
    for version, url in ARCHIVE_URLS.items():
        archive_path = apps / f'notes-{version}.tar.gz'
        archive = checked_digest(archive_path, PINS['archive'][version])
        release = notes_releases[version]
        if release['download'] != url or release.get('signatureDigest') != 'sha512':
            raise ValueError(f'App-store release metadata differs for Notes {version}')
        signature = base64.b64decode(release['signature'], validate=True)
        signature_path = root / f'notes-{version}.sig'
        signature_path.write_bytes(signature)
        if sha256(signature) != PINS['signature'][version]:
            raise ValueError(f'App-store archive signature differs for Notes {version}')
        verified = subprocess.run(['openssl', 'dgst', '-sha512', '-verify', str(key_path),
                                   '-signature', str(signature_path), str(archive_path)],
                                  check=False, capture_output=True, text=True)
        if verified.returncode or 'Verified OK' not in verified.stdout:
            raise ValueError(f'Official store signature verification failed for Notes {version}')
        release['download'] = f'http://127.0.0.1:18765/apps/notes-{version}.tar.gz'
        signed[version] = {
            'archive': f'apps/notes-{version}.tar.gz', 'sha256': sha256(archive),
            'storeSignatureSha256': sha256(signature), 'signatureDigest': 'sha512',
            'certificateSha256': sha256(cert), 'signatureVerified': True,
            'storeDownload': url,
        }

    notes_stable = dict(notes_meta, releases=[notes_releases['6.0.0'], notes_releases['6.1.0']])
    notes_unstable = dict(notes_meta, releases=[notes_releases['6.0.0'], notes_releases['6.1.0'], notes_releases['6.1.0-beta.3']])
    base_release = dict(next(row for row in mcp_original['releases'] if row['version'] == '1.2.4'))
    synthetic_base = dict(base_release, download='http://127.0.0.1:18765/never-download-synthetic-mcp.tar.gz',
                          rawPlatformVersionSpec='>=35 <=35', platformVersionSpec='>=35.0.0 <36.0.0')
    synthetic_stable = dict(synthetic_base, version='2.1.0')
    synthetic_unstable = dict(synthetic_base, version='2.2.0-beta.1', isNightly=False)
    mcp_stable = dict(mcp_original, releases=[synthetic_stable])
    mcp_unstable = dict(mcp_original, releases=[synthetic_stable, synthetic_unstable])
    output_catalogs = {
        'install-old': [dict(notes_meta, releases=[notes_releases['6.0.0']])],
        'control-stable': [notes_stable, mcp_stable],
        'control-unstable': [notes_unstable, mcp_unstable],
        'filtered-stable': [notes_stable],
        'filtered-unstable': [notes_unstable],
    }
    catalog_pins = {}
    for name, catalog in output_catalogs.items():
        directory = catalogs / name
        directory.mkdir(mode=0o755, parents=True, exist_ok=True)
        payload = (json.dumps(catalog, ensure_ascii=False, separators=(',', ':')) + '\n').encode()
        (directory / 'apps.json').write_bytes(payload)
        catalog_pins[name] = {'sha256': sha256(payload), 'bytes': len(payload)}

    manifest = {
        'schemaVersion': 1,
        'catalogSource': {'url': CATALOG_URL, 'sha256': sha256(catalog_bytes), 'bytes': len(catalog_bytes)},
        'notes': signed,
        'syntheticMcpControl': {
            'version': '2.1.0', 'unstableVersion': '2.2.0-beta.1',
            'catalogOnly': True, 'downloadAttemptAllowed': False, 'signatureClaim': False,
        },
        'catalogs': catalog_pins,
    }
    (root / 'manifest.json').write_text(json.dumps(manifest, indent=2) + '\n')
    print(json.dumps({'output': str(root), 'manifestSha256': sha256((root / 'manifest.json').read_bytes()),
                      'catalogs': catalog_pins, 'notes': signed}, indent=2))


if __name__ == '__main__':
    main()
