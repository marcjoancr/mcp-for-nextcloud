# SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Compare extracted/repacked bytes, modes, numeric owners and links before startup."""
import hashlib
import json
import sys
import tarfile
from volume_archive import validate


def inventory(filename):
    validate(filename)
    result = {}
    with tarfile.open(filename, 'r:') as archive:
        for entry in archive:
            name = entry.name.removeprefix('./').rstrip('/') or '.'
            content = hashlib.file_digest(archive.extractfile(entry), 'sha256').hexdigest() if entry.isfile() else None
            result[name] = (entry.type.decode('ascii'), entry.mode, entry.uid, entry.gid, entry.linkname, content)
    return result


if __name__ == '__main__':
    expected, actual = map(inventory, sys.argv[1:])
    if expected != actual:
        raise ValueError('Restored volume bytes, ownership, modes or links differ')
    print(json.dumps({'entries': len(expected), 'bytesAndOwnership': 'matched'}))
