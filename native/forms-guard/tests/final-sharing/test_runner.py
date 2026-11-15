# SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
import hashlib
import json
import subprocess
import sys
import importlib.util
import tempfile
import unittest
from pathlib import Path

spec = importlib.util.spec_from_file_location("final_sharing_runner", Path(__file__).with_name("run.py"))
runner = importlib.util.module_from_spec(spec)
if Path(spec.origin).exists():
    spec.loader.exec_module(runner)

class FixtureBoundaryTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix="forms-final-input-test-")
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.native = self.root / "native"
        self.native.mkdir()
        self.repo = self.root / "repo"
        self.repo.mkdir()
        (self.native / "lib").mkdir()
        (self.native / "lib" / "a.php").write_bytes(b"<?php // verified input")
        self.pins = {"lib/a.php": hashlib.sha256((self.native / "lib/a.php").read_bytes()).hexdigest()}

    def api(self, name):
        self.assertTrue(hasattr(runner, name), "Missing fixture boundary: " + name)
        return getattr(runner, name)

    def test_verified_native_bytes_are_retained_in_memory(self):
        verify = self.api("verified_native_inputs")
        actual = verify(self.native, self.pins)
        (self.native / "lib" / "a.php").write_bytes(b"changed after verification")
        self.assertEqual({"lib__a.php": b"<?php // verified input"}, actual)

    def test_missing_changed_or_symlink_source_refuses(self):
        verify = self.api("verified_native_inputs")
        f = self.native / "lib" / "a.php"
        f.write_bytes(b"modified")
        with self.assertRaises(RuntimeError): verify(self.native, self.pins)
        f.unlink()
        with self.assertRaises(RuntimeError): verify(self.native, self.pins)
        f.symlink_to(self.root / "foreign.php")
        with self.assertRaises(RuntimeError): verify(self.native, self.pins)

    def test_symlink_parent_and_source_root_refuse(self):
        verify = self.api("verified_native_inputs")
        alias = self.root / "alias"
        alias.symlink_to(self.native, target_is_directory=True)
        with self.assertRaises(RuntimeError): verify(alias, self.pins)
        (self.native / "lib" / "a.php").unlink()
        (self.native / "lib").rmdir()
        foreign = self.root / "outside"
        foreign.mkdir()
        (foreign / "a.php").write_bytes(b"<?php // verified input")
        (self.native / "lib").symlink_to(foreign, target_is_directory=True)
        with self.assertRaises(RuntimeError): verify(self.native, self.pins)

    def test_relative_escape_and_flattened_name_collision_refuse(self):
        verify = self.api("verified_native_inputs")
        for pins in [{"../outside.php": "0"*64}, {"/outside.php": "0"*64}, {"lib/a.php": "invalid"}, {"lib/a.php":"0"*64,"lib__a.php":"0"*64}]:
            with self.subTest(pins=pins), self.assertRaises(RuntimeError): verify(self.native, pins)
        with self.assertRaises(RuntimeError): verify(Path("relative-source"), self.pins)

    def test_invalid_sources_create_no_build_output(self):
        prepare = self.api("prepare_fixture")
        (self.native / "lib" / "a.php").write_bytes(b"changed")
        with self.assertRaises(RuntimeError): prepare(self.repo, self.native, self.pins, {}, {})
        self.assertFalse((self.repo / "mcp").exists())

    def test_fixture_is_private_and_preserves_source_bytes(self):
        prepare = self.api("prepare_fixture")
        src = self.repo / "owned.php"
        src.write_bytes(b"<?php // current production")
        fixture, inputs = prepare(self.repo, self.native, self.pins, {"src/Owned.php":"owned.php"}, {"sample.php":b"<?php // synthetic test"})
        self.assertTrue(fixture.is_relative_to(self.repo / "mcp/build/forms-native/tests/final-sharing"))
        self.assertEqual(0o700, fixture.stat().st_mode & 0o777)
        self.assertEqual(b"<?php // current production", (fixture/"src/Owned.php").read_bytes())
        self.assertEqual(0o600, (fixture/"src/Owned.php").stat().st_mode & 0o777)
        self.assertEqual(b"<?php // verified input", (fixture/"references/lib__a.php").read_bytes())
        (fixture/"src/Owned.php").write_bytes(b"synthetic mutant")
        self.assertEqual(b"<?php // current production", src.read_bytes())
        self.assertEqual(self.repo, inputs[0][0].parent)

    def test_existing_symlink_build_is_never_followed(self):
        prepare = self.api("prepare_fixture")
        (self.repo/"mcp").mkdir()
        foreign=self.root/"foreign-build"
        foreign.mkdir()
        (self.repo/"mcp/build").symlink_to(foreign, target_is_directory=True)
        with self.assertRaises(RuntimeError): prepare(self.repo, self.native, self.pins, {}, {})
        self.assertEqual([], list(foreign.iterdir()))

    def cli_scenario(self, child):
        repo = self.root / ("scenario-" + str(len(list(self.root.glob("scenario-*")))))
        repo.mkdir()
        for name in ["mcp/composer.lock", "mcp/vendor/autoload.php", "mcp/vendor/bin/phpunit", "mcp/tests/bootstrap.php"]:
            path=repo/name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text("synthetic prerequisite; never executed")
        production=repo/"native/forms-guard/src/Service/FinalShareSources.json"
        production.parent.mkdir(parents=True)
        production.write_text(json.dumps(self.pins))
        tests=repo/"native/forms-guard/tests/final-sharing"
        tests.mkdir(parents=True)
        (tests/"run.py").write_bytes(Path(spec.origin).read_bytes())
        (tests/"native-test-inputs.json").write_text(json.dumps(self.pins))
        (tests/"source-layout.json").write_text(json.dumps({"src/Owned.php":"owned.php"}))
        (tests/"test-sources.json").write_text(json.dumps(["run-covering.py"]))
        (tests/"run-covering.py").write_text(child)
        (repo/"owned.php").write_bytes(b"<?php // current production")
        result=subprocess.run([sys.executable,"-B",str(tests/"run.py"),"--native-source-root",str(self.native)],capture_output=True,umask=0o022,timeout=30)
        runs=list((repo/"mcp/build/forms-native/tests/final-sharing").glob("run-*"))
        self.assertEqual(1,len(runs))
        return result,runs[0],repo

    def test_cli_enforces_child_private_modes_under_a_permissive_parent_umask(self):
        child="from pathlib import Path\np=Path(__file__).with_name('born.log')\np.write_text('synthetic output')\nraise SystemExit(0 if p.stat().st_mode & 0o777 == 0o600 else 17)\n"
        result,run,_=self.cli_scenario(child)
        self.assertEqual(0,result.returncode,result.stderr.decode())
        self.assertEqual(0o600,(run/"tests/born.log").stat().st_mode&0o777)

    def test_cli_refuses_mutated_or_added_executable_base_inputs_with_originals_intact(self):
        for target in ["src/Owned.php","references/lib__a.php","src/Added.php"]:
            with self.subTest(target=target):
                child="from pathlib import Path\n(Path(__file__).resolve().parent.parent/"+repr(target)+").write_bytes(b'changed base input')\n"
                result,run,repo=self.cli_scenario(child)
                self.assertNotEqual(0,result.returncode,"A modified private input produced a green run")
                receipt=json.loads((run/"run-receipt.json").read_text())
                self.assertTrue(receipt["source_unchanged"])
                self.assertFalse(receipt["fixture_unchanged"])
                self.assertEqual(b"<?php // current production",(repo/"owned.php").read_bytes())

if __name__ == "__main__":
    unittest.main()
