<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Documentation translations

English is the canonical source. Operational documentation supports Spanish (`es`),
Catalan (`ca`), Brazilian Portuguese (`pt-BR`) and European Portuguese (`pt-PT`).
UI catalogs remain independent: English source text plus `ca`, `de`, `es` and `pt_BR`.
German UI support does not create a German documentation tree. The German catalog
and its rendering in the administration interface still require human review.

Draft translations may be versioned and included in the operational package when
explicitly marked `translation-review: pending`, with a visible human-review notice
and an English source link. This supports preparation and review without pretending
that human approval has occurred. Only a human reviewer may certify `reviewed`.
Use English filenames in each locale and language names instead of flags. Missing
pages link explicitly to English; contributor documentation may stay English-only.
Do not add empty translated pages or silently link a different-language landing page.

Every translated page records its canonical repository path, a real full source
commit, and `translation-review: pending` or `reviewed` in HTML comments. Commit
English source first. The source revision must remain an ancestor of HEAD after
integration; preserve these source commits instead of squashing them away. If
history changes, establish a durable source commit and compare again before updating
metadata. Do not manufacture a SHA or automatically certify language review.

```markdown
<!-- translation-source: docs/en/guides/permissions.md -->
<!-- translation-source-revision: FULL_SOURCE_COMMIT_SHA -->
<!-- translation-review: pending -->
```

The token above is illustrative. Published metadata must use a real revision where
the source path exists. Compare source bytes at that revision with current bytes;
an unrelated commit does not make a translation stale. Pending or changed content
needs a visible notice and an English source link. A reviewed unchanged source
means current provenance, not automated proof of translation quality.

Missing/stale translations are advisory and do not block ordinary development.
Invalid metadata, missing history and broken links fail validation. Full Git history
is required; the validator never fetches it silently. English authors add a review
notice when they know a translation is stale, without needing to translate the
change. Human reviewers may advance the revision and remove the notice after review.
Record the reviewer and reviewed pages in the pull request review.

Prioritize overview, installation, clients, permissions and troubleshooting.
All translated pages remain explicitly pending until human review. Pending pages
without a visible notice fail validation, including notices hidden in code or HTML
comments. A changed previously reviewed page remains an advisory review request.
Use prose notices beginning “Traducción pendiente”, “Traducció pendent” or “Revisão
pendente” / “Revisão humana pendente”, followed by the equivalent English source link.

## UI and store-description provenance

UI catalogs are independent of documentation translations. Preserve the existing
source/provenance comments and contributor credits when adapting messages from
upstream. New messages and rendered UI still require human review; an automated
catalog or documentation check does not certify language quality.

English, Brazilian Portuguese and Spanish store descriptions include selected
upstream capabilities with fork-specific limits. German and other localized
claims must be checked against the current code rather than copied from a
historical feature list. Contributor documentation stays English-only.

The operational coverage set consists of root/app READMEs and every page under
`docs/en/` except `development/`. Keep each Portuguese variant independent:
adapting Brazilian spelling alone does not establish European Portuguese review.

## Tool titles and catalog checks

Every registered tool needs a literal English title in
`mcp/lib/Mcp/Tools/Discovery/ToolPresentation.php` and matching entries in each
shipped UI catalog (`ca`, `es`, `pt_BR`, `de`), in both JSON and JavaScript. Technical
tool names and permissions remain unchanged. The humanized-name fallback is for
unknown tools; it does not translate a registered tool whose title is missing.

`ToolPresentationTest::testEveryCatalogToolHasATranslatedTitleInEveryShippedLocale`
reads every module catalog and checks the rendered titles against each language
file. The Forms submission-form reader and single-own-response reader must have
separate titles from the general form reader and full-result reader.

After changing UI catalogs, build the assets and run the focused checks from
`mcp/`:

```sh
npm run build
vendor/bin/phpunit --filter 'L10n|ToolPresentationTest'
node scripts/docs/cli.mjs check
node scripts/docs/cli.mjs translations
```

Titles and permission labels use the authenticated account's UI language. The
English tool descriptions and parameter schemas sent to the model are a separate
contract; the model is instructed to explain them in the user's language. A
passing coverage check verifies lookup and catalog consistency, not human review
of the wording.

The initial Catalan catalog covers every source key, with two plural forms. It
was prepared from the Spanish catalog using the Apertium Spanish–Catalan IEC 2017
linguistic data, followed by technical terminology and tool-title review. Keep
interpolation fields, protocol identifiers and code examples unchanged. Automated
key/placeholder checks do not replace the pending human linguistic review.
Catalan operational documentation remains explicitly marked as pending review;
contributor-only documentation may remain in English.
