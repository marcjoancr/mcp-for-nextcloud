<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
# Qualified Mail CI

`Mail qualified / NC35 PG16 S3` is a mandatory job in `integration.yml`. The ordinary matrix keeps its stock compatibility purpose. Its explicit Mail file list cannot discover qualified v11 classes. `catalog.json` assigns every Mail test file and every test method to a required qualification phase; adding or removing a method requires an intentional catalog update. Qualified JUnit evidence must contain every assigned method, with no skipped, incomplete, failed or empty tests. Parameterized cases are all executed by PHPUnit. Each test failure stops the run and retains its state rather than repeating an uncertain effect.

The separate profile uses pinned LinuxServer Nextcloud 35.0.2, PostgreSQL 16, MinIO S3 and Dovecot images. It does not change the historical LinuxServer 1.2→2.0 profiles. `mail-cell.sh prepare` provisions stock Mail, Forms, IMAP and authentication peers. A dedicated synthetic legacy owner is synchronized by stock Mail before the complete v11 runtime is installed and migrated. The original message columns and IDs must survive, and new provenance fields must remain NULL. Only then does `mail-cell.sh accounts` create and synchronize Alice and Bob through the new native implementation. No SQL generation stamping or legacy account reset is used.

The native companion is reconstructed by the canonical source tool from the same frozen MCP source bundle. Its complete assembled runtime replaces the stock tree; the old tree is retained. The runner verifies both complete installed inventories, exact source metadata, all available migrations, the native fingerprints, open shipping qualification flags and the S3/provider requirements. The actor tests use the registered shipping boundary and signed child commands. Transport and checkpoint fault seams remain deliberate test inputs. Ordinary OAuth HTTP, source offers, all eleven anonymous asset downloads and concurrent projection revocation have explicit phases. Full inventories are checked before and after the clean and projection phases. The transmission gate starts false and is verified false after each phase; only the existing synthetic delivery helper may temporarily enable its loopback recording sink.

Build and execution are separate. On a clean exact checkout with PHP 8.2+, Composer, Node 24.22.1 and npm 11.19.1:

```sh
python3 -B mcp/tests/Integration/ci/mail-qualified/catalog_test.py
python3 -B mcp/tests/Integration/ci/mail-qualified/runner_test.py
python3 -B mcp/tests/Integration/ci/mail-qualified/run.py --plan
python3 -B mcp/tests/Integration/ci/mail-qualified/prepare.py
```

The last command builds the canonical five-file MCP source kit and six-file native companion and writes `mcp/build/mail-qualified-inputs.json`. It neither installs nor publishes them. Existing reviewed kits can instead be supplied in a descriptor with this exact schema (absolute paths must be under that execution checkout’s `mcp/build/`):

```json
{
  "schemaVersion": 1,
  "sourceCommit": "FULL_40_CHARACTER_COMMIT",
  "kit": "/absolute/path/to/mcp-kit",
  "manifestSha256": "TRUSTED_MCP_MANIFEST_SHA256",
  "native": "/absolute/path/to/native-companion",
  "nativeManifestSha256": "TRUSTED_NATIVE_MANIFEST_SHA256"
}
```

On the same clean checkout, run the disposable fixture explicitly:

```sh
python3 -B mcp/tests/Integration/ci/mail-qualified/run.py --inputs mcp/build/mail-qualified-inputs.json --cleanup
```

Execution needs Python 3.12+, Docker, PHP CLI, Node 24.22.1, Git, curl and OpenSSL. It does not need Composer or npm. PHP/Node may be private toolchain wrappers; the canonical verifiers and source checks still run unchanged. No production configuration, credentials, volumes or ports are inputs. No host ports are published. S3/admin/database credentials and TLS keys are generated at the destination. The existing IMAP authentication-only fixture constants are synthetic and scoped to its disposable network.

Without `--cleanup`, a successful fixture is retained. A failed fixture is always retained for diagnosis. Explicit successful cleanup checks the exact run label before removing its resources. No daemon-wide prune or unrelated cleanup occurs. The runner emits only phase names and counts. Its sole upload directory is `qualified-evidence/`, with exactly `result.json`, `coverage.json`, `inventories.json`, `downloads.json` and `test-summary.log`. Raw PHPUnit/JUnit traces, SQL statement tapes, MIME, credentials, certificates, configurations and source server logs remain private and are never passed to artifact upload. A local or remote rehearsal must be recorded as pending until this real profile finishes successfully; offline validator tests alone are not native qualification.
