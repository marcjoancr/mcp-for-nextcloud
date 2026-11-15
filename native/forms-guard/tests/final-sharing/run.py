#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Run isolated synthetic tests against verified offline native source inputs."""
import argparse
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import stat
import subprocess
import sys
import tempfile


def digest(data):
    return hashlib.sha256(data).hexdigest()


def relative_name(name):
    if not isinstance(name, str):
        raise RuntimeError("Invalid fixture path")
    path = PurePosixPath(name)
    if not name or path.is_absolute() or ".." in path.parts or str(path) != name or "\\" in name:
        raise RuntimeError("Invalid fixture path")
    return name


def regular_path(path, directory=False):
    path = Path(path)
    if not path.is_absolute() or ".." in path.parts:
        raise RuntimeError("An absolute regular path is required")
    try:
        for component in list(path.parents)[::-1] + [path]:
            mode = component.lstat().st_mode
            if stat.S_ISLNK(mode):
                raise RuntimeError("Symlink fixture input refused")
            if component != path and not stat.S_ISDIR(mode):
                raise RuntimeError("Invalid fixture input parent")
        mode = path.lstat().st_mode
        if not (stat.S_ISDIR(mode) if directory else stat.S_ISREG(mode)):
            raise RuntimeError("Fixture input has an invalid type")
    except OSError as error:
        raise RuntimeError("Fixture input is missing or inaccessible") from error
    return path


def read_regular(path):
    path = regular_path(path)
    try:
        descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
        with os.fdopen(descriptor, "rb") as stream:
            info = os.fstat(stream.fileno())
            if not stat.S_ISREG(info.st_mode) or info.st_size > 8 * 1024 * 1024:
                raise RuntimeError("Fixture input is not a bounded regular file")
            data = stream.read(8 * 1024 * 1024 + 1)
    except OSError as error:
        raise RuntimeError("Fixture input cannot be read") from error
    if len(data) > 8 * 1024 * 1024:
        raise RuntimeError("Fixture input exceeded its byte bound")
    return data


def verified_native_inputs(root, pins):
    root = regular_path(root, directory=True)
    flat_names = set()
    for name, expected in pins.items():
        relative_name(name)
        flat = name.replace("/", "__")
        if flat in flat_names or not isinstance(expected, str) or re.fullmatch(r"[0-9a-f]{64}", expected) is None:
            raise RuntimeError("Invalid or colliding native source pin")
        flat_names.add(flat)
    output = {}
    total = 0
    for name, expected in pins.items():
        data = read_regular(root / name)
        if digest(data) != expected:
            raise RuntimeError("Native source pin mismatch: " + name)
        total += len(data)
        if total > 16 * 1024 * 1024:
            raise RuntimeError("Native source input budget exceeded")
        # Retain the verified read; never reopen a source after checking its hash.
        output[name.replace("/", "__")] = data
    return output


def private_directory(path):
    path = Path(path)
    if not path.exists() and not path.is_symlink():
        private_directory(path.parent)
        path.mkdir(mode=0o700)
    regular_path(path, directory=True)


def private_write(path, data):
    private_directory(path.parent)
    with os.fdopen(os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600), "wb") as stream:
        if stream.write(data) != len(data):
            raise RuntimeError("Incomplete fixture copy")
    if digest(read_regular(path)) != digest(data):
        raise RuntimeError("Fixture copy verification failed")


