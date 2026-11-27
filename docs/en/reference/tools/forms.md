<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Forms

Forms tools require Forms 5.3.0 or later, availability for the authenticated account, explicit tool assignments and selection in the connection. Native Forms permissions are checked again for every request.

`forms_list` lists accessible forms; `forms_read` reads questions and options. Both use `forms.read`. `forms_read_responses` requires the separate `forms.read_responses` operation and the native permission to view results. Being allowed to submit a form or view only one's own submission does not grant access to the full result set.

## Official Forms package

The `app.mcp.official-apps.forms` system setting selects the official package
integration. It is disabled by default. The CSV tool writes the reviewed page
to a new file in Files. Guarded attachment lifecycle, comments and linked export
workers remain unavailable in this mode. The release source kit documents the
coordinated Forms transition.

For a Nextcloud Basic login, the MCP uses Forms OCS v3 on the instance configured
in `overwrite.cli.url`. Configure an HTTPS URL with valid trust for that
instance. The read checks the current OCS user before requesting Forms data.
A denied or failed OCS request fails the tool. MCP OAuth and browser sessions
use the native reader in the authenticated request. An MCP bearer token has
an audience limited to the MCP endpoint.

Both readers require explicit results permission for the all-results tool.
Forms can continue to show a submitter their own responses after results
permission is withdrawn. The MCP does not treat those rows as an all-results
page.

With qualified Forms 5.4.4 or 5.4.5, the MCP also offers these v3 reads
after tool assignment and connection selection:

