<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Inspector encrypted storage across issuers

Status: **qualification executed; 14 cases passed, 1 required isolation case
failed; exit 1**. The encrypted, issuer-bound token and registration paths work
across fresh processes. Preregistered confidential-client credentials remain
resource-scoped and are returned for another requested issuer. That finding blocks
a blanket claim that every stored credential is issuer-isolated. It is not a
finding of network exfiltration or an established Nextcloud application defect.

HTTPS ingress, ChatGPT and human review remain pending. No containers, listeners,
infrastructure, exposure, outbound policy, installed client dependency or
application code were changed. No push or PR was opened.

This completes the previously missing disk-persistence investigation from the
[private TLS report](2026-10-19-private-tls-inspector-report.md), with the failed
property kept visible. It does not close every P1 security criterion or replace
P2–P4 from the [acceptance proposal](2026-10-19-https-ingress-client-validation-proposal.md).

## Scope, inputs and reproduction

```sh
composer --working-dir=mcp test:clients:storage -- /absolute/inspector-install
```

The opt-in command uses Inspector **2.11.0**, Node **24.21.0** and the existing
installed dependency tree. The lock SHA-256 is
`d6e59c49bd1a5640d33d7d8c77a387e382553762e216173373c0f2af86c704ae`;
the exact installed CLI bundle SHA-256 is
`bd48a8df27412e4c01e876493a4e629d84d823ed44f0e738751a7efa3a8d18ca`.
Both are checked before execution. Inspector has no public export for these
internal storage classes, so the harness copies the **entire unchanged bundle**
privately and appends one export declaration. The original bytes are retained;
there is no replacement crypto, storage backend, issuer lookup or client-provider
implementation. The copied module is imported, so its CLI entrypoint does not run.
Dependencies resolve to the existing installation. The CLI bundle is hash-checked
unchanged; this does not independently hash every installed dependency.
No third-party implementation is vendored into this repository.

Every case runs in a new Node process. It instantiates the installed
`FileSecretStore`, `NodeOAuthStorage` and `BaseOAuthClientProvider`, with actual
AES-256-GCM, scrypt, filesystem persistence and locking. Access tokens, refresh
tokens, client secrets and key material are randomly generated **synthetic**
values stored privately. They have no validity at any server. The same resource
URL is used with three distinct `.test` issuers; this tests issuer separation,
not merely separate resource keys. No DNS resolution or OAuth server is involved.

Private run `storage-pzBfwA` and its
[sanitized results](../contracts/inspector-encrypted-storage-2.11.0.json) record
all 15 required outcomes, process exits, source hashes and private stream hashes.
Private raw results live under `~/.local/state/mcp-client-tests/storage-pzBfwA/`.
Do not upload that directory: it includes key files, fixtures, encrypted stores,
error details and the instrumented copy. Only the curated JSON belongs in Git.

## Observed results

| Required cases | Outcome |
| --- | --- |
| Preflight | PASS: exact lock, bundle and runtime; installed bundle unchanged after execution. |
| Seed A / reload A | PASS: access token, refresh token and issuer-bound client secret persist; a new process reads A exactly and obtains no credential for B. |
| Seed B / reload both | PASS: A and B retain distinct values and issuer stamps for the same resource; absent issuer C cannot retrieve either registration or tokens. |
| Rotate A / reload | PASS: A's replacement persists, while B's token pair and client secret remain unchanged. |
| Clear A / reload | PASS: A's tokens and registration remain absent after process restart; B is preserved. |
| Wrong key | PASS: the provider refuses reads and writes with `SecretFileKeyMismatchError`; direct secret-store writes/deletion also refuse, retaining the encrypted bytes. |
| Missing key | PASS: same explicit refusal and byte preservation; no plaintext fallback. |
| Altered ciphertext | PASS: changing one ciphertext byte makes the authenticated envelope unreadable; the exact error is asserted, writes/deletion refuse and the altered evidence is not overwritten. |
| Read after controls | PASS: original healthy store still reloads B and keeps A deleted. Negative probes used isolated copies. |
| Save static registration | PASS: the preregistered client secret is encrypted on disk. |
| Reload static registration under another issuer | **FAIL**: a new process retrieves the same preregistered secret through both issuer contexts, with no issuer stamp. Required refusal assertion fails. |

