# SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Build immutable source kits with canonical release tools. No Docker effects."""
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import urllib.request

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[4]

def digest(path):
    with Path(path).open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()

def run(args):
    subprocess.run(args, cwd=ROOT, check=True)

def main():
    sha = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip()
    if subprocess.check_output(['git', 'status', '--porcelain', '--untracked-files=normal'], cwd=ROOT):
        raise RuntimeError('Immutable clean checkout required')
    profile = json.loads((HERE / 'profile.json').read_text())
    cache = Path(os.environ.get('XDG_CACHE_HOME', str(Path.home() / '.cache'))) / 'mcp-mail-qualified'
    cache.mkdir(parents=True, exist_ok=True)
    inputs = []
    for key in ['upstreamRuntime', 'preferredSource']:
        pin = profile[key]
        target = cache / (pin['sha256'] + '.tar.gz')
        if not target.exists():
            partial = target.with_suffix('.partial')
            with urllib.request.urlopen(pin['url'], timeout=120) as response, partial.open('xb') as out:
                while chunk := response.read(1024 * 1024):
                    out.write(chunk)
            if digest(partial) != pin['sha256']:
                raise RuntimeError('Upstream archive integrity mismatch')
            partial.replace(target)
        if digest(target) != pin['sha256']:
            raise RuntimeError('Cached upstream integrity mismatch')
        inputs.append(target)
    cli = ROOT / 'mcp/scripts/release/cli.mjs'
    build = ROOT / 'mcp/build'
    build.mkdir(exist_ok=True)
    os.environ['TMPDIR'] = str(build / 'qualified-tmp')
    Path(os.environ['TMPDIR']).mkdir(exist_ok=True)
    run(['node', str(cli), 'prepare', sha])
    kit = build / 'source' / sha
    md = digest(kit / 'manifest.json')
    native = build / ('mail-qualified-native-' + sha)
    run(['node', str(cli), 'verify', str(kit), md])
    run(['node', str(cli), 'native', str(kit), md, *map(str, inputs), str(native)])
    nd = digest(native / 'native-manifest.json')
    run(['node', str(cli), 'verify-native', str(native), nd, sha])
    descriptor = build / 'mail-qualified-inputs.json'
    if descriptor.exists():
        raise RuntimeError('Refusing to overwrite prior qualification input')
    descriptor.write_text(json.dumps({'schemaVersion': 1, 'sourceCommit': sha, 'kit': str(kit), 'manifestSha256': md, 'native': str(native), 'nativeManifestSha256': nd}, indent=2) + '\n')
    print(descriptor)

if __name__ == '__main__':
    main()
