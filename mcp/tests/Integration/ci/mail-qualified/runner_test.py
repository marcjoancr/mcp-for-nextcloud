# SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
import json
from pathlib import Path
import subprocess
import sys
sys.dont_write_bytecode = True
import tempfile
import unittest
from unittest.mock import Mock
import run

class RunnerTest(unittest.TestCase):
    def test_dirty_source_stops_before_any_docker_action(self):
        f = run.Fixture.__new__(run.Fixture)
        f.sha = 'a' * 40
        f.call = Mock(side_effect=[subprocess.CompletedProcess([], 0, (f.sha + '\n').encode()), subprocess.CompletedProcess([], 0, b' M shipping.php\n')])
        f.docker = Mock(side_effect=AssertionError('No Docker action authorized'))
        with self.assertRaises(RuntimeError):
            f.verify_inputs()
        f.docker.assert_not_called()

    def test_cleanup_refuses_a_resource_owned_by_another_run(self):
        f = run.Fixture.__new__(run.Fixture)
        f.run = 'mcp-it-ours'
        f.names = {'nc': 'mcp-it-ours-nc'}
        f.volumes = []
        f.net = 'mcp-it-ours-net'
        f.call = Mock(return_value=subprocess.CompletedProcess([], 0, json.dumps([{'Config': {'Labels': {'mcp.integration.run': 'someone-else'}}}]).encode()))
        f.docker = Mock()
        with self.assertRaises(RuntimeError):
            f.cleanup()
        f.docker.assert_not_called()

    def test_publication_cannot_copy_private_sql_or_error_payloads(self):
        with tempfile.TemporaryDirectory() as directory:
            f = run.Fixture.__new__(run.Fixture)
            f.public = Path(directory) / 'public'
            f.public.mkdir()
            private = Path(directory) / 'private'
            private.mkdir()
            (private / 'raw.log').write_text('AUTH_PRIVATE_CANARY MIME_PRIVATE_CANARY SQL_PRIVATE_CANARY')
            f.result = {'status': 'failed', 'failureType': 'RuntimeError'}
            f.stage = 'extras'
            f.started = run.time.monotonic()
            f.inputs = {'manifestSha256': 'a' * 64, 'nativeManifestSha256': 'b' * 64}
            f.coverage = {}
            f.inventory_records = {}
            f.publish()
            self.assertEqual(set(run.PROFILE['artifactAllowlist']), {p.name for p in f.public.iterdir()})
            self.assertNotIn('PRIVATE_CANARY', ''.join(p.read_text() for p in f.public.iterdir()))
            self.assertTrue((private / 'raw.log').is_file())

    def test_qualified_tool_actors_use_shipping_admission_and_child_commands(self):
        for kind in ['Delivery', 'Snooze', 'Sieve', 'SignatureMode']:
            source=(run.CI.parent / 'Mail' / ('Mail' + kind + 'ToolActor.php')).read_text()
            self.assertNotIn('FixtureBoundary::install()',source)
            self.assertTrue("Runner::class,'command'" not in source,kind + " must use its shipping child command")
            self.assertIn('MailCleanHttpActor::assertClean()',source)

    def test_plan_has_no_ports_and_all_digest_pins(self):
        self.assertEqual([], run.PROFILE['publishedPorts'])
        self.assertEqual('linux/amd64', run.PROFILE['platform'])
        for pin in run.PROFILE['images'].values():
            self.assertRegex(pin, r'@sha256:[a-f0-9]{64}$')
        self.assertEqual('mail-qualified-nc35-pg16-s3-v11', run.PROFILE['id'])

if __name__ == '__main__':
    unittest.main()
