# C7 Mail no-send acceptance plan

Baseline: `6a5c554d270dbf89d70f47291de4f9349770d151`, MCP2.7.0. Execute the
approved C7 acceptance section of the master completion plan. No new runtime
tool, grant, native artifact, delivery path or deployment is proposed.

## Scope and interfaces

A/D local drafts, Files snapshots and native settings compose with C1/C2/C3/C5/C6
through the existing journal. C7 must accept only implemented guarantees, preserve
held replies/Files bytes and leave sending disabled. C0's local-only reply/alias
limits, D+ uncertainty and native thread-rebuild limits remain explicit.

- Native guarded pair: NC35.0.2 / Mail5.12.5 / MariaDB10.11.19 with exact v5
  source and migrated primary storage. Original v1–v4 scope fingerprints remain
  unchanged. Stock Mail retains its supported A/D/local capabilities and refuses
  guarded remote writes.
- NC33/34 Mail combinations have no native cell in the current runner; label
  their native behavior unverified. Static API fixtures do not qualify them.
- Use the selected existing worktree. Keep ledger/test logs outside the repository;
  generated integration artifacts remain under mcp/build. Use Node24 and
  project-local test TMPDIR. No public ports, credentials or live mail accounts.

## Task 1 — Cross-delivery acceptance and focused regression

- [x] Add one actual HTTP/native workflow: local reply + Files attachment, signature
  and role changes, flag/tag assignment, explicitly reviewed legacy-cache reset,
  archive and destination refresh. Verify held parent/body/attachment identity,
  unchanged Files original, no outgoing/MDN/SMTP effect, read-only replay/status and
  current source authorization. Use existing helpers and exact owned fixture cleanup.
- [x] Run existing focused Mail/journal/Files contracts. These protect the separate
  failure and concurrency cases; do not duplicate them merely to increase coverage.
- [x] Validate the new existing-behavior acceptance test with a temporary test-only
  parent-corruption negative control, then remove it. Never release a draft hold or
  send/enqueue a message to prove the no-send boundary.

## Task 2 — Native acceptance matrix

- [x] Preview the runner for NC35/MariaDB/fresh/Mail, then execute the full Mail
  namespace with v5 and with stock Mail, serializing native cells.
- [x] Record executed, expected-skipped and unverified cells honestly; preserve
  native lifecycle/fault evidence and exact immutable artifact digests.

## Task 3 — Final catalog and release evidence

- [x] Run the complete devbox check after focused/native acceptance succeeds.
- [x] Update master plan, public Mail reference and C7 evidence with actual tools,
  no-send guarantees, budgets, version/provider matrix and manual inspection limits.
- [x] One fresh final read-only review; one TDD fix pass for material findings, no
  duplicate review. Preserve current branch/worktree, locally commit after checks,
  and package from clean source. No push, PR, publication or deployment.

Completed: native v5 49/1,302, stock 17 executed + 32 expected skips / 422
assertions, catalog 10,242 PHP / 93,724 assertions + 837 browser tests, both static
scopes, docs and package. One fresh final reviewer found no actionable findings.
No runtime/native code, qualifier, schema, permission or delivery-path change.
