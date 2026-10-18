<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Operational documentation translation report

Follow-up to the [documentation migration report](2026-10-17-documentation-i18n-report.md).
The user authorized preparation of Spanish, Catalan and both Portuguese variants,
with English provenance and explicit human-review requirements. No push or deployment.

## Scope and review policy

The operational set contains 24 English sources: root and app READMEs, the locale
index, installation, operational guides and references. Spanish (`es`), Catalan
(`ca`) and European Portuguese (`pt-PT`) receive matching pages; the existing 24
Brazilian Portuguese (`pt-BR`) pages are retained. Development documentation and
historical plans/reports remain English-only under the agreed scope, with explicit
English links from each locale index.

New translations use English source revision
`890ca28bb0d327fb063dea472fc579570eb137c4`. This real ancestor contains the corrected
configuration links. Existing Brazilian Portuguese provenance remains at its
original source revision, `0c95ccfd3a13339521c1470883edfdcf8138bd98`.
These revisions identify the original translation bases, not human approval. The
subsequent technical review compared all 96 pages with the current English sources.
English changes add locale navigation and correct a stale lock-helper path and
three app README topic destinations; those corrections also appear in all locales.
Source revisions remain unchanged while human review is pending.

The user's request to prepare translations while identifying human-review needs
supersedes the original reviewed-only rule for adding new locales. Drafts can now
be versioned and bundled with `translation-review: pending`, a visible localized
review notice and an equivalent English source link. No page is automatically
marked reviewed. The current architecture and translation-maintenance policy record
this change; historical migration plans retain their original acceptance context.

## Validation design

The existing parser-backed checks retain real source/ancestor validation, equivalent
page navigation, link/fragment checks and exact package inventory/byte verification.
The explicit manifest gains `pt-PT` and the new operational pages. No runtime UI
catalog or application behavior is changed.

Behavioral tests first reproduced rejection of the new locale and draft state.
They now cover pending pages in all four locales, missing or hidden human-review
notices, and partial European Portuguese archive navigation with English fallback.
Warnings embedded only in comments or code do not count as visible prose. Invalid
provenance still fails, while missing/stale coverage remains advisory. Existing
reviewed pages that become stale retain their advisory review state.

## Review ownership and limits

Translations are AI-assisted drafts. Technical checking and cross-language drafting
do not constitute independent human linguistic approval. Human reviewers should
check terminology, grammar, UI labels, permission/security caveats and client setup
instructions against the recorded English revision. Portugal and Brazil remain
separate variants; translated prose must not change claims about supported UI
languages. API identifiers, literal keys and code examples retain their English
contracts.

## Execution results

Preparation and local validation are complete. No translation is certified as
human-reviewed.

| Locale | Operational pages in repository | Review state | Packaged pages |
| --- | --- | --- | --- |
| English | 24 | Canonical source | 21 operational pages + app README |
| Spanish | 24 new | Pending human review | 21 operational pages + app README |
| Catalan | 24 new | Pending human review | 21 operational pages + app README |
| Portuguese (Brazil) | 24 retained | Pending human review | 21 operational pages + app README |
| Portuguese (Portugal) | 24 new | Pending human review | 21 operational pages + app README |

The root README and full locale index stay repository entry points; the archive
contains the operational index and complete allowlisted navigation. Language bars
link equivalent pages across all five locales. The documentation entrance labels
translations as drafts, and new pages show the review notice before their content.

Validation was repeated after the technical corrections using PHP 8.4.26,
Composer 2.8.8 and pinned Node 24.21.0. Each command below exited successfully:

- `composer --working-dir=mcp docs:check`: 167 Markdown files, 110 package sources.
- `composer --working-dir=mcp docs:translations`: 96 review-required pages and no
  missing operational translations. Invalid provenance is still an error.
- `composer --working-dir=mcp test:docs`: 29 tests pass, none skipped or cancelled.
  The changed locale/draft-policy tests failed before the validator change.
- `composer --working-dir=mcp package`: actual archive built, inspected and its
  extracted documentation verified for exact inventory, bytes and local links.
- Offline `fsfe/reuse:5 lint`: all 1,212 files have copyright and license metadata.
- `git diff --check` and a whitespace check including the new untracked Markdown:
  pass.

Additional inspection of the actual tarball confirmed 21 operational pages and one
app README per locale, no developer/history bundle, the correct configuration links
and retained pending-review markers in every translated package page. The corrected
README topic links and lock-helper paths were also verified inside the archive.
The archive
SHA-256 is `ff2da8bec1dfc64ca5f49d1b1a815e15f2b438b1a2997a90f09883647bebcf75`;
this identifies the local artifact and is not a reproducible-build guarantee.

Checks against the English sources confirmed matching SPDX headers and fenced code
examples in all 72 new pages, preserved technical identifiers and no long English
body paragraphs left as translation placeholders. These are technical checks, not
proof of linguistic accuracy. During drafting, checks caught translated filenames,
changed heading fragments, lost example content and mechanical Portuguese prose
errors; those were corrected before the successful package validation. The follow-up technical review below corrected further omissions, inconsistent
examples and semantic defects before the validation recorded here.
No application code or runtime UI translations changed, so the PHP/browser suites
were not rerun for this documentation/tooling follow-up.

## Translation review follow-up

The user subsequently authorized a technical review, corrections and a local commit
following documentation and package validation. All 24 pages in each of the four
locales were compared with the English sources. The review corrected:

- Spanish: the omitted literal `type` response key in People and a redundant OAuth
  heading.
- Catalan: server-log API strings and inline JSON formatting.
- Brazilian Portuguese: untranslated README sections and reference labels, a
  translated backup-path pattern, and command placeholders. Fenced examples and
  the installation placeholder explanation now match English.
- European Portuguese: an inverted empty-upload exception (an empty body must be
  rejected with HTTP 400 when the plan declared a positive size), the corrupted
  `admin_audit` identifier, the `iCalendar` format name, and wording about lock
  behavior, undo availability, administrator eligibility, ACLs and required apps.
- Every locale and English: the stale lock-helper path and app README links for
  Deck, Calendar diagnostics and images now point to the current implementation
  path or dedicated documentation topic.

A further cross-locale check found matching fenced examples across all 96 pages
and no missing underscored technical identifiers from their English counterparts.
These checks supplement the page-by-page review; they do not establish linguistic
accuracy. All 96 pages retain pending metadata, visible notices and their original
source revisions. No human reviewer has approved them.

## Remaining work

- Independent human linguistic review of all 96 translated pages, prioritizing
  installation, client setup, permissions, file protection and operational limits.
  Review each Portuguese variant separately. Record the reviewer and exact source
  revision before changing a page to `reviewed`.
- Check UI-label wording against actual localized clients during human review;
  source client examples were translated, not exercised against live services.
- Actual GitHub rendering and CI remain unexecuted until publication is authorized.
  The earlier real-Nextcloud and full-runtime validation gaps remain separate.
- Development guides and historical plans remain intentionally English-only;
  there are no empty translated development trees.

No push, pull request, publication or deployment was performed. The translation
drafts, validator support and technical corrections form one documentation change
for the authorized local commit after successful validation.
