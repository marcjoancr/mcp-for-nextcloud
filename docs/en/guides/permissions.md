<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Permissions and administration

Open **Administration → Nextcloud MCP**. The five tabs are **Overview**, **Access and permissions**, **Clients and connections**, **Data protection** and **Advanced**. Their addresses (`#overview`, `#access`, `#clients`, `#protection`, `#advanced`) and keyboard navigation open the corresponding section. On narrow screens, use the Sections selector to switch between the same five views. Clients and connections opens with the active connections list. On phones, each connection has its own labeled rows and actions.

## Enable access

The service, user eligibility (**Can connect**) and personal connection start off, including for administrators. Enable the service, allow the user to connect, then let that user connect in **Personal settings → Nextcloud MCP**. Authentication alone never grants access: the account, service, eligibility, connection, optional app, operation permission and native Nextcloud resource permissions must all allow the request.

The original 2.0.0 read tools retain their defaults, except Mail and server logs, which start denied. Every newly added tool requires an explicit user or group assignment and selection in the connection. Assigning one new tool does not grant another: for example, reading a mail message does not enable reading a thread, and reading form questions does not enable reading responses. In the matrix, grant the individual tools for Mail search, message and thread reading; Forms listing, questions and responses; Deck comment reading and posting; and Talk summary publication.

The tool definitions retain operation keys for classification: `mail.read` covers accounts and folders, `mail.search` header searches, and `mail.read_message` message and thread content. Forms uses `forms.read` for structure and `forms.read_responses` for responses; results also require native Forms results permission. Deck comments use `deck.read_comments` and `deck.comment`; Talk summaries use `talk.publish_summary`, independently of replies. Mail attachment reading (`mail.read_attachment`), saving (`mail.save_attachment`), draft reading (`mail.read_drafts`), draft writing (`mail.write_drafts`) and draft deletion (`mail.delete_drafts`) are separate. Saving an attachment additionally requires `files.create` and the destination's native permissions and Files scope. Talk message search uses `talk.search`; contextual reading uses `talk.read`, with an independent tool assignment. New tools remain unassigned and unselected when upgrading. Native resource access and any additional operation prerequisites still apply. Files sharing with people/groups (`files.share`) and public links (`files.link`) remain independent permissions. Listing shares is a read operation.

At connection time, MCP lists the tools permitted for the user and selected for that connection.
Each tool also requires its module's app and any additional app it uses to be installed and enabled for that user.
For example, listing, creating and removing file shares require `files_sharing`. Editing, replacing and checking out
an existing file require `files_versions`. Ordinary file reads work with either app disabled. The server checks the
same requirements again on a direct call.

The Overview shows the endpoint, app version, connection counts and checkout upload limit. The checkout limit is edited in MiB and cannot exceed PHP's `post_max_size`. Advanced contains server-log roles/privacy and OCR. Data protection contains the exclusion tags; see [file protection](file-protection.md).

## Guests app restrictions

Starting with 1.1.2, MCP reapplies the Guests app restrictions after authenticating
an app password or OAuth token. A guest whose allowed-app list excludes MCP is
refused. MCP returns HTTP 403; Guests may reject an app-password login earlier
in Nextcloud (its rejection is surfaced as HTTP 500 on Nextcloud 35). Tools, Notes resources and Talk references for disallowed
apps are hidden or refused; file-version tools also require Guests to allow
`files_versions`. Files uses the always-allowed `files` app. Tasks requires the `tasks` app on the guest’s allowlist and uses `dav` for storage.

Hidden external storage remains inaccessible, including Nextcloud 35 mounts
resolved directly by path. Structured file references in Talk and Tasks, including
publication of Talk attachments, use the same hidden-storage check. When Guests hides other accounts, sharing remains
limited to the guest's groups. An unreadable Guests state fails closed.

Checkout and upload links apply the same relevant restrictions to their owner
before resolving a file. A refused guest receives the answer of an unknown link
(404), without spending it. Guests versions older than 4.8.0 cannot apply these
sessionless restrictions, so guest-owned links are refused. Non-guest accounts
and installations without Guests retain their existing behavior.

## Individual choices and group inheritance

In **Administration → Nextcloud MCP → Access and permissions**, the matrix opens with the first group in the directory. Click its column heading to choose a different group or a person from the dropdown. Both lists are available before you type. Use **Add comparison column** to keep the current column and compare another entry. A group shows its assigned rules. A person shows effective access, its source and any individual exceptions. The service row stays at the top. Open a catalog, search for a tool or filter to differences or personal exceptions.

Choose **Allow**, **Block**, or the neutral option directly in a cell. **Not assigned** clears a group rule. **Inherit** clears a personal exception. The information button beside the selector opens the tool description and its related rules beneath that row. For a person, it also shows the groups behind the decision. To change several assignments together, turn on **Edit multiple rules**, check the tools and target columns, then choose the rule once. Your edits stay pending until you review and save them. Review each column’s impact and save it before reviewing the next one, since a group change can affect a person in another column.

Only tools from installed, enabled and compatible apps appear. A person sees the apps enabled for their account. Disabling an app hides its tools from MCP and the settings screens. Existing permissions and connection selections become available again when the app is re-enabled.

