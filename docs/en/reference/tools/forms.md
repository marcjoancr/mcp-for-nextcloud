<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Forms

Forms tools require Forms 5.3.0 or later, availability for the authenticated account, explicit tool assignments and selection in the connection. Native Forms permissions are checked again for every request.

`forms_list` lists accessible forms; `forms_read` reads questions and options. Both use `forms.read`. `forms_read_responses` requires the separate `forms.read_responses` operation and the native permission to view results. Being allowed to submit a form or view only one's own submission does not grant access to the full result set.

## Summarize responses

`forms_summarize_responses` requires `forms.summarize_responses`. Pass `id`, with optional `offset` and `limit` (default 50, maximum 100 submissions). It returns deterministic answer counts by question for that page, with `submissionsAnalyzed`, `partial`, `hasMore` and `nextOffset`. Follow the returned offset to continue; a page summary is not a whole-form summary. At most 50 question metadata records and 200 answers per submission are considered, with a ceiling of 8,000 scanned answers and 4 MiB of answer text. Text and scan limits may also make results partial.

The summary contains no respondent identifiers. Uploaded file answers are omitted; no file is opened or downloaded. Native results permission is required even when the caller can already read the form's questions.

## Export responses to Files

`forms_export_responses` requires `forms.export_responses` **and** `files.create`, as well as access to the form results and the destination folder. Pass `id`, a new `.csv` destination `path`, and optional `offset` and `limit` (default 50, maximum 100 submissions).

The first call prepares a preview without writing. Show the source page, destination and partial-result warning to the user. After approval, repeat the same arguments with `confirm: true` and the returned plan state. A changed source page or destination requires a new preview. A shared destination also requires the existing Files shared-resource confirmation. Existing files are not overwritten.

The CSV has one row per answer, with submission ID, timestamp, question ID, question and answer. Respondent identities and uploaded file answers are excluded. Cells that could be spreadsheet formulas are neutralized. The export is bounded to 1 MiB and 8,000 rows; partial results are explicitly indicated. Question and answer text cells are capped at 1,024 bytes, with truncation and omitted-row details in the preview. Files scope restrictions and native folder permissions still apply.

[All tools](../tools.md) · [Permissions](../../guides/permissions.md)