| Tool | Native access and output |
| --- | --- |
| `forms_read_settings` | Current form access. Bounded settings and question metadata without share secrets. |
| `forms_read_question` | Current form access. One question with an independently paged option list. |
| `forms_list_shares` | Form owner only. User and group share metadata without public-link tokens. |
| `forms_read_own_response` | Current form access and the authenticated user`s own submission ID. |
| `forms_read_response` | Current form access and explicit native results permission. Respondent identity requires the separate MCP permission and connection choice. |

Single-response reads omit file answers and answers whose question type cannot
be verified. The Basic transport calls Forms over HTTP. For MCP OAuth, the
authenticated Nextcloud session invokes the same stock OCS read actions
in-process. `forms_capabilities` reports the qualified `officialReadTools`.
App Store updates use the original Forms package. After an update to an
unqualified version, discovery falls back to the original tools until the
extended reads are checked against that version.

### Authoring on the official package

The same profile offers creation, private cloning, editing, publishing, closing
and deletion of forms, along with question and option authoring. An owner can
create or revoke a public link with submission permission. Results use the
separate response and summary permissions. `forms_capabilities` includes the
`officialWriteTools` list.

Confirm the exact preview using its `operation_id` and `plan_state`. Public
links, shared-resource changes and permanent form deletion require separate
confirmation. On confirmation, the MCP refreshes permissions and the snapshot
before calling Forms and reads back the acknowledged change. Forms does not
enforce that snapshot during the write. A lost or unverified result remains
`uncertain`. Repeating the approval returns the operation receipt without
sending the write again. Inspect Forms before attempting to reconcile an
uncertain result. A confirmed creation can leave a private blank form if the
title update was interrupted.

An existing question keeps its type. To revise a form with answers, clone it as
a new private version and keep the original results available. Question and
option removal requires an observed empty response set because Forms can omit
historical answers once a question is removed. Permanent form deletion also
removes its dependent native data and creates no recovery copy.

## Summarize responses

`forms_summarize_responses` requires `forms.summarize_responses`. Pass `id`, with optional `offset` and `limit` (default 50, maximum 100 submissions). It returns deterministic answer counts by question for that page, with `submissionsAnalyzed`, `partial`, `hasMore` and `nextOffset`. Follow the returned offset to continue; a page summary is not a whole-form summary. At most 50 question metadata records and 200 answers per submission are considered, with a ceiling of 8,000 scanned answers and 4 MiB of answer text. Text and scan limits may also make results partial.

The summary contains no respondent identifiers. Uploaded file answers are omitted; no file is opened or downloaded. Native results permission is required even when the caller can already read the form's questions.

## Export responses to Files

`forms_export_responses` requires `forms.export_responses` **and** `files.create`, as well as access to the form results and the destination folder. Pass `id`, a new `.csv` destination `path`, and optional `offset` and `limit` (default 50, maximum 100 submissions).

The first call prepares a preview without writing. Show the source page, destination and partial-result warning to the user. After approval, repeat the same arguments with `confirm: true` and the returned plan state. A changed source page or destination requires a new preview. A shared destination also requires the existing Files shared-resource confirmation. Existing files are not overwritten.

The CSV has one row per answer, with submission ID, timestamp, question ID, question and answer. Respondent identities and uploaded file answers are excluded. Cells that could be spreadsheet formulas are neutralized. The export is bounded to 1 MiB and 8,000 rows; partial results are explicitly indicated. Question and answer text cells are capped at 1,024 bytes, with truncation and omitted-row details in the preview. Files scope restrictions and native folder permissions still apply.

## Qualified native workflows

The catalog defines 65 Forms tools. The admitted subset depends on the installed
native companion, current permissions and connection selection. Use
`mcp_guide` with `module: "forms"` for the current schemas, required operations
and available tools; use `forms_capabilities` for runtime readiness and limits.
A tool missing from discovery cannot be enabled by guessing its name.

The original five tools above retain their Forms 5.3 API floor and page limits.
The additional guarded workflows below require the separately installed, exact
Forms 5.4.2 companion and qualified Nextcloud, database, storage and writer
configuration. A version
number alone does not qualify a build. Unknown or modified native sources disable
the affected workflows. Installation, source verification and rollback are
covered in `native/forms-guard/README.md` in the matching source bundle.

| Workflow | Tools and behavior |
| --- | --- |
| Discovery and metadata | `forms_search`, `forms_read_settings`, `forms_read_question`, `forms_capabilities`; follow question, option and result continuations independently. |
| Form construction | `forms_create`, `forms_clone`, `forms_update`, `forms_set_state`, `forms_set_lock`, `forms_release_lock`; cloning resets submissions, shares and the export link. Owner-only state and permanent-lock rules still apply. |
| Questions and options | Create, clone, update, hide and reorder questions; add, update, delete and reorder options. Reorders require the exact active set. Question type cannot be patched. |
| Sharing and links | `forms_list_shares`, `forms_read_links`, `forms_share`, `forms_update_share`, `forms_unshare`, `forms_generate_link_token`, `forms_get_embed`; a public capability grants submission access, not editing or result access. |
| Submission | `forms_read_submission_form`, `forms_submit_response`, own-response reads, updates and deletion; public structure requires an explicit live share hash. Submission rules, anonymity, quotas and question validation remain native. |
| Result management | `forms_read_response`, `forms_update_response`, `forms_delete_response`, `forms_delete_responses`; own-response and full-result authority are separate. |
| Answer files | `forms_upload_response_files`, `forms_list_response_files`, `forms_save_response_file`; upload handles come from confirmed staging of currently visible Files sources, not caller-supplied native file IDs. |
| Complete results | `forms_summarize_results`, `forms_download_results`, `forms_export_results`, `forms_link_results_file`, `forms_unlink_results_file`; these are separate from the older page summary/export tools. |
| Comments and administration | Native comment read/create/edit/delete and `forms_read_admin_settings` / `forms_update_admin_settings`, when individually qualified. Administrative access is enforced for the relevant settings. |
| Lifecycle and evidence | `forms_transfer`, `forms_delete_form`, `forms_restore`, operation history, `forms_read_operation_impact`, `forms_read_recovered_comments`, continuation and cancellation. |

There are 12 supported question creates: `color`, `date`, `dropdown`, `file`,
`grid`, `linearscale`, `long`, `multiple`, `multiple_unique`, `ranking`, `short`
and `time`. A grid requires its declared subtype (`radio`, `checkbox` or
`number`). Existing `datetime` questions remain readable and answerable; creating
that legacy type is not supported.

## Permissions and confirmations

Every new operation starts denied and every new tool starts unassigned and
unselected, including after an upgrade. Reading structure does not grant access
to settings, share capabilities, all results, respondent identities or files.
Own-response access does not grant full results. Native roles, app availability,
resource scopes, exclusion tags and guest restrictions continue to apply.

Audience and public-link changes require `forms.public_link`; confirmation-email
settings additionally require `forms.configure_notifications`. File sharing can
add `files.share`. Explicit answer-file reads and respondent inclusion have their
own Forms operations; reading a filename is not permission to download its bytes.
Files exports require the declared Files create/edit operations and destination
access. Current selection and the original action's permissions are checked again
for continuations, cancellation and evidence reads.

A write first returns a preview. Review its complete effects and repeat the same
arguments with `confirm: true`, `plan_state` and `operation_id`. Supply
`confirm_shared: true` when the preview requires shared-resource confirmation.
Source, permission, destination or connection changes require a new preview.
Previews do not acquire the native fifteen-minute editing lock. Token generation
and upload staging are confirmed effects too.

## Complete results and ongoing exports

Complete-result jobs collect a stable source snapshot over bounded steps. A
pending result is not a complete export: continue the original operation until
its verified completion. CSV, ODS and XLSX are supported. Anonymous respondent
identities stay omitted; optional respondents and file references require their
additional permissions. Formula-like cells are neutralized.

Downloads are bound to the actor and connection, single-use and valid for at most
five minutes. Private result-cache content expires after seven days; operation
receipts and sealed native recovery have separate lifecycles. See `docs/en/development/forms-result-cache.md` in the matching source bundle
for budgets and ambiguous-IO recovery.

Creating a Files export is the default. Replacing an existing file additionally
requires `overwrite`, its approved `etag`, edit authority and verified recovery.
An ongoing export needs a persistent OAuth connection and approval of initial
and future writes. Changes to its audience, source, destination, connection or
permissions can pause it. Inspect its status and retained evidence before
reconciling it; unlinking preserves the existing output.

## Bounded deletion, transfer and recovery

For operations with many responses or files, inspect every collection using
`forms_read_operation_impact` and its returned cursors before confirming. Counts
for a question backup describe whole-form recovery, not deletion of every answer.
Title changes and share edits can use bounded workflows too; their
`authoring_effects_v1` evidence is explicitly non-restorable.

Deleting a question follows native Forms behavior: the question becomes hidden
and its answer/option history remains. Approved file cleanup removes only exact
Forms-managed files with verified recovery. Whole-form deletion additionally
removes that form's native comments and managed sharing records. Files outside
the approved manifest, unrelated forms and linked outputs are retained.
Ownership transfer copies verified managed bytes and retains originals and
recovery evidence; sharing and ongoing exports have separately reported phases.

`forms_restore` creates a new private, closed form. It does not repost comments,
recreate share tokens or restart jobs. Recovery requires the original actor and
current native recovery authority; holding an edit-only question backup does not
grant access to its results. Historical comments can be read through
`forms_read_recovered_comments`, with current comment permission and the original
recovery authority. Unverified historical identities are labeled accordingly.

`bulk_v1:` references describe the original sealed recovery format. A later
current-state archive can contain native hidden-question file history whose
bytes were already absent. Its distinct `bulk_history_v2:` reference and
`whole_form_with_unavailable_history` scope explicitly report unavailable
historical files and incomplete historical byte recovery. Restoration preserves
that history without inventing bytes or reusing dangling file IDs. The older
archive taken before approved file removal still contains its verified bytes.

History distinguishes pending, partial, uncertain and verified outcomes, with
separate database, Files and event evidence. After a lost response, inspect
`forms_get_operation` for the exact operation ID. Never repeat an uncertain
physical effect or clear its reservation to make a retry possible. Cancellation
can retain staging copies and evidence; it is not a promise to undo committed
changes. A notification attempt is not proof of delivery.

[All tools](../tools.md) · [Permissions](../../guides/permissions.md)

English · [Español](../../../es/reference/tools/forms.md) · [Català](../../../ca/reference/tools/forms.md) · [Português (Brasil)](../../../pt-BR/reference/tools/forms.md) · [Português (Portugal)](../../../pt-PT/reference/tools/forms.md)
