# C5 exact message tag assignment — delivery evidence

Baseline: `b8fc394d6dedfa39c3900a292164f4f39efe9716`, MCP2.7.0. This completes
the assignment portion of C5 on top of the existing local creation/inventory.
The [implementation plan](../plans/2026-11-09-mail-tag-assignment-plan.md) extends
the approved no-send design. No deployment, push or Mail account send occurs.

## Boundary

One new tool, `mail_set_message_tag`, adds the independently denied-by-default
`tag_messages` permission. The 35 existing raw tool contracts are preserved.
Preview includes full owned tag metadata and current/desired membership. Execution
binds account/message/mailbox/UID/UIDVALIDITY, inbound profile, tag metadata and
selected association revisions to the existing journal, worker and native fence.
Stock or v1/v2/v3 native Mail remains closed for assignment. v4 retains their
original flag, move and folder scopes; all native source hashes must match.

Native v4 migrates exact cached-message IDs beside preserved legacy header rows,
with uniqueness/cascade and generation/profile provenance. Import/update, reads,
search, native single/bulk UI, global tag deletion and classification consume
exact IDs. Legacy history is never adopted by header/name. Existing legacy or
profile-changed cache requires an explicit native cache rebuild, outside previews.
Tags are read only where provenance is current; native JSON exposes unknown state.

Native UI captures a pre-wire revision too, so a concurrent cache writer invalidates its cache update. A selected native connection brackets fetches; a shared pre-fetch mailbox revision
advances only through that snapshot's own acknowledged commits. Same-generation
stale sync cannot overwrite a newer tag cache commit. Connection identity excludes
password/token ciphertext, so credential refresh alone does not change provenance.
Old header-only queued follow-up jobs are ignored before LLM calls. New jobs bind
cached ID/UID/generation and revalidate after classification. Normal native AI
policy/scheduling remains; MCP assignment invokes no classifier or sync.

The wire guard requires READWRITE selection and actual PERMANENTFLAGS keyword
support (or wildcard). It dispatches at most one UID STORE and verifies the full
flag set on the same selection. Default-label aliases are one logical label;
system-state keywords and unrepresentable/truncated labels are excluded. Native
Important cache projection and its exact association update together. Stock
native tagging emits no event, and no new tag event is introduced.

The existing journal retains remote/cache evidence and uncertainty. Replay/status
requires current original permission and never sends another command. Uncertain
operations keep their account claim; there is no reconciliation or force unlock.
Sending, outbox/SMTP/MDN dispatch and remote refresh remain disabled.

## Validation

The final packaged source, with C5 enabled through its normal boundary and no
fixture override, passed **40 native Mail tests / 1,053 assertions** on pinned
NC35.0.2 / Mail5.12.5 / MariaDB10.11.19, PHP8.5.12. The suite includes the existing
A/D/C0/C1/C2/C3/C4/local-C5 paths, full HTTP approval/replay/status/current-permission
checks, selected UID/alias permanence, scripted protocol faults and cache receipt
loss. It preserves an actual held reply and SMTP/outbox/MDN safety state. Stock
Mail creation/lifecycle compatibility separately passed **5 tests / 68 assertions**.

The whole post-review check passed **10,205 PHP tests / 93,247 assertions**, **837
browser tests**, both static-analysis scopes, architecture/API/docs checks and
packaging. PHP peak was 1,023.52 MiB under the existing 1-GiB limit. The shared
`/tmp` filesystem was almost full: the first whole check failed only because a
large Postgres-log fixture could not be fully written. That unchanged test and
all subsequent whole checks passed with project-local test temporaries under
`mcp/build/check-tmp`; package caches were not redirected.

After adding the OAuth identity case, the final catalog test passed **10,206 PHP
tests / 93,249 assertions**. The native factory uses account.email in XOAUTH2;
that non-secret principal now participates in cache identity too. A unit regression
first observed the old cache remain verified after an email change, then passed
with the new projection. A native entity/DB regression verifies invalidation and
stale-account refusal without contacting an OAuth endpoint or changing credentials.
Password-mode cache identity is unchanged; credential/token ciphertext is excluded.

One fresh read-only review found a QRESYNC response-reuse gap. Real Dovecot
regressions observed the old per-stage revision recapture erase an intervening
successful native C5 assignment, and observed cached combined FLAGS erase its own
new-message Important classification with a legal token lacking HIGHESTMODSEQ.
The fix keeps one shared pre-fetch version for the entire partial request, skips
reapplying the same combined flags to actually imported new UIDs, and applies
vanished batches through the same locked version. Both regressions now pass in
the full 40-test suite. Native sync/response/cache assumptions are fingerprinted;
stock native Cache never reads or writes individual-message cached flags. There
were no other actionable review findings and no second review was commissioned.

The repository qualifier remained false through these gates. A temporary qualifier
existed only in labelled disposable test containers; it was then removed entirely.
The final normal packaged-source run also passed 40/1,053 after the repository
qualifier was enabled. No live installation, deployment, send or publication took
place. The v4 source and migrated storage remain mandatory runtime conditions.

The normalized v4 overlay contains **36 files**, with **56 fingerprinted paths**:

- Overlay SHA256: `056233948790dfa742cc1471e1c2133c5a70551ce8177cb22faadc964bb8ad79`.
- Manifest SHA256: `4385f56e2dcde49e1f3739ad91fc21c84dbf41adea224af9152abeb57d8337a5`.
- Original native archive SHA256:
  `c52dab18a4de1710854038d95810f9ae4eb76a8656d89800e7dceb5aff0034e0`.
- Local output: `mcp/build/native-mail-guard/mail-guard-v4-overlay.tar.gz`.

The v1/v2/v3 artifacts and original fingerprints are unchanged. Historical
pre-qualification v4 candidates are retained under different local filenames and is not
the qualified artifact. Rebuilds must match the final hashes above.

## Operational limits

Use only the reviewed v4 source and native migration on an unsharded, unreplicated
transactional primary with the exact unique key and FK cascade. C5 assignment requires the qualified MySQL/MariaDB provider; other storage refuses before schema or transport work. Native cache rebuilds remain a
native administrative action. All original legacy associations are preserved.
Rollback needs a matched database/code snapshot, because v4 adds native entity
columns. Older immutable artifacts must not be overwritten.

C6 explicit bounded refresh, D+ alias/credential-only workflow and full C acceptance
remain separate pending work. Existing reply holds and alias conversion limits
from A/C0 still apply.

## Decisions retained

- Preserve historical NULL-exact associations; never guess an identity from a header or name. Cost: legacy cache needs an explicit native rebuild.
- Keep native tagging's zero-event behavior. Cost: no new tag webhook/event is promised.
- Treat canonical native default-label aliases as one tag and exclude system-state keywords. Cost: state-keyword workflows stay in native Mail.
- Reject superseded snapshots rather than adopt a newer writer's revision. Cost: concurrent writes can cause native sync to abort and retry.
- Bind cache provenance to non-secret inbound identity, excluding password/token ciphertext. Cost: host/user/protocol or OAuth-principal changes need a rebuild; credential-only workflow completion remains D+ work.
- Qualify only the MySQL/MariaDB primary provider. Cost: SQLite/PostgreSQL assignment remains closed pending native qualification.
- Deployment/live rollback, sending/MDN/outbox dispatch, C6 refresh, advanced parity and unmodified native features remain outside C5. Cost: this delivery must not be read as full Mail completion or deployment authorization. Final qualifier/artifact/evidence work was completed here; it was not assumed from review.
