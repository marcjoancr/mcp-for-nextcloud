# SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Extract only regular files/directories from an already hash-verified app archive."""
import pathlib
import sys
import tarfile

archive, destination, root = sys.argv[1:]
base = pathlib.Path(destination)
with tarfile.open(archive, 'r:gz') as tar:
    members = tar.getmembers()
    total = 0
    seen = set()
    for member in members:
        path = pathlib.PurePosixPath(member.name)
        if (path.is_absolute() or '..' in path.parts or not path.parts
                or path.parts[0] != root or member.issym() or member.islnk()
                or not (member.isfile() or member.isdir()) or member.name in seen):
            raise ValueError('Unsafe archive member')
        seen.add(member.name)
        total += member.size
        if total > 512 * 1024 * 1024 or len(seen) > 50000:
            raise ValueError('Archive exceeds extraction budget')
    tar.extractall(base, members=members, filter='data')
# Normalize archive permissions; never preserve executable/set-ID surprises.
for path in base.rglob('*'):
    path.chmod(0o755 if path.is_dir() else 0o644)
