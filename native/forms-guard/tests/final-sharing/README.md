# Final sharing regression tests

From the repository root, with PHP/Composer development dependencies installed:

```sh
python3 -B native/forms-guard/tests/final-sharing/test_runner.py
python3 -B native/forms-guard/tests/final-sharing/run.py --native-source-root /absolute/offline/nextcloud --mutants
```

The explicit offline source tree must match every pinned Nextcloud35.0.2 input, including optional bundled app and dependency sources. The runner verifies every native source hash against the production profile, retains the verified bytes, and copies them with upstream notices into a new private run below `mcp/build/forms-native/tests/final-sharing/`. Missing or changed input fails; nothing is downloaded, no native application is bootstrapped, and no test is silently skipped. Third-party source copies and outputs are generated inputs, never committed dependencies.

Each covering family runs in a separate process to isolate its synthetic collaborators and source loaders. PHPUnit families and standalone checks cover bounded row sets, expiry, group lifetime/leases/listener factories and complete declarations without method execution. The optional deliberate invariant breaks must fail through assertions. Tests exercise actual native algorithms on synthetic input; SQL engines, concurrency, rollback, lifecycle bootstrap and storage/home parity need separate disposable-cell qualification.

The layout copies current production files and records their hashes before tests; every child is followed by verification of the exact executed code and native reference copies, including added executable files; final verification also requires production originals unchanged. Child outputs use a restrictive umask independently of the caller. Assertion bodies and mutants retain the reviewed R1 behavior. A directory-wide PHPUnit invocation is unsuitable because isolated classes intentionally share names. Use the runner. No credentials or real account, operation or cell IDs are required.
