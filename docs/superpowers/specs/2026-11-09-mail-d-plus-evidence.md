# Mail D+ implementation evidence

Baseline MCP2.7.0, `f24bc1141c88fd00ca3c16c10ee6801961740aae`.
D+1, D+2 and D+3 extend the existing native adapters and durable journal.
The catalog has 41 tools. Sending/scheduling/enqueue/MDN/outbox dispatch remain
absent and disabled. The v5 native overlay/source is unchanged.

## Final acceptance

The complete `devbox run check` finished with **exit0 / ok=true** on the final
implementation: **10,326 PHP tests / 94,908 assertions**, **837 browser tests**
across Chromium, Firefox and WebKit, both static scopes, documentation, release
and client tooling tests, asset builds and package validation. Documentation
validation covers 221 Markdown files and 116 packaged sources. Existing missing/
review-required public translations remain advisory; no provenance was fabricated.

One fresh independent review found no actionable findings and declined no
judgment. Subsequent changes only corrected inherited test resource usage, the
exact registration count and the explicit UI tier list; the latter was proven
identical to its previous safe fallback and passed 24 focused browser cases.
The final full catalog includes all those corrections. Native PHP/overlay sources
were unchanged after the separately recorded native qualification/refusal fixes.
The final local package is rebuilt from the committed source and its embedded
identity checked against that commit. No native installation or publication is
performed. Credential-only changes still cannot be attested; sending stays closed.

## Implemented guarantees

D+2 introduces a separate approved metadata operation with independent permission,
current original/source access and exact user/connection/account binding. It
admits only terminal native phase proof, a durably committed local alias marker,
completed setup binding or a succeeded repair explicitly referencing its source.
The target, effect digest, state, private resource snapshot and revision are
compared before releasing the old claim. Old keys survive. Missing resources,
old age, labels or acknowledgment never prove an outcome. Reconciliation and its
own checkpoint commit together; ordinary status and replay never perform it.
Journal/setup tables must be transactional primary storage, without replicas or
sharding. Unprovable old, intermediate or unscoped outcomes require native/admin
inspection and keep their claims. No attachment is deleted by reconciliation.

D+1 links an explicitly selected pending workflow and original non-secret intent
to a reviewed authentication diagnostic. Exact account, actor, connection,
profile revision, expiry and current source/read permissions are checked; the
worker rechecks the source chain before authentication. Per-protocol observations
survive source revocation between protocols without starting another probe.
An observation never completes setup, proves a credential change or claims OAuth
consent. Mail5.12.5 exposes secret values/presence, not a qualified non-secret
authorization revision: unchanged-profile completion still refuses. This is the
native blocker allowed by the approved D+1 scope, not fabricated change evidence.

D+3 offers owned alias name/email creation, update and deletion with three
independent grants. Native Alias/AliasMapper instances share the journal DB and
commit their opaque ID and completion marker with the native row. Dirty-field
tracking preserves signature/mode/certificate/account fields. Provisioned,
foreign, referenced local messages and unresolved account operations refuse.
Source/schema/pair gates admit only the tested NC35.0.2/Mail5.12.5 path; stock and
v5 share these native sources. Native UI actions remain independent, as with
account removal; no global native UI lifecycle lock is claimed. Additional
preferences outside the existing allowlist and cryptographic administration are
outside D+3. No native SQL write, controller call or secret input is introduced
in production code.

## Focused and native verification

Unit RED/GREEN covers missing completion marker, no-proof retention, exact target
CAS, completed-vs-intermediate native phase, completed setup, linked repair,
source revocation, old credentials vs unchanged profile, alias preservation and
foreign/provisioned/reference rejection. The final focused Mail/journal/mapper selection passed **196 tests / 1,863 assertions**;
API fixtures were regenerated from the existing SHA-pinned official archives for
Alias/AliasMapper, then3172/6815 API cases passed. Static fixtures do not qualify
other native pairs. The first native alias/reconciliation test passed; the linked
assistant test exposed a fixture helper expecting succeeded instead of the correct
awaiting_user state. The helper was corrected, with no product change.

Full native regression passed with v5: **51 tests / 1,409 assertions**, no skips,
run `mcp-it-20261109190747-6432c216bf7d`. Stock passed **19 executed / 32 expected
skips**, 529 assertions, run `mcp-it-20261109191355-e1f7932fd5ad`.

An additional transaction fault test then demonstrated a false completion marker
if an inner callback aborted its transaction but returned a resource ID. The
journal now verifies that the transaction is still active before its completion
checkpoint. The test first failed, then passed; the focused transaction/proof/
auth/alias suite passed **34 tests / 180 assertions**. A callback returning after
an aborted transaction leaves uncertainty and no completion marker.

