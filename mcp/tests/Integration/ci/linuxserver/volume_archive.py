# SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Inspect a volume archive without extracting; preserve legitimate in-volume links."""
import json
import posixpath
import sys
import tarfile


def validate(filename):
    entries = {}
    with tarfile.open(filename, 'r:') as archive:
        for entry in archive:
            name = entry.name.removeprefix('./').rstrip('/')
            if name in ('', '.') and entry.isdir():
                continue
            if not name or name.startswith('/') or '..' in name.split('/') or name in entries or posixpath.normpath(name) != name:
                raise ValueError('Unsafe or duplicate archive path')
            if not (entry.isdir() or entry.isfile() or entry.issym() or entry.islnk()):
                raise ValueError('Special archive entry')
            if entry.mode & 0o6000:
                raise ValueError('Privileged archive mode')
            if (name == 'www/nextcloud/apps/mcp' or name.startswith('www/nextcloud/apps/mcp/')) and (entry.issym() or entry.islnk()):
                raise ValueError('Installed MCP content must not contain links')
            entries[name] = entry
        def resolve(name, stack=(), chain=()):
            # Expand symlinks before applying subsequent '..', as the filesystem does.
            # Lexical normpath first would turn root -> '.' plus root/../outside into an escape.
            resolved = list(stack)
            for component in name.split('/'):
                if component in ('', '.'):
                    continue
                if component == '..':
                    if not resolved:
                        raise ValueError('Escaping archive link')
                    resolved.pop()
                    continue
                prefix = '/'.join(resolved + [component])
                entry = entries.get(prefix)
                if entry and (entry.issym() or entry.islnk()):
                    if prefix in chain or len(chain) > 40:
                        raise ValueError('Archive link cycle')
                    if entry.linkname.startswith('/'):
                        raise ValueError('Escaping archive link')
                    base = tuple(resolved) if entry.issym() else ()
                    target = resolve(entry.linkname, base, chain + (prefix,))
                    resolved = target.split('/') if target else []
                else:
                    resolved.append(component)
            return '/'.join(resolved)
        for name, entry in entries.items():
            resolved = resolve(name)
            if entry.islnk() and (resolved not in entries or not entries[resolved].isfile()):
                raise ValueError('Invalid hard link')
            # A descendant beneath a link could overwrite an unrelated archived path on extraction.
            if resolve(posixpath.dirname(name)) != posixpath.dirname(name):
                raise ValueError('Archive entry beneath a link')
    return {'entries': len(entries), 'links': sum(e.issym() or e.islnk() for e in entries.values()), 'owners': sorted({f'{e.uid}:{e.gid}' for e in entries.values()})}


if __name__ == '__main__':
    print(json.dumps(validate(sys.argv[1])))
