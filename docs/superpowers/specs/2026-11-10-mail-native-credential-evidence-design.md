# Native Mail credential-write evidence

Baseline: MCP2.7.0, local commit
0340439929998f5c4747bcfb1820f261795eb0f6. The accepted continuation completes the
native draft lifecycle first, then native credential-write evidence, then broader
qualification. The draft delivery is complete. This slice retains native Mail as
the credential owner and keeps MCP sending disabled.

## Outcome and limits

A reviewed account-setup workflow may complete after the native assistant
accepted a correlated credential write even when its non-secret profile is
unchanged. The completion says **native credential write observed**, not that a
password differs, authentication currently succeeds or mail was delivered.
Diagnostics remain independent observations. A native page visit, successful old
authentication, existing tokens, background refresh or abandoned wizard never
satisfies this proof. An explicit save of the same value may count as a write;
credential-change remains unverified.

No credential, provider code, token or hash of one enters MCP arguments, results,
plans, journals or evidence tables. Native encryption, HTTP/IMAP/SMTP clients and
the existing assistant remain the only credential implementation. No new tool or
grant is added. Current original setup, completion and connection-read permissions,
account ownership, provisioned-account restrictions, TLS/native host policy, intent
and the A/D journal still apply.

## Approaches and implementation choices

The existing diagnostic cannot prove a write. Generic account-token dirty fields
would count automatic refresh and fail on abandoned workflows. The chosen native
extension records only correlated successful manual save paths, with a private
native revision counter invalidating intervening credential/identity changes.
This is an implementation choice within the authorized native-assistant approach.

Use v7 of the pinned Mail5.12.5 dependency (custom version 5.12.5.7), preserving
immutable v6 and v1–v5 manifests/artifacts for their existing scopes. New
credential evidence stays closed until actual native acceptance. Initial
qualification is NC35.0.2 and primary unsharded/unreplicated InnoDB only.

## Native ownership

A native SetupEvidence service registers an opaque context bound to owner,
original MCP connection scope/operation, create/configure kind, exact target
account when known, non-secret intent and at most 24 hours. Re-registration of
the same source returns the same context only for identical inputs. Bound
pending contexts to 100 per owner and use the existing owner-session coordinator
for admission; an expired context grants no proof. Context IDs are correlation
handles requiring native login, never authentication authority.

Opening the qualified native Page link activates a context through a core
HttpOnly/SameSite Lax cookie, Secure on HTTPS, scoped to the configured Nextcloud
webroot and expiring no later than its context. The assistant's credential forms
and existing CSRF protection remain unchanged. The Page initial state also carries
its opaque context handle. The exact native create/update/state API producers
attach this page marker, and all three admission hooks require it to match the
HttpOnly cookie. A stale page or ordinary native page without that marker can
still perform normal native actions but cannot bind another handoff’s evidence.
Callbacks retain their reserved original state and never rebind from the cookie.
The native context validates owner,
kind, target, expiry and intent; absent/foreign/expired handles refuse without
revealing another account. Multiple tabs may invalidate an older handoff safely;
a different owner/account/intent can never obtain its proof.

Native MailAccountMapper increments a hidden monotonic mcp_auth_revision (including the connection's display name) in the
same short primary transaction as credential or inbound/outbound identity writes.
The field is not serialized. Inserts initialize a positive revision when auth
fields are supplied; ordinary unrelated settings do not advance it. Never
implement this using secret digests. Credential mapper work participates in the
existing owner coordinator, reusing nested owner scopes and refusing inherited
filesystem transactions. Background refresh advances the revision but records
no manual proof.

AccountsController records password evidence only after SetupService persisted
explicitly supplied password fields successfully, binding a create context only
through the actual create route. SMTP-only saves do not imply authentication:
native SetupService tests connectivity only when an IMAP password is supplied.
A native persistence success followed by proof failure remains unverified;
never record proof before the credential write.

OAuth generateState binds the exact signed-state hash, account and original
context for at most the native 600-second TTL. At most 32 states may be bound during one
handoff's lifetime; further native OAuth can proceed normally but cannot attest
this workflow. This bounds new correlation storage without retrying credentials. Pinned native states are otherwise
deterministic within a second. A versioned random nonce is therefore covered by
the same native HMAC, making each new state unique. Legacy native states still
validate for ordinary OAuth completion but cannot attest a new manual context.
The stored state digest is correlation metadata; no provider code or access/
refresh token, or digest of those credentials, is stored. Manual Google/Microsoft
finishConnect receives an internal transient permit and marks it accepted only
in the successful token-response branch. Both current native methods return the
UNCHANGED account on missing configuration/provider errors, so controller status
or token presence cannot count. After account persistence, the controller records
proof only from that accepted permit and exact reserved state. Refresh has no
permit. State binding is one-shot and cannot migrate to a later context, including
a same-account context opened in another tab. Token/code contents stay native.

Native proof metadata includes only context/source identity, owner, account,
protocol/write kind, revision and time. A failed/expired context or revision change
does not get inferred from current authentication. Context/proof APIs return no
native client. Source/schema checks include the mapper, manual success branches,
cookie/state handling and participating tables.

## MCP integration and completion

Add one optional native_context_id to the private setup workflow table. Legacy
rows/stock/v6 retain their previous behavior and cannot claim new evidence.
MailAccountSetup.begin registers the qualified native context within the existing
approved/journalled admission, stores its handle privately, and returns only the
native assistant link. A lost admission response remains uncertain; status/replay
never registers a second context or saves credentials.
The existing operation detail may recover the same pending native link and the
bound account ID after current source/read grants, connection scope, expiry and
native ownership are rechecked. Listing receipts remains minimal. Recovery never
activates the context, probes authentication or restarts a provider exchange.

Preview completion rereads a valid current native proof and includes a non-secret
summary in its confirmation state. Unchanged-profile completion requires this
proof. A changed profile or ordinary account creation keeps the existing D
completion behavior, without manufacturing credential proof.

When proof is used, completion locks/rechecks the native account revision and
context, claims the one-shot proof and completes the MCP workflow/source receipt
in the existing primary transaction. Verify exact connection identity and InnoDB
participants. Recheck the native physical owner session immediately before the
shared SQL commit. The pinned NC35 connection disables idle reconnect while a
transaction is active; lost sessions must abort rather than continue writes on a
new connection. Revocation, expiry, changed intent/account/revision, concurrent
completion and lost acknowledgments remain conservative. No force-complete or
native credential retry is introduced.

## Verification and delivery

Use failing unit/native cases before implementation. Prove the actual native
password HTTP/browser save, plus real native state/provider/token-encryption/
mapper callback behavior for both OAuth providers. External provider responses
may come from run-local TLS fixtures with disposable-container-only DNS overrides;
never contact real accounts/providers or replace product transport. Capture only
non-secret outcomes. Test failed manual exchange with valid OLD tokens, automatic
refresh, old/reused/expired/wrong-owner state, multi-tab/new-context substitution,
SMTP-only write wording, revocation, unchanged-profile diagnostic-only refusal,
atomic completion and response loss.

Run full native Mail/browser and stock regression, immutable v6 source gates,
both static/API/architecture scopes and complete devbox catalog. One fresh final
review covers this independent slice, followed by one consolidated fix pass.
Retain clean-source packages and artifact hashes; no live installation, push, PR,
release, remote preview or sending. NC33/34 qualification follows separately.
