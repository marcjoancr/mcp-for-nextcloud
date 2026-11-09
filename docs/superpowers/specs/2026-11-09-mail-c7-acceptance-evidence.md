# C7 Mail acceptance and regression evidence

Baseline: MCP2.7.0, commit `6a5c554d270dbf89d70f47291de4f9349770d151`.
C7 accepts the implemented no-send slices; it adds no runtime tool, native source,
permission, schema, installation or delivery path. Sending stays disabled.

## Focused and native verification

Focused Mail/journal tests: 170 tests / 1,559 assertions passed; standalone
journal regression: 14 tests / 52 assertions. The full v5 Mail selection passes
**49 tests / 1,302 assertions**, with no skips; its new acceptance workflow passes
**120 assertions**. Every selected case is in the Mail namespace. Stock passes **17 executed / 32 expected skips** out of 49 selected, with
**422 assertions**. The full catalog also passes, as recorded below.

Runner preview: `sh mcp/tests/Integration/ci/run-local.sh --plan 35 mariadb fresh --mail`.
Execution uses Node24.22.1 and `MCP_IT_PHPUNIT_ARGS='--filter Mail'`, with
`MCP_IT_MAIL_GUARD=1` for v5 and `0` for stock. Both are fresh, disposable cells,
with no published ports or live accounts. No temporary qualifier override is used.

| Cell | Run | Result |
| --- | --- | --- |
| Exact v5 | `mcp-it-20261109172920-079e1ce714c4` | 49 / 1,302; exit 0 |
| Stock Mail | `mcp-it-20261109173517-9bf379b095bb` | 17 executed / 32 expected skips, 422 assertions; exit 0 |

Stock skips are guard-only flag/move/folder/tag-assignment/refresh cases, including
the cross-delivery workflow. Executed A/D/C0/local-organization/tag-creation cases
and source-boundary refusal checks pass. Skips are not evidence of those remote
capabilities working on stock Mail.

The actual v5 builder manifest SHA256 remains
`77d02705bfd308d5b39b9b7f3bda90faef2fd23b79587ae24b943ff672558007`;
the normalized overlay remains
`1b74e8b6f0a39f2e8f28331fea1e426c3f348d72fdab8f45ffef0e8cbf8194a5`.
Artifacts stay under `mcp/build/integration/<run>/mcp-it/artifacts`: JUnit,
redacted logs, source archive digest, pinned environment and final exit status.

`McpMailAcceptanceTest` composes a real held reply and native Files attachment
with a signature update, Archive role configuration, flag/tag assignment,
explicitly reviewed legacy-cache reset, archive and destination refresh. All
fixture messages share one Message-ID; observations use mailbox/UID and exact
cached IDs. The held native draft JSON, parent and attachment bytes, Files
original, outgoing IDs and SMTP capture must remain unchanged. Old operation IDs
remain read-only after their source IDs vanish; ownership and source revocation
still protect receipts/replay.

The temporary negative control changed only the test draft's native parent after
reset, preserving `failed=true`. It failed exactly at the held-reply hash assertion
(1 test / 49 assertions). The corruption was removed before normal regression;
no permanent diagnostic switch or product behavior was introduced. The fixture
explicitly grants `mail_attach_files` in addition to Files read and draft access.

## Complete catalog verification

`PATH=/home/dev/.local/share/mise/installs/node/24.22.1/bin:$PATH`
with project-local `TMPDIR=mcp/build/check-tmp`, followed by
`devbox run check --cwd /home/dev/worktrees/mcp-for-nextcloud/mail-release-a --json`,
returns `ok=true`, `exit_code=0`:

- **10,242 PHP tests / 93,724 assertions**, under the unchanged 1GiB limit.
- **837 browser tests**, Chromium, Firefox and WebKit.
- Both static-analysis scopes, syntax, manifest/API/architecture checks.
- Documentation validation: 219 Markdown files and 116 package sources;
  translation provenance, staging/verification and production package pass.

Catalog PHP is 8.4.27; the pinned native container uses PHP8.5.12. Existing
pending translations/missing locale pages remain advisory, without fabricated
human review. No failing test is skipped or weakened. Native guard-only skips in
the stock matrix are reported separately above.

## No-send and architecture acceptance

The `MailToolCatalog` and `GrantPolicy` allowlists remain unchanged: 37 tools,
with no send, schedule, enqueue, cancellation, MDN or outbox-dispatch entry.
Existing catalog/capability tests pin that contract; both native cells keep
`send.status=disabled`. D diagnostics retain authentication-only IMAP/SMTP probes,
with their existing no-envelope/no-DATA checks. The new workflow preserves the
held reply rather than exercising a native UI edit or draft flush. Existing C0
characterization separately records that a person editing through native Mail can
release that local hold and lose alias/parent data during native IMAP conversion.
C7 does not turn that known limitation into a lifecycle guarantee.

A/D composition, settings and native account setup continue through existing
adapters and one shared durable journal. C1–C6 use the existing closed native
boundaries and delegated receipt phases. No second journal, credential collector,
transport, generic mutation callback, force unlock or automatic native install is
introduced. Review/confirm/status remain distinct operations under current grants.

Public docs now state actual v2–v5 scopes, separate refresh/reset after destination
moves/new folders, all existing byte/time/admission budgets, historical freshness,
local-vs-IMAP drafts, recoverable Trash and operator inspection steps. Existing
locale pages retain visible pending-review notices and real source revisions;
C7 does not certify translations as human-reviewed.

## Scope and remaining work

NC35.0.2 / Mail5.12.5 / MariaDB10.11.19 is the disposable native acceptance pair.
NC33/34 and other native versions/providers remain unverified by C7. No upgrade
cell or v1–v4 legacy cell is rerun here; their earlier evidence is unchanged. Existing
fingerprint/schema/protocol gates remain in force. Stock Mail supports qualified
local tag creation, but refuses guarded remote writes. The exact v5 dependency
remains separate, with the unchanged [C6 artifact and rollback requirements](2026-11-10-mail-refresh-evidence.md).

D+ credential-only evidence, explicit reconciliation and alias administration are
pending. B sending/scheduling/outbox dispatch is frozen. This is no-send acceptance,
not full native Mail parity or a deployment record. See the
[public operational limits and inspection steps](../../en/reference/tools/mail.md).

## Final review and disposition

One fresh read-only reviewer inspected the five-file C7 diff against the stated
baseline and the actual JUnit/catalog evidence. No actionable Critical, Important
or Minor finding was reported; no fix pass or second review was needed. The
reviewer set aside new runtime qualification of NC33/34, separate MySQL, upgrade
and legacy cells, plus D+/B/optional parity implementation. Those retain the
approved exclusions above: no unsupported pair is newly qualified and pending
features are not claimed as delivered.

C7 is accepted for the implemented no-send scope and tested matrix. Keep the
existing branch/worktree and a local commit, then validate a production package
from clean committed source. Publishing, native installation, PRs and deployment
remain separate user-requested operations.
