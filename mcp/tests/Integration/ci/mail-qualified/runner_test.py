# SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
import json
import contextlib
import io
import os
import stat
from pathlib import Path
import subprocess
import sys
sys.dont_write_bytecode = True
import tempfile
import unittest
from unittest.mock import Mock, patch
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
            f.result = {'status': 'failed', 'failureType': 'RuntimeError', 'operatorEnabled': None}
            f.stage = 'extras'
            f.started = run.time.monotonic()
            f.inputs = {'manifestSha256': 'a' * 64, 'nativeManifestSha256': 'b' * 64}
            f.coverage = {}
            f.inventory_records = {}
            f.publish()
            self.assertEqual(set(run.PROFILE['artifactAllowlist']), {p.name for p in f.public.iterdir()})
            self.assertNotIn('PRIVATE_CANARY', ''.join(p.read_text() for p in f.public.iterdir()))
            self.assertTrue((private / 'raw.log').is_file())
            outcome=json.loads((f.public / 'result.json').read_text())
            self.assertEqual('failed',outcome['status'])
            self.assertIsNone(outcome['operatorEnabled'])

    def test_qualified_tool_actors_use_shipping_admission_and_child_commands(self):
        for kind in ['Delivery', 'Snooze', 'Sieve', 'SignatureMode']:
            source=(run.CI.parent / 'Mail' / ('Mail' + kind + 'ToolActor.php')).read_text()
            self.assertNotIn('FixtureBoundary::install()',source)
            self.assertTrue("Runner::class,'command'" not in source,kind + " must use its shipping child command")
            self.assertIn('MailCleanHttpActor::assertClean()',source)

    def test_each_off_probe_invalidates_prior_evidence_until_confirmed(self):
        f = run.Fixture.__new__(run.Fixture)
        f.names = {'nc': 'synthetic-nc'}
        f.result = {'operatorEnabled': None}
        f.docker = Mock(return_value=b'{"ok":true,"result":{"operatorEnabled":false}}')
        self.assertEqual({'operatorEnabled': False}, f.probe('off'))
        self.assertIs(f.result['operatorEnabled'], False)
        for failure in [RuntimeError('Synthetic transport failure'), b'{"ok":false}', b'{"ok":true,"result":{"operatorEnabled":true}}']:
            f.result['operatorEnabled'] = False
            if isinstance(failure, Exception):
                f.docker = Mock(side_effect=failure)
            else:
                f.docker = Mock(return_value=failure)
            with self.assertRaises(RuntimeError):
                f.probe('off')
            self.assertIsNone(f.result['operatorEnabled'], 'A failed observation cannot retain an earlier OFF claim')
            self.assertEqual(1, f.docker.call_count, 'The observation must never force configuration to satisfy itself')
            self.assertEqual('off', f.docker.call_args.args[-1])

    def test_public_source_directory_is_traversable_under_private_umask(self):
        with tempfile.TemporaryDirectory() as directory:
            f = run.Fixture.__new__(run.Fixture)
            base = Path(directory)
            f.private = base / 'private'
            f.tools = base / 'tools'
            f.kit = base / 'kit'
            f.native = base / 'native'
            for folder in [f.private, f.tools, f.kit, f.native]:
                folder.mkdir()
            f.private.chmod(0o700)
            f.tools.chmod(0o755)  # Mirror Fixture.__init__ before testing the nested source directory.
            secret = f.private / 'credential'
            secret.write_text('synthetic-private-canary')
            secret.chmod(0o600)
            f.names = {'nc': 'synthetic-nc'}
            f.sha = 'a' * 40
            f.result = {'operatorEnabled': None}
            f.inputs = {'manifestSha256': 'a' * 64, 'nativeManifestSha256': 'b' * 64}
            f.m = {'version': '2.8.0', 'files': {name: {} for name in ['BUILDING.md', 'source.bundle', 'runtime-dependencies.tar.gz', 'mcp-2.8.0.tar.gz']}}
            f.n = {'nativeManifestSha256': 'c' * 64, 'files': {name: {} for name in ['NATIVE-BUILDING.md', 'mail-upstream-runtime.tar.gz', 'mail-upstream-source.tar.gz', 'mail-reviewed-overlay.tar.gz', 'mail-assembled-runtime.tar.gz']}}
            for folder, manifest, filename in [(f.kit, f.m, 'manifest.json'), (f.native, f.n, 'native-manifest.json')]:
                for name in [*manifest['files'], filename]:
                    (folder / name).write_text('public-synthetic-asset')
            f.call = Mock()
            f.docker = Mock()
            f.occ = Mock()
            f.probe = Mock(side_effect=lambda action, payload: payload if action == 'downloads' else {})
            previous = os.umask(0o077)
            try:
                with patch.object(run.time, 'sleep'), contextlib.redirect_stdout(io.StringIO()):
                    f.services()
            finally:
                os.umask(previous)
            self.assertEqual(0o755, stat.S_IMODE((f.tools / 'source-offer').stat().st_mode))
            for asset in (f.tools / 'source-offer').iterdir():
                self.assertEqual(0o644, stat.S_IMODE(asset.stat().st_mode))
            self.assertEqual(0o700, stat.S_IMODE(f.private.stat().st_mode))
            self.assertEqual(0o700, stat.S_IMODE((f.private / 'delivery-cert').stat().st_mode))
            self.assertEqual(0o600, stat.S_IMODE(secret.stat().st_mode))

    def test_phase_timeout_or_copy_failure_cannot_publish_prior_off_evidence(self):
        for failure in ['timeout', 'copy', None]:
            with self.subTest(failure=failure), tempfile.TemporaryDirectory() as directory:
                f = run.Fixture.__new__(run.Fixture)
                base = Path(directory)
                f.tools = base / 'tools'
                f.private = base / 'private'
                f.tools.mkdir()
                f.private.mkdir()
                f.names = {'nc': 'synthetic-nc'}
                f.result = {'status': 'running', 'operatorEnabled': False}
                f.coverage = {}
                f.inventory_records = {}
                f.inventories = {}
                f.cat = run.catalog.load()
                f.test_env = Mock(return_value={})
                off = []
                calls = []
                def command(args, **kwargs):
                    calls.append(f.stage)
                    if f.stage == 'legacy' and failure == 'timeout':
                        raise subprocess.TimeoutExpired('synthetic-phpunit', 3600)
                    return subprocess.CompletedProcess(args, 0)
                def docker(*args, **kwargs):
                    if args[0] == 'cp':
                        if f.stage == 'legacy' and failure == 'copy':
                            raise RuntimeError('Synthetic JUnit copy failure')
                        return b''
                    if args[-1] == 'off':
                        off.append(f.stage)
                        return b'{"ok":true,"result":{"operatorEnabled":false}}'
                    return b'{"ok":true,"result":{}}'
                f.call = command
                f.docker = docker
                for name in ['verify_inputs', 'images', 'bootstrap', 'install_native', 'services', 'publish']:
                    setattr(f, name, Mock())
                descriptor = base / 'inputs.json'
                descriptor.write_text('{}')
                previous = os.umask(0o077)
                try:
                    with patch.object(run, 'Fixture', return_value=f), patch.object(run.sys, 'argv', ['run.py', '--inputs', str(descriptor)]), patch.object(run.catalog, 'result', return_value={'tests': 1, 'methods': 1, 'assertions': 1}), contextlib.redirect_stdout(io.StringIO()):
                        if failure:
                            with self.assertRaises(SystemExit) as error:
                                run.main()
                            self.assertEqual(1, error.exception.code)
                        else:
                            run.main()
                finally:
                    os.umask(previous)
                f.publish.assert_called_once()
                if failure:
                    self.assertEqual('failed', f.result['status'])
                    self.assertIsNone(f.result['operatorEnabled'])
                    self.assertEqual(['policy'], off)
                    self.assertEqual(['policy', 'legacy'], calls, 'An interrupted phase must never be replayed')
                else:
                    self.assertEqual('passed', f.result['status'])
                    self.assertIs(f.result['operatorEnabled'], False)
                    self.assertEqual(['policy', 'legacy', 'regression', 'core', 'extras', 'clean', 'source', 'projection'], off)

    def test_plan_has_no_ports_and_all_digest_pins(self):
        self.assertEqual([], run.PROFILE['publishedPorts'])
        self.assertEqual('linux/amd64', run.PROFILE['platform'])
        for pin in run.PROFILE['images'].values():
            self.assertRegex(pin, r'@sha256:[a-f0-9]{64}$')
        self.assertEqual('mail-qualified-nc35-pg16-s3-v11', run.PROFILE['id'])

if __name__ == '__main__':
    unittest.main()