The affected native paths were re-executed after the transaction-owner correction:

| Profile | Run | Post-transaction-guard result |
| --- | --- | --- |
| v5 | `mcp-it-20261109192033-c312badbd9b0` | 5 tests / 263 assertions, no skips |
| Stock | `mcp-it-20261109192344-f4cda1f4e6ec` | 4 executed / 1 expected skip, 143 assertions |

JUnit verifies the selected cases are held reply/Files acceptance, both D+ actual
HTTP workflows and both native tag-creation transaction cases. Only the held
reply/reset/archive case is skipped on stock, as its remote mutations require
v5. The same native Alias/AliasMapper source is used by both profiles. All native
runs exit0 and clean their labelled resources. The v5 normalized overlay SHA256
is unchanged: `1b74e8b6f0a39f2e8f28331fea1e426c3f348d72fdab8f45ffef0e8cbf8194a5`.

Subsequent product edits only tightened two refusal predicates: legacy unscoped
uncertain draft deletion now protects account/alias removal, and alias creation
refuses at 1,000 identities while existing edits/deletes remain available there.
Both were demonstrated RED then GREEN and are included in the final 196-test
selection. They change no native positive path, schema, overlay or source contract.

The guarded frozen-publication patch appended only four raw definitions and
extended the diagnostic with two optional correlation inputs/description. Every
other prior object was compared unchanged before patching. Publication/protocol/
results then passed **5 tests / 678 assertions**; no whole capture was regenerated.
The final complete catalog passed; both static scopes have no errors. The fresh
final review found no actionable Critical, Important or Minor issue and declined
no judgment. No second review or review-driven product change was required. Tests use
only labelled disposable native cells, no live mail accounts or public ports.
NC33/34 and other native/provider pairs remain unverified. Native installation,
release publication and deployment are separate operations.

## Complete-suite resource diagnosis

The first full catalog found the required original SPDX credit missing from nine
new library headers; the headers were corrected without changing the licensing
test. That run also exhausted the fixed 1 GiB PHPUnit heap in an existing large
Forms export fixture. Sharing one immutable 2 KiB string across its unchanged
20,200 cells retained the same payload SHA256 and assertions, reducing measured
fixture allocation from 60,145,424 to 8,433,424 bytes. The licensing/Forms selection
then passed 12 tests / 46 assertions.

Later whole runs reached the existing 10 MiB adversarial snippet case, where
only 202,324,552 bytes were live but 1,068,498,944 reserved: allocator fragmentation
prevented the contiguous allocation. Cache release did not return those arenas;
the ineffective experiment and its temporary logger were removed. The five large
adversarial datasets now use the repository's PHPUnit process-isolation pattern.
Each child explicitly receives, sets and asserts the parent's memory limit;
no unlimited child or increased budget is used. All original sizes, refusal and
timing assertions remain. The focused run passed 18 tests / 44 assertions under
1 GiB. Production snippet behavior and test coverage are unchanged.

That whole run passed the isolated cases and reached 10,309 of 10,326 tests,
then exhausted memory in PHPUnit's exporter while describing the aggregate
production source for an existing store-double contract assertion. Its same
nonempty-source and no-test-helper predicates now pass boolean/integer results
to PHPUnit, avoiding redundant multi-MiB diagnostic copies. All source bytes and
helper checks remain covered. The focused final-memory selection passed
32 tests / 118 assertions with the project-local temporary directory. An earlier
ad hoc invocation of that selection lacked this directory and failed the
64 MiB PostgreSQL log fixture because `/tmp` was almost full; it was rerun with
the established catalog environment, without a product/test change for that error.

The next complete PHP run reached all 10,326 tests under the fixed 1 GiB limit,
revealing one remaining stale registration assertion: 37 instead of 41 tools.
The exact expected count is now 41; the test still constructs every tool factory
and refuses eager resolution of native Mail. The focused application-registration
selection passed 18 tests / 1,272 assertions. No product source changed for these
suite-maintenance corrections after the fresh review.

The following catalog run passed all **10,326 PHP tests / 94,908 assertions**
and both static scopes. Its browser run passed 834 of 837 cases; the same catalog
completeness assertion failed on all three browsers because four new grants
were absent from the explicit UI tiers. The existing fallback already assigned
them to Full. They are now explicitly listed there; a before/after comparison
proved every Mail level maps to the identical operation arrays. The existing
Mail management test also covers all four grants, including isolation from Read
and individual custom selections. The focused tier suite passed **24 / 24**
on Chromium, Firefox and WebKit. The final whole catalog above passed after this correction;
no native or PHP product source changed.
