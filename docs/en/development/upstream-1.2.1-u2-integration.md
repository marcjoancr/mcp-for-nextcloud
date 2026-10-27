<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Upstream 1.2.1 U2 integration

Date: 2026-10-27. Scope: the [U2 plan](upstream-1.2.1-u2-plan.md), adapting
upstream `3c61e928f3abdb37ad60b80ed207a5e243bf0ec4` onto the fork capability
architecture. This is partial 1.2.1 integration, not a 2.0.0 release qualification.

## CI prerequisite

At `c06bdda3b24def22ccf27e66cf90348f309e8824`, all four published workflows passed:
[Tests](https://github.com/marcjoancr/mcp-for-nextcloud/actions/runs/40505865402),
[Quality](https://github.com/marcjoancr/mcp-for-nextcloud/actions/runs/40505865552),
[Package](https://github.com/marcjoancr/mcp-for-nextcloud/actions/runs/40505865439),
[Native integration](https://github.com/marcjoancr/mcp-for-nextcloud/actions/runs/40505865488).
This corrected the shallow-history failure documented in the [U1 record](upstream-1.2.1-u1-integration.md#published-ci-follow-up-2026-10-27).
These runs validate the U1/CI baseline, not the subsequent U2 delta.

## Implementation

`Access/BackupPolicy` is the only backup document owner. It replaces the fixed
`Platform/FileAccess/BackupLocation` owner and uses the existing policy transaction
for revision, concurrency and persistence. The fresh reader queries only its own
AppConfig key, accepts valid lazy string values and observes rollback without
flushing unrelated shared caches. Consumers have explicit policy dependencies;
only test fixtures supply in-memory defaults.

Files Editing separates preparation, verified copying and write admission.
Presentation decorates plans without losing checkout/restore instructions.
Creation, reorganization, checkout, restore and undo recheck reservations at their
mutation callbacks. Contacts always verifies a complete vCard before deletion.
Current and historical NFC names remain reserved at the actor root; canonical
share aliases retain U1 hidden-original mirror protections. This does not introduce
a blanket write prohibition through renamed shared mounts. Filesystem writes and
policy commits remain non-atomic; the final-check limitation is documented in
[configuration](../reference/configuration.md#mcp-backup-copies).

The administration section lives in `src/admin/backups/{controller,state,view}.js`
and is composed by the existing bootstrap. It uses the existing transport,
lifecycle and revision channel. Invalid responses never offer invented defaults;
conflicts/uncertain saves preserve the draft and block replay until verified reload.
Four tool descriptions and the corresponding frozen publication/protocol fixtures
change deliberately to describe the copy policy. Tool names, schemas, grants and
confirmation requirements remain unchanged.

## Validation record

From the repository root with the pinned development dependencies installed:

```sh
devbox run check --cwd "$PWD" --json
composer --working-dir=mcp test:integration -- 35 mariadb upgrade-local
```

The registered `check` task invokes `composer --working-dir=mcp check`; REUSE and
secret scanning are separate checks. Raw native logs are not publication evidence:
use the harness-redacted artifacts and the exact input hashes below.

The complete catalog check passes with exit 0 (`check-reviewed.json`): Composer manifest validation; documentation
(162 Markdown files / 110 package sources); documentation tooling (29 tests);
client-runner safety tests (10); source/integrity tooling tests (11); syntax
(1,312 PHP files); both fixed PHPStan scopes; and **8,520 PHP tests / 72,687
assertions**; **717 browser tests** across Chromium, Firefox and WebKit; and
production packaging with 110 documentation sources staged and verified. Runtime:
PHP8.4.27 / Node24.21.0. This full run includes all independent-review fixes.
Focused policy, HTTP, write, privacy, architecture, translation and contract checks
pass (150 tests / 7,724 assertions). Both PHPStan scopes pass. Initial browser
scenarios pass in all three engines (21 tests), and two extra Chromium tests prove
page-local revision advancement and disposal of late reads. The checkout prompt
regression and shared contracts pass (24 tests / 117 assertions).

`composer --working-dir=mcp test:integration -- 35 mariadb upgrade-local` passed
with exit 0: **293 tests / 4,384 assertions / 9 skips**, run
`mcp-it-20261027132836-387c5d1d4721`, on NC35.0.1 / PHP8.5.11 / MariaDB10.11.19.
Six skips are pre-1.1 migrations; three are PostgreSQL-only probes. Real deadlock
and lock-timeout diagnostics are intentional passing admission tests, not hidden
failures. The runner removed its owned containers/network and published no ports.
The upgrade baseline predates `backup_settings`: this qualifies default behavior
when absent, not an invented previously configured 1.2.1 upgrade. The native U2
cases exercise persisted rename history, corruption, Unicode, Files copy on/off,
mandatory Contacts copies and real administrator/non-admin/CSRF admission.
Coordinated recovery remains covered by its separate NC35/SQLite/fresh cell.

Sanitized local artifacts are under
`mcp/build/integration/mcp-it-20261027132836-387c5d1d4721/mcp-it/artifacts/`.
This final candidate includes all production review fixes; only documentation
closure follows. The earlier pre-review run `mcp-it-20261027130508-427f2e473953`
also passed with the same native counts. The final input hashes identify a local
working-tree acceptance candidate, not a signed release artifact:

| Input | SHA-256 |
| --- | --- |
| Public fork baseline archive | `b6f2470a7bc15c8796b45081cd8bfd6f082dc2bca019c46830581f08fbb98b25` |
| U2 candidate archive | `61b011730e99bc8b18cae5646b968c9402eb79299b116006eb512cfeae368cb1` |
| Native test source | `9803eca829c5ed8a29ff3e8ba55db4056b95e6f5bc9a04880b3099324915b61e` |

Development failures were retained rather than suppressed: new policy/flow tests
first failed before implementation; the first complete PHP run exposed static
fixture arguments, test autowiring, intentional description/route changes and one
remaining legacy native-test reference. These were corrected without weakening
architecture or compatibility assertions. A browser invocation initially used stale
built assets; rebuilding the authored entry resolved that harness error. The first
setup lacked npm dependencies; `npm ci` restored the pinned lockfile dependencies.
The catalog run then found one missed DAV guide fixture; updating just its two
Contacts sentences resolved the mismatch. A subsequent catalog run passed every
non-browser gate and 714 browser tests, with three failures from the same no-JS
section count (12 before U2, 13 now). The expectation now includes explicit checks
that the new section/help remain readable and its save control remains disabled.
Intentional build-failure tests emit a missing-module diagnostic while proving the
build refuses invalid input; those diagnostics are not production build failures.

REUSE 6.2.0 passes all 1,705 files, and Gitleaks 8.30.1 finds no secret in the staged
delta with the pinned default rules. The scoped Impeccable UI detector returned no
findings. Translation provenance remains valid with 96 `review-required` documents;
that status is advisory and is not human approval.

## Independent review and fixes

One fresh whole-branch technical review found three material issues. All were
accepted and reproduced before correction: eight regression cases produced six
failures, with stable GET/PUT controls already passing. The fixes are:

- Verify the policy revision after assembling each settings response. A competing
  commit cannot pair newer values with an older revision; GET refuses that mixed
  read and PUT reports an uncertain 503 so the UI preserves the submitted draft.
- Check both recorded endpoints during the entire undo preflight. A known reserved
  path refuses the whole undo before moving any item. Recorded empty directories
  that became reserved are predicted and reported as retained, not deleted.
  Final mutation checks remain necessary for later concurrent changes.
- Remove the remaining unconditional fixed-folder copy promise from the live Files
  guide. Its exact publication fixture strings change with the guidance; schemas,
  grants and result contracts remain unchanged.

Focused regression, batch, guide and controller validation passes **101 tests /
423 assertions**. The full catalog and native suite both passed again after this
fix pass, with the final counts above. There were no deferred minor findings. This automated independent review
does not satisfy the still-unassigned human reviewer roles.

Review boundaries accepted explicitly: actor-root reservations do not introduce a
new blanket ban through renamed shared mounts; policy and filesystem operations
are not atomic after the last check; release/platform/client/human qualification
outside the tested scope remains pending. Expanding either first guarantee would
require additional architecture and tests; omitting the qualification boundaries
would overstate release readiness. The existing approved intake authorized this
implementation detail plan, explicit constructor updates and isolated worktree;
temporary execution/review ledgers stayed outside the repository as required.

## Remaining work

U3 Tasks hierarchy, U4 Mail and U5 complete 1.2.1 parity/qualification remain open.
LinuxServer L1-L5 and intended-server upgrade/recovery qualification, trusted source
and artifact distribution, and F3/F4/F5 approval remain open. Public HTTPS,
Inspector's prior nonzero 14/15 result and encrypted-store issue, real ChatGPT
consent/tools/revocation, and human security, operations/recovery, licensing,
accessibility and language review remain pending. New runtime translations are
prepared in de/es/pt_BR; changed English guides retain translation provenance and
require corresponding language updates/review.

Publication advances only the already-authorized `fork/main`. Evaluate its new
[Actions runs](https://github.com/marcjoancr/mcp-for-nextcloud/actions) against the
resulting commit; the passing C0 workflows are not evidence for the U2 revision.
All four U2 workflows for `de607816` subsequently passed; the
[U3 prerequisite record](upstream-1.2.1-u3-integration.md#u2-prerequisite) links the exact runs. No deployment,
intended-server modification or upstream PR #14 change is part of U2.