**Allow**, **Block** and **Unassigned** apply to both the service and tools. An individual's explicit decision wins. When it is unassigned, a block by a trusted group wins over group allowances. Without a group decision, the catalog default applies. Turning off the global service or disabling an account prevents every connection from using MCP. A tool still needs its optional application, role, operation prerequisites and native resource access.

Group membership comes from Nextcloud. Only native database-backed groups can grant access. A failure to resolve a policy is reported as unavailable. From a tool cell, open **Related operation rules** for controls such as sharing with people or creating public links. Changing one of those settings affects every tool that requires that operation.

When Nextcloud creates an account, MCP clears any individual tool grants and connection selections left by an earlier account with the same user ID. If that transaction fails, account creation stops. Retry creation after the policy store is available.

### Upgrading from 2.0.0

The upgrade preserves the original individual and group settings. The 84 tools shipped in 2.0.0 inherit these settings until an administrator changes an assignment. An old unchecked group operation stays unassigned. Resetting an individual tool to **Unassigned** also clears its inherited individual operation override for that tool. The migration verifies the old and new decisions before activation. An unexpected difference or an unreadable policy stops the upgrade.

Existing connections receive a selection limited to their previous operation permissions. Newly added tools require a new assignment and selection. Keep the pre-upgrade database and application backup together. After someone changes selections or blocks access in the new editor, recovery must retain those decisions. An older app cannot enforce them.

## Forms analysis, Deck search and Calendar availability

These additions start unassigned and are selected separately in each connection:

| Tool | Operation | Additional native access |
| --- | --- | --- |
| `forms_summarize_responses` | `forms.summarize_responses` | Full form results permission |
| `forms_export_responses` | `forms.export_responses` and `files.create` | Form results and creation in the selected Files folder |
| `deck_search_cards` | `deck.search` | Read permission on each returned board |
| `calendar_check_availability` | `calendar.availability` | Calendar scope, native shares and event classification |

Existing read permissions do not automatically enable these tools. Form export is the only write in this set: it requires a current preview and confirmation, plus shared-destination confirmation when applicable. Neither a tool assignment nor a connection selection bypasses another user's native access restrictions.

## Saving, conflicts and undo

Policy writes carry the revision displayed by the interface. A stale preview or concurrent change produces a conflict and a reload; the browser does not silently replay the write. An uncertain response is not shown as a successful save. Group/resource/client drafts stay drafts until their respective Save action.

Reset/bulk requests are limited to 50 users and 2,500 cells. Preview validity is 120 seconds; server receipts expire after 600 seconds. The interface offers **Undo** for 10 seconds while its control is available, pausing the timer on pointer hover or keyboard focus. Undo still requires the saved revision and a valid receipt: it cannot overwrite intervening changes. Restoring a permission does not restore revoked credentials or sign users back in.

## Resource restrictions and inspection

An administrator can restrict one user's **Files**, **Calendar** and **Tasks** resources from the access drawer. Each module is independent: **All resources** means unrestricted; **Only selected** with an empty selection grants access to none. The limit is 200 selected resources per user and module. Restrictions only narrow existing grants and native permissions; changing them does not revoke the connection. They apply to the next operation.

Notes, Contacts, Deck and Talk have no module resource restriction. Mail likewise has no per-account resource selection. Structured file references and attachment operations can still require Files scope checks; see [file protection](file-protection.md). A folder restriction is not a general restriction on everything every other module stores beneath that folder.

The administrator-only inspector reports **Allowed**, **Blocked** or **Unknown**, the blocking check and the permission's source. It may explain an excluded path to the administrator; MCP errors retain generic refusals. Queries and changes can produce info-level audit messages without resource names or paths. These messages depend on the host log level and are not a guaranteed audit trail.

## Clients and personal settings

Open **Personal settings → Nextcloud MCP** and choose a connected client to manage its tools. The same selector appears when authorizing a new OAuth connection. Select only what that client needs. Administrative withdrawals apply on the next request, including existing tokens. Refreshing an OAuth token retains that connection's selection. Basic authentication and session clients share the separately labeled Nextcloud profile. Resource scope continues to limit which folders, calendars and task lists the client can reach.


Manage published client hosts, the native client and registered custom clients under **Clients and connections**; see [OAuth](../reference/oauth.md). Administrative APIs require an administrator and Nextcloud CSRF protection. Personal connection/scope APIs derive identity from the session and expose only that user's state. Responses never contain token hashes.

Personal settings show connection status, connected clients, their revocation controls and the user's read-only resource restrictions. For HTTP Basic, create an app password under **Personal settings → Security**. MCP does not ask for or store the main password. Disconnecting MCP does not delete that Nextcloud app password; see [revoking access](clients.md#revoking-access).

A residual write risk remains: a generic refusal can distinguish an unavailable destination from one where creation would succeed, even though it does not disclose why the destination is unavailable.

[Operational guides](README.md).

English · [Español](../../es/guides/permissions.md) · [Català](../../ca/guides/permissions.md) · [Português (Brasil)](../../pt-BR/guides/permissions.md) · [Português (Portugal)](../../pt-PT/guides/permissions.md)
