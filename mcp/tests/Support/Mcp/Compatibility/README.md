<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Shared MCP contract captures

Captured from unchanged production at `2f80fc8` on PHP 8.4.26. All identities,
content and failures are synthetic. `SharedMcpMigrationCompatibilityTest` compares
complete bytes; it never writes snapshots. English, Brazilian Portuguese and
Spanish publication includes all nine catalogs, built-ins, guides, resources and
prompts under default, denied, full and alternative link grants. Protocol captures
include both eras and malformed envelopes; results include read, preview, write,
changed-plan and failure envelopes. JSON object/array distinctions are retained.
Invalid UTF-8 is asserted in PHP rather than normalized in input fixtures.

The dynamic tests and original ToolRegistry, protocol, PlanState, SharedWriteGuard,
VisibilityGuard and LockAwareWrite suites additionally exercise audit failure,
role/app changes, confirmations, ETags, native events, partial tag mutations,
share aliases, backup privacy and lock rechecks. Their original expanded cases
are reconciled in the execution report. These existing security tests remain
mandatory gates; snapshots do not replace them.

Do not regenerate these files after extraction. The Notes migration separately
approved two internal exception identities in its older captures.

The 2026-10-17 local closure corrects one public Deck guide sentence in six
`publication.json` entries (English guide text under the three locales and the two
applicable grant contexts). It now describes the already-supported movement between
lists and boards. Only those seven string values across `publication.json` and the older
`tests/fixtures/deck-definitions.json` capture were edited by hand; schemas, plans,
protocol and result captures are unchanged. The guide contract first failed on the
revised expectation; both complete contract comparisons pass after the correction.
The fixture changes and their source revisions remain traceable in Git history.

The 2026-10-20 upstream 1.1.1 integration advances only the 29
`io.modelcontextprotocol/serverInfo.version` values in `protocol.json` from
`1.1.0` to `1.1.1`, matching the new manifest and protocol metadata. Every other
fixture byte remains unchanged. The full frozen-contract test rejected the old
values before this explicit release-version update; the focused protocol and
shared-contract suite then passed 24 tests / 143 assertions. The captures were
not regenerated or normalized.

The 2026-10-21 upstream 1.1.2 integration advances the same 29 server-version
values from `1.1.1` to `1.1.2`. No other bytes in the four compatibility captures
change. Guests restrictions are exercised by behavioral unit and native tests,
not by regenerating the non-guest captures.

Batch B of the selected upstream 1.2.0 port changes only Notes edit input
properties/descriptions and Notes/Deck guide notes, plus the Deck deadline input
description. These explicit fields were patched in place; existing plan, result
and protocol fixtures were not regenerated. The new partial-write and timed
deadline behaviors have focused regression and native persistence tests.

Batch C adds only the four unconditional label/file tools to the reviewed Deck
catalog captures, updates the Deck-only guide count/line and appends its new
behavior note. The protocol capture changes only the same additive tools in
`tools/list` arrays; every existing tool object, server identity and other
protocol field was compared unchanged. `results.json` remains byte-for-byte
unchanged. Dependency discovery has separate supported/unsupported capability
tests; the metadata-only capture has no verified native capability and therefore
keeps those three tools unavailable. Existing factory/catalog equality still
covers all twenty declared classes.

The F3 local source-offer implementation appends only the explicit source-delivery
unavailable notice to the twelve discovery instruction strings in `protocol.json`.
Configured delivery has separate identity/link tests. Tool definitions, results,
server identity and authentication/authorization expectations are not regenerated.

The 2026-10-27 upstream 1.2.1 U2 port updates the four Files overwrite descriptions,
Files/Contacts guide notes and two checkout-prompt sentences to describe the
administrator's copy policy and configured destination accurately. Only those
literal strings are replaced in publication/protocol captures; tool names,
schemas, grants, annotations, ordering and result captures are retained. The
older Files definitions/pilot captures receive the same description replacements.
Policy, Files flow, browser and native tests exercise the behavior independently;
these captures were not regenerated from the new implementation.

The 2026-10-27 U3 port adds only Tasks parent create/edit schemas, direct-child
listing schema/description and hierarchy guide notes to the relevant captures.
DAV plans/results add `parent: null` and empty `parents` title maps for the
existing top-level create/edit cases. Calendar/Contacts contracts, grants,
existing renderings and all unrelated result/protocol fields remain unchanged.
These explicit additive patches are covered independently by the pinned 1.2.1
subtask, hierarchy/lock and native CalDAV cases; captures were not regenerated.

U4 (upstream 1.2.1) appends only the two static Mail definitions to each locale's
raw catalog. Existing published tools/protocol/results are unchanged because the
historical fixture has no known Mail version; focused Mail publication/native tests
exercise enabled, explicitly granted Mail. People/Logs captures append only
`MailModule` to Application's module order. No complete capture was regenerated.