def prepare_fixture(repository, native_root, pins, layout, tests):
    repository = regular_path(repository, directory=True)
    native = verified_native_inputs(native_root, pins)
    owned = {}
    originals = []
    for target, source in layout.items():
        relative_name(target)
        relative_name(source)
        path = repository / source
        data = read_regular(path)
        owned[target] = data
        originals.append((path, digest(data)))
    for name in tests:
        relative_name(name)
    # All external source pins and source copies were checked before creating output.
    parent = repository / "mcp/build/forms-native/tests/final-sharing"
    private_directory(parent)
    run = Path(tempfile.mkdtemp(prefix="run-", dir=parent))
    for folder in ["src", "adapters", "standalone", "migration", "references", "tests"]:
        private_directory(run / folder)
    for target, data in owned.items():
        private_write(run / target, data)
    for name, data in native.items():
        private_write(run / "references" / name, data)
    for name, data in tests.items():
        private_write(run / "tests" / name, data)
    php_root = str(repository).replace("\\", "\\\\").replace("'", "\\'")
    private_write(run / "tests/repository-root.php", ("<?php return '" + php_root + "';\n").encode())
    private_write(run / "tests/repository_root.py", ("from pathlib import Path\nREPOSITORY_ROOT = Path(" + repr(str(repository)) + ")\n").encode())
    receipt = {"native_files": len(native), "source_inputs": [{"path": str(path.relative_to(repository)), "sha256": sha} for path, sha in originals]}
    private_write(run / "source-receipt.json", (json.dumps(receipt, indent=2) + "\n").encode())
    return run, originals


def fixture_inputs(run):
    inputs = {}
    for folder in ["src", "adapters", "standalone", "migration", "references", "tests"]:
        for path in (run / folder).rglob("*"):
            if path.is_symlink():
                raise RuntimeError("Unexpected symlink in executable fixture")
            if path.is_file() and (folder != "tests" or path.suffix in [".php", ".py"]):
                inputs[str(path.relative_to(run))] = digest(read_regular(path))
    inputs["source-receipt.json"] = digest(read_regular(run / "source-receipt.json"))
    return inputs


def fixture_matches(run, expected):
    try:
        return fixture_inputs(run) == expected
    except RuntimeError:
        return False


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--native-source-root", type=Path, required=True,
                        help="Absolute offline Nextcloud35.0.2 source tree; never bootstrapped")
    parser.add_argument("--mutants", action="store_true", help="Also verify the18 deliberate invariant breaks")
    args = parser.parse_args()
    test_dir = Path(__file__).resolve().parent
    repository = regular_path(test_dir.parents[3], directory=True)
    for required in ["mcp/composer.lock", "mcp/vendor/autoload.php", "mcp/vendor/bin/phpunit", "mcp/tests/bootstrap.php"]:
        regular_path(repository / required)
    pins = json.loads(read_regular(test_dir / "native-test-inputs.json"))
    production_pins = json.loads(read_regular(repository / "native/forms-guard/src/Service/FinalShareSources.json"))
    if pins != production_pins:
        raise RuntimeError("Native test pins differ from the production source profile")
    layout = json.loads(read_regular(test_dir / "source-layout.json"))
    test_names = json.loads(read_regular(test_dir / "test-sources.json"))
    tests = {relative_name(name): read_regular(test_dir / name) for name in test_names}
    run, originals = prepare_fixture(repository, args.native_source_root, pins, layout, tests)
    expected_inputs = fixture_inputs(run)
    commands = [[sys.executable, str(run / "tests/run-covering.py")]]
    if args.mutants:
        commands.append([sys.executable, str(run / "tests/run-mutants.py")])
    receipts = []
    try:
        for command in commands:
            process = subprocess.run(command, cwd=run, capture_output=True, timeout=600, umask=0o077)
            output = process.stdout + process.stderr
            name = Path(command[-1]).stem
            private_write(run / (name + ".log"), output)
            receipts.append({"command": name, "exit_code": process.returncode, "output_sha256": digest(output)})
            print(json.dumps(receipts[-1]), flush=True)
            if not fixture_matches(run, expected_inputs):
                raise RuntimeError("Executable fixture inputs changed during test execution")
            if process.returncode:
                raise RuntimeError("Final sharing test family failed; inspect its private run log")
    finally:
        unchanged = all(digest(read_regular(path)) == sha for path, sha in originals)
        copies_unchanged = fixture_matches(run, expected_inputs)
        private_write(run / "run-receipt.json", (json.dumps({"commands": receipts, "source_unchanged": unchanged, "fixture_unchanged": copies_unchanged}, indent=2) + "\n").encode())
        if not unchanged or not copies_unchanged:
            raise RuntimeError("Production source or executable fixture changed during test execution")
    print(json.dumps({"ok": True, "run": str(run), "native_files": len(pins), "source_unchanged": True, "fixture_unchanged": True}))


if __name__ == "__main__":
    main()
