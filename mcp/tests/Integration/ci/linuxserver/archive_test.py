# SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
import io
import os
import tarfile
import tempfile
import unittest
from volume_archive import validate


class VolumeArchiveTest(unittest.TestCase):
    def archive(self, names):
        with tempfile.TemporaryDirectory() as root:
            filename = os.path.join(root, 'volume.tar')
            with tarfile.open(filename, 'w') as archive:
                for name, kind, target in names:
                    entry = tarfile.TarInfo(name)
                    entry.type, entry.linkname = kind, target
                    entry.mode, entry.uid, entry.gid = 0o640, 1000, 1000
                    archive.addfile(entry, io.BytesIO())
            return validate(filename)

    def test_relative_links_and_ownership(self):
        result = self.archive([('a', tarfile.REGTYPE, ''), ('dir/link', tarfile.SYMTYPE, '../a'), ('hard', tarfile.LNKTYPE, 'a')])
        self.assertEqual(result['owners'], ['1000:1000'])
        self.assertEqual(result['links'], 2)

    def test_unsafe_archives(self):
        for entries in [[('../escape', tarfile.REGTYPE, '')], [('a', tarfile.SYMTYPE, '../../escape')],
                        [('a', tarfile.SYMTYPE, '/etc/passwd')], [('fifo', tarfile.FIFOTYPE, '')],
                        [('a', tarfile.SYMTYPE, 'b'), ('b', tarfile.SYMTYPE, 'a')],
                        [('a', tarfile.SYMTYPE, 'b'), ('a/file', tarfile.REGTYPE, '')],
                        [('www/nextcloud/apps/mcp/link', tarfile.SYMTYPE, 'file')],
                        [('www/nextcloud/apps/mcp', tarfile.SYMTYPE, 'other')],
                        [('root', tarfile.SYMTYPE, '.'), ('escape', tarfile.SYMTYPE, 'root/../outside')]]:
            with self.subTest(entries=entries), self.assertRaises(ValueError):
                self.archive(entries)


if __name__ == '__main__':
    unittest.main()