Healthy-store cases assert the real on-disk envelope identifies AES-256-GCM and
scrypt, decrypted readback succeeds, stored files have private permissions, and
none of the generated secret values appears in plaintext in either metadata or
secret files. These checks demonstrate the exercised storage path; they are not
a cryptographic audit, OS-compromise defense or exhaustive filesystem-fault test.

An initial run, `storage-FnCy9C`, correctly exposed the same static-registration
boundary but also failed three negative probes because the harness expected empty
credentials. Inspection showed the real integrated backend **throws** on an
unreadable secret store. The probes were corrected to require that exact error
for reads and writes, plus byte preservation. The initial failures remain retained;
they were not reclassified as passes. The final run still exits nonzero for the
actual failed static-registration property.

## Finding and disposition

The installed provider first asks storage for `preregisteredClientInformation` by
resource. If present, it returns that registration before consulting the requested
issuer's registration slot. The preregistered record has no issuer key. This is
consistent with the unstamped-registration warning previously reproduced; encryption
protects the file's contents but does not add an issuer boundary to that lookup.

The integration fixture proves **cross-issuer return of a confidential static
client secret through the provider after disk reload**. It does not prove the SDK
sent that secret to an untrusted authorization server, nor test a metadata attack,
public-client tool flow or two real authorization-server exchanges. Determining
network impact requires that separate bounded flow and upstream's intended
preregistration contract. No CVE or general exploitability claim is made.

The frozen Nextcloud candidate advertises public-client `none`, and the real
Inspector acceptance used a registered client identifier without a client secret.
Those successful flows are not retroactively claimed to have leaked a secret.
Issuer-bound access/refresh tokens and registrations passed this separate disk
qualification; the static-secret failure remains an **external client acceptance
blocker for a universal credential-isolation claim**.

Next technical action: resolve the static-registration contract with Inspector or
qualify a corrected pinned client version, then rerun this same failing case and
the relevant actual-client journeys. Any report or proposed change to that project
must be prepared separately; nothing was sent, pushed or opened here. Do not
suppress the SEP-2352 warning, alter the frozen server's `iss` behavior, replace
the failed assertion with an expected pass, or silently upgrade the dependency.

## Validation and pending work

The storage command is separate from ordinary `check`, as it requires the external
pinned installation and tests an external client's unresolved property. Its
nonzero result is explicitly recorded above. Fresh repository validation passed:

| Check | Result |
| --- | --- |
| Composer manifest/lock validation | Passed. |
| `docs:check` | 196 Markdown files / 110 package sources. |
| `test:docs` | 29 passed, none skipped. |
| `test:clients:unit` | 10 passed, none skipped. |
| `DeveloperCommandsTest.php` via `composer test` | 5 tests / 40 assertions. |
| `composer package` | Archive built and verified; 110 documentation sources; translation provenance valid, 96 review-required. |
| REUSE 5.1.1 | 1,550/1,550 files, no copyright/license/read errors. |
| Source/evidence identity, fixture-secret scan and `git diff --check` | Passed. |

An independent automated review confirmed the external static-credential finding
and found no additional Important issue in the diagnostic runner. That is not a
human security approval. The unchanged application's earlier full PHPUnit,
browser and native-matrix results are carried forward with their recorded
identities; they were not rerun for this storage-only test addition. Ordinary
repository/package success cannot turn the external-client failure into a pass.

Pending:

- Inspector: static preregistered credential scope/issuer warning and any resulting
  network-impact qualification. Storage investigation is complete for the cases
  listed; overall client security acceptance remains open.
- HTTPS: supported per-exposure application authentication, public transport and
  external-vantage qualification remain unexecuted.
- ChatGPT: account/workspace access, real CIMD/OAuth flow, tool calls and cleanup
  remain unexecuted; no account access was requested for this storage-only check.
- Human security, accessibility and language reviewers remain unassigned/pending;
  no automated result assigns approval, including the 96 review-required translations.

## Subsequent commit review

The [final opening checklist](2026-10-19-final-pr-opening-checklist.md) records
fresh documentation/package validation after adding that checklist (197 Markdown
files and 1,551 REUSE files), without rewriting the original evidence above.
A repeat run `storage-1d450D` used identical runner source hashes and reproduced
14 passed / 1 failed / 0 blocked, exit 1. The sole failure remains
`static-client-reload`; the installed CLI bundle remained unchanged. The review
also corrected the scope of the hash claim: lock and CLI bundle are checked,
not every installed dependency file. No production code correction was justified
by this component-only finding.
