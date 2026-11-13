# Native Forms guard

This companion is a source overlay for Nextcloud Forms **5.4.2**, upstream commit `066573fd592513a06f8b8624def8244e911efdfd`. It is separately installed with Forms; it is not part of the MCP application archive. The current admitted cells are Nextcloud 35.0.2 with PostgreSQL 16.15 or MariaDB 10.11.19. Other versions and SQLite are not admitted for guarded MCP writes.

## Prepare and install

Extract the official installable Forms 5.4.2 release archive, including its vendor dependencies. Run:

```sh
node native/forms-guard/prepare.mjs /absolute/extracted/forms /absolute/new/output/forms
```

Preparation verifies every pinned executable/configuration input, rejects extra executable sources and symlinks, preserves native source and validation, wraps the enumerated writer boundaries, installs companion classes, lints generated PHP and emits `mcp-guard-installed.json` with exact installed hashes. The output directory must not exist. The source remains untouched. The output is a modified, unsigned Forms distribution: the upstream signature does not authenticate the overlay. Distribution must include this companion source and its matching pinned upstream source offer; it must not claim to be an unmodified upstream release.

Install only during an authorized Forms maintenance window with a verified database and file backup. Run the normal app migration lifecycle after placing the overlay, even if Forms 5.4.2 was already enabled: disable and re-enable Forms while traffic is stopped. This applies `Version050402Date20261113000000`. Do not edit an active installation in place while requests/jobs run. Rollback requires maintenance and a reviewed restoration of the previous complete Forms code; companion tables retain historical evidence and must not be blindly dropped. This repository's preparation command does not deploy, publish or modify a live installation.

## Contract

`OCA\Forms\Mcp\Guard` provides `capabilities()`, `snapshot(uid, action, input)`, `apply(uid, action, input, revision, operationKey)`, `read(uid, action, input)` and `lookup(uid, operationKey)`. Calls require the same enabled native session actor. The MCP layer remains responsible for actor-incarnation/connection binding, tool selection, grants, authorization of historical receipt access, bounded model-visible projections and secret redaction. `snapshot` returns `revision`, `form`, `config`, `summary` and `impact`, refuses metadata over 1 MiB, and neither locks nor mutates native state. Raw trusted native data must never be copied unfiltered into public receipts.

Writes use a conservative global row fence in the same primary connection/transaction as Forms. The ordinary native controller, mapper, import, share-review and background writer hooks share that fence. Every successful outer writer increments the generation. The SQL increment is bounded before integer overflow. Native validations execute inside the shared fence. Answer replacement rolls back completely on an insert failure; the last submission slot has one serialization order. Creation/clone, authoring, locks/state, sharing, DB-only response operations and administration invoke native controllers or validators.

A unique native operation key, HMAC of the input and sanitized receipt commit with the native DB mutation. A retry returns that key's existing receipt; a different input using the key is rejected. A lost commit acknowledgement must be resolved using `lookup`; absence after an ambiguous storage/commit error is not proof of no effect. Receipts persist opaque references and phases, not answer bodies or share/upload capabilities. `OCA\Forms\Mcp\BeforeEffect` identifies a proven authorization, source, revision, key, file-boundary or native OCS validation rejection. Other exceptions must remain uncertain.

Native submission/share notification intents enter a DB outbox before commit. The process claims each intent as uncertain before invocation and never replays a claimed intent. Only intents queued by the current request are drained, preserving the session actor. Native Activity swallows delivery exceptions; therefore successful invocation is reported as `attempted_unverified`, and the operation reports `partial`. This is deliberately not a delivery guarantee. Pending intents after process loss remain pending; there is no background replay/recovery worker in this slice. Core linked-file job insertion stays transactional, but MCP operations on linked forms are refused until persistent worker authority is implemented.

## Unqualified file effects and extension points

`Guard::fileBoundary` refuses uploaded-file submission/replacement/deletion, matching form-folder deletion or rename, staged upload deletion, linked-export writes and ownership transfer. A folder name beginning with a form ID is **not evidence** that every node is Forms-managed: stock Forms deletes such nodes. Recovery work must verify database-record-backed ownership, copy every affected byte plus data/metadata, verify recovery integrity, persist durable recovery evidence before destructive work, and provide a usable recovery action. Arbitrary user Files nodes remain excluded.

New native classes can be added under `src/`; preparation includes them automatically in the output manifest. Extend `NativeActions`, `Guard::authorize`, `Guard::fileBoundary` and the explicit hook map only with corresponding native tests. File handling, upload staging, exports, comments, persistent job authority, verified event delivery, and complete full-parity qualification remain separate work. Do not advertise this slice as complete Forms parity or atomicity across database, filesystem and notifications.

## Tests and provenance

Run `node --test native/forms-guard/tests/prepare.test.mjs` and the `NativeGuardContractTest` PHPUnit test. The opt-in integration class `mcp/tests/Integration/Forms/NativeFormsBoundaryTest.php` invokes `tests/native-probe.php` in separate PHP processes against an installed, disposable Nextcloud cell. Set `MCP_FORMS_NATIVE_BOUNDARY=1`, `MCP_FORMS_NATIVE_PROBE` to that probe, `NEXTCLOUD_ROOT` to the installation and `MCP_FORMS_NATIVE_OWNER` to its disposable administrator. These tests deliberately install database triggers and modify/restore one pinned source file; never enable them against production. Stock Forms fails the rollback and final-slot tests. The guarded suite covers those behaviors, ordinary native revision/share invalidation, lost acknowledgements, input-key conflicts, source drift, foreign actor refusal, receipt privacy and notification replay suppression.

Upstream: [Nextcloud Forms](https://github.com/nextcloud/forms/tree/066573fd592513a06f8b8624def8244e911efdfd), AGPL-3.0-or-later. The generated overlay retains the archive's COPYING and vendor notices. Companion source is AGPL-3.0-or-later, copyright 2026 MCP for Nextcloud contributors. `manifest.json` records the official installable source-file hashes; no third-party payload is embedded in this directory.
