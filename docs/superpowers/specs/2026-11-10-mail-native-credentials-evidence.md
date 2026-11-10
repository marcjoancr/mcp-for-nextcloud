# Native Mail credential-write evidence — delivery evidence

## Scope

MCP2.7.0 retains 41 Mail tools and all original grants. The pinned custom
Mail5.12.5.7 dependency adds operation-bound native password/OAuth write evidence;
credentials remain entirely in native Mail. Sending, scheduling, MDN and outbox
dispatch stay disabled. This is a local implementation and acceptance record,
not an installation or release.

Qualification covers NC35.0.2.2 / MariaDB10.11.19 / primary unsharded,
unreplicated InnoDB and the exact v7 source/schema. NC33/34 remain separate work.
The unchanged-profile result reports a native write observed, with credential
change, current authentication and delivery unverified. Same-value saves count;
SMTP-only evidence does not imply authentication.

## Implementation

Native context metadata binds owner, MCP connection scope, original operation,
kind, target and non-secret intent for at most 24 hours. The core native Page
cookie activates the handoff; status only recovers its existing link. Recovery
can open the assistant to repair unsafe settings while completion still rejects
those settings. No status path registers a new context or exchanges credentials.

The native mapper increments its hidden authorization revision atomically with
credential/connection-identity writes, including display name. Committed dirty
fields are cleared before native cache work reuses the entity. Password evidence
follows actual persistence. Manual Google/Microsoft evidence requires the exact
reserved signed nonce state and the successful new-token branch; their unchanged
old-token error return and automatic refresh never attest a manual write.

There are at most 100 unconsumed native handoffs per owner and 32 bound states per
handoff, with at most 600 seconds per state. Metadata tables contain no password,
provider code, token or secret digest. A transient permit holds native encrypted
values only within the callback; it cannot be serialized.

The MCP adapter verifies source, schema and exact primary connection identity.
One-shot native proof consumption, workflow binding and original-source completion
share that primary transaction and native owner session. An inherited transaction
is refused. Admission uses the current request-wide grant-policy snapshot, and
connection revisions are rechecked before the effect; session loss aborts work.
A revocation committed after that snapshot can allow the admitted request to
finish; subsequent requests are denied. This preserves the existing grant
contract and does not promise atomic mid-request revocation. Lost commit acknowledgements remain uncertain and never retry
native credential writes.

## Verification

| Check | Observed result |
| --- | --- |
| Enabled native Mail regression, final run d3beb4bf932f | 56 tests / 1,412 assertions, no skips |
| Actual browser draft lifecycle | Plain and HTML: HTTP MCP + Files, native edit, native IMAP identity/bytes, no sending |
| Actual credential browser workflow | Original native form, unchanged profile, SMTP-only wording; real MCP admission, completion, consumed proof, status recovery and replay |
| Native Google and Microsoft callbacks | Native state/HMAC, loopback trusted TLS provider transport, native encryption/mapper persistence and actual MCP completion |
| Negative provider/state cases | Error with old encrypted tokens, automatic refresh, expired/reused/over-limit state, earlier context/new tab, different logged-in owner and foreign native Page entries |
| Native SQL/session cases | Real shared rollback of proof/workflow/source; concurrent password and display-name writes refused; KILL after proof claim aborts without replacement-session writes |
| Current source/schema gates | Actual primary schema admitted; native source tamper closes evidence, exact restore reopens it |
| Stock Mail regression, final run cef682bb175b | 17 executed / 39 expected unsupported skips / 482 assertions |
| Immutable v6 compatibility | 113 actual source fingerprints reconstructed from verified archives; current production boundary passes 1 test / 31 assertions, old scopes only, tamper closes them |
| Final complete devbox catalog | 10,371 PHP tests / 95,499 assertions; 837 browser tests; both static scopes, documentation, client/release checks and package pass |

Initial native RED proved accepted same-value credential persistence while the
old unchanged-profile completion refused. A later native RED admitted a 33rd
bound state; the bounded implementation passes that case. Targeted RED→GREEN
also covers unsafe-profile link recovery, connection revision/identity policy and
inherited transactions. SQL tests include rollback and an acknowledgement lost
after a successful real SQLite commit: the proof/workflow/source remain committed,
the completion receipt stays uncertain, and replay never claims again.

The wider 382-case native integration run had one stale Mail test assertion that
forbade all query parameters. The corrected test permits only the opaque context
handle on v7 and still forbids a query on stock; its complete Mail rerun passes.
No production behavior was weakened for that assertion.

## Final review correction

The fresh review of snapshot `a355a1c9672a8c9fa7d7fe012174d27b5dd0320d`
found one Important issue and no Critical or Minor findings. Native evidence
admission used a shared browser cookie alone. Opening a matching handoff in tab B
and then saving, creating an account or generating OAuth state from old tab A
could attribute the action to B, including across different MCP connections.
Native RED run b8bba97be475 reproduced all three: wrong proof for password and
late OAuth state, wrong target for account creation. Both actual MCP connection
scopes differ. The correction adds a page-local initial-state marker to the exact
three native API payload producers and checks it against the HttpOnly cookie.
Candidate GREEN run 22c562ce8fb7 verifies all three interleavings with no wrong
proof or target, plus the existing native provider, revision, rollback,
concurrency and session-loss guarantees. Only then was the exact-artifact
qualification switch enabled. Final run d3beb4bf932f passes complete MCP-issued
acceptance without fixture arming: password, Google and Microsoft admission,
completion, consumed proof, status recovery and replay; all three late-tab cases
have no wrong proof/target. Plain/HTML draft acceptance also passes unchanged. The verification table records the final corrected source.

Executor rulings: unchanged v1–v6 algorithms stay within compatibility assessment
because their earlier deliveries already have acceptance and review evidence;
unknown pre-existing defects remain a residual risk. Request-wide grant snapshots
retain the contract described above, with the stated mid-request revocation limit.
The 100-context bound counts all unconsumed native handoffs, including unused
contexts after legacy MCP completion; its 24-hour availability cost is accepted.

## Final local artifacts and remaining scope

The final catalog, current stock regression and immutable v6 source/tamper
compatibility pass. The fresh review's one Important finding is fixed in one
consolidated pass with actual native RED→GREEN; no Critical or Minor findings
remain. There was no second review.

The normalized native overlay contains 88 files, all matching the 129-entry
source manifest, with fixed ownership/time and no links or unsafe paths:

| Artifact | SHA-256 |
| --- | --- |
| `mcp/build/native-mail-guard/mail-guard-v7-overlay.tar.gz` | `903c4ec72cf084985082d378e237cf52fdaaf323baa97b373d787f1ba473f4b7` |
| `mcp/build/native-mail-guard/credentials-v7-page1/manifest.json` | `9bbfb21c43694c23e1143c46e48e4c81ba9ac171ce454415578deae10d16c8da` |

The source commit and clean MCP package digest are retained in the external
execution record after local packaging. Immutable v6 overlay and clean MCP
package retain their original hashes. No live instance, provider account, push,
pull request or release is created. Sending stays disabled. Broader NC33/34
qualification remains a separate continuation and is not inferred from these
NC35 results.
