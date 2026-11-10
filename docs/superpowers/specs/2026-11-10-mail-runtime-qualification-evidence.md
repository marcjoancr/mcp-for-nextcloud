# Mail runtime qualification — execution evidence

## Scope and implementation

MCP stays 2.7.0 with 41 Mail tools and existing grants. Native Mail retains all
credentials, forms, consent, MIME, transport and cache work. MCP sending,
scheduling, MDN and outbox dispatch stay disabled. All runs use owned disposable
containers; no live installation, push, PR or release.

The separate v8 artifact is Mail5.12.5.8 / mcp-mail-mutations-v8. All 129 native
fingerprint keys match v7; 128 implementation hashes are identical. Only
appinfo/info.xml changes version and supported core range. Native flags, moves,
folders, exact tags, refresh, draft protection and page-bound credential proof
algorithms are unchanged. The prior v7 artifact and all earlier manifests remain
immutable and independently supported for their original scopes.

MailRuntimePairs centralizes strict three/four-segment core parsing and exact
app-build admission. It rejects malformed input, leading zeros, suffixes,
unknown/future patches and legacy builds on extended cores. Capabilities, native
draft entry, credential evidence and alias writes share that policy while
retaining their independent source/schema/ownership/permission checks.

Candidate extended cells require an explicit phase, report unverified guarded
capabilities and refuse draft/credential adapters and aliases. Only unavailable
credential adapters permit native test-context arming. Enabled acceptance must
use actual MCP-issued context/link without manual arming. There is no production
environment bypass.

Qualification applies to the exact pinned core images and primary unsharded,
unreplicated MariaDB10.11.19/InnoDB. Fresh cells are tested; upgrades, other
patches/builds/providers and live deployment are separate acceptance work. Current
request-wide grant snapshot limits remain as documented for v7.

## Observed acceptance

| Runtime / check | Result |
| --- | --- |
| NC33.0.10 candidate ed722d7b4afd | 18 native tests / 176 assertions; runtime stayed closed; all credential/provider/tab browser guarantees pass |
| NC33.0.10 enabled b7e6b478a109 | 57 native / 1,424 assertions; plain/HTML and full password/Google/Microsoft MCP workflows and all late-tab cases pass, no manual arming |
| NC34.0.5 candidate 70b46de00e8f | 18 native / 176 assertions; runtime stayed closed; all credential/provider/tab browser guarantees pass |
| NC34.0.5 enabled e58047ea4f6e | 57 native / 1,424 assertions; plain/HTML and full password/Google/Microsoft MCP workflows and all late-tab cases pass, no manual arming |
| NC35.0.2 current v8 4a5d76fca688 | Four source/runtime/tamper/alias/thread tests / 105 assertions plus every browser workflow; exhaustive v7 NC35 56 / 1,412 native evidence retained for unchanged implementations |
| Stock NC33.0.10 final 1f34a83469cc | 18 executed / 39 expected unsupported skips / 412 assertions |
| Stock NC34.0.5 final 512d127257eb | 18 executed / 39 expected unsupported skips / 412 assertions |
| Final complete devbox catalog | 10,379 PHP / 95,640 assertions, 837 browser tests, both static scopes, documentation/client/release checks and package |
| Final immutable v6/v7 source compatibility | Actual files: 1 / 31 and 1 / 37; prior scopes retained, tamper closes and exact restore reopens them |
| Final source-phase review correction | 20 focused tests / 199 assertions, actual source/pre-admission copy and external-kit RED→GREEN |

The candidate native guarantees cover selected-generation flags, command-bound
movement, targeted folder changes, exact keyword semantics, bounded refresh and
cache transactions, owner/session continuity, killed SQL sessions, stale draft
conversion and attachment ownership. Native browser evidence covers actual
password persistence, SMTP-only wording, Google/Microsoft old-token failure,
refresh, expired/reused/over-limit state, foreign owner/source, shared rollback,
credential/name contention, source/schema tampering and all three late-tab
interleavings across real distinct MCP connection scopes.

Enabled acceptance additionally exercises every Mail test, actual HTTP MCP
confirmation/journal paths, plain/HTML native editing with Files attachment bytes
and IMAP identities, credential completion, one-shot proof consumption, status
recovery and replay. All cases retain no-send/outgoing safety checks.

## Final review correction

The fresh review of snapshot 200c2c3f81641b7bd98dacda46dc6372b90e1410 found
one Important test-integrity issue and no Critical or Minor findings. Final source
already admitted both pairs, while its candidate phase still attempted to assert
they were closed. The recorded pre-admission candidate results remain valid.

One consolidated fix validates the selected source before run creation: candidate
requires that pair closed, enabled requires it admitted, and qualification phases
reject external kits. There is no production environment bypass. Actual source
plus an isolated copy of its pre-admission policy produce the two expected REDs,
then GREEN; a separate external-kit RED proves the source mismatch guard. All
20 focused tests / 199 assertions pass. No second review was dispatched.

The first stock NC33 run found one stale test expecting characterized NC35 tag
creation. The implementation correctly refused the unverified pair. The test now
asserts refusal, unchanged tag count and no sending; the complete stock rerun
passes 18 executed / 39 expected unsupported skips / 412 assertions.

## Native artifact verification

The final builder reproduces the source manifest and compiled MCP manifest
identically. The normalized overlay contains 88 files matching the 129-entry
manifest, fixed files644/directories755 and uid/gid/time0, with no links or unsafe
paths. All 128 native implementation fingerprints remain identical to v7.

- Final normalized overlay: `mcp/build/native-mail-guard/mail-guard-v8-normalized-overlay.tar.gz`, SHA-256 `d95c6674b45f749b13edda4a2c21f634f691817fe6fd416832da701865911756`.
- Source manifest: `mcp/build/native-mail-guard/runtime-v8-final/manifest.json`, SHA-256 `957ba46907945d3cd1cee00955ae0ab9ae9c3dcf0d0fd3b71b6d4298f5e81276`.
- The earlier review archive `mail-guard-v8-overlay.tar.gz` remains unchanged; it inherited private build modes and is not the final distribution archive.

## Local delivery and limits

All planned native, stock, compatibility, full-catalog and fresh-review acceptance
is complete. The source-phase Important finding was fixed in one consolidated
pass; no Critical or Minor findings were reported, and no second review was
dispatched. Source commit and clean MCP package identity are recorded externally
after packaging.

Final docs validate 230 Markdown files / 116 packaged sources. Translation
freshness remains advisory (100 review required, four missing), with valid
provenance. No live instance, provider account, push, PR or release is created.
Sending stays disabled. Only the exact accepted fresh core/build/provider pairs
are qualified; other patches, upgrades, databases and complete native Mail UI
parity remain outside this delivery.
