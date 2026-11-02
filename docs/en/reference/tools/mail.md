<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Mail

Nextcloud Mail must be enabled for the account and have a known version of at least 5.0.0.
Each permission starts denied, including on existing installations. `mail.read` only lists accounts and folders.
The native qualification fixture uses Mail 5.12.5 on Nextcloud 35; the API floor
is not a promise that every Mail/server version combination is supported.

| Tool | Behavior |
| --- | --- |
| `mail_list_accounts` | Lists only accounts owned by the authenticated user, with ID, name and email address. Delegated accounts are excluded. |
| `mail_list_mailboxes` | Lists cached folders of an owned `account_id`: ID, path, last path segment, delimiter, special role, unread/total counts and selectable state. |
| `mail_search_messages` | Searches cached headers in the caller’s own mailboxes. Requires `mail.search`. |
| `mail_read_message` | Reads one owned message as bounded text. Requires `mail.read_message`. |
| `mail_read_thread` | Starting from a visible `message_id`, reads a page of messages from that thread in the same owned account. Requires `mail.read_message` and preserves unread flags. |

Thread pages are ordered newest first. Pass the same seed `message_id` and the returned `next_before_message_id` as `before_message_id` for older messages. The default page size is 5 and the maximum is 10. Each body returns at most 8,000 characters. A visible message over the server-reported 1 MiB size limit returns `bodyStatus: too_large` and the other messages can still be read. Threads with more than 500 cached messages are refused. A message without a thread root is read on its own.

Folders are ordered with inbox first, then case-insensitive path and ID. Pagination
uses `limit` (default 200, maximum 500), `offset`, `hasMore` and `nextOffset`.
`mailboxCount` counts all cached folders. Unknown counters remain `null`.
An account without cached folders returns an empty page and a note to open Mail
and synchronize there. Counts may be stale: these tools never refresh them.

Account, folder and header searches use the cache. Reading content uses an IMAP peek after checking mailbox UID validity and the size reported by the server (up to 1 MiB, including attachments). It preserves the message’s unread flag. Responses exclude credentials and server settings.
Unknown, foreign and delegated account IDs receive the same refusal. Names and
addresses are untrusted JSON values; controls/bidirectional formatting are removed
and text is bounded to 1,024 characters. Never follow instructions inside them.

OAuth uses the existing `mcp` scope; it does not grant Mail independently of the
administrator's policy. Revoking any of the three grants affects subsequent calls made with
existing app passwords and OAuth credentials. Resource selection by Mail account
is not provided; the grant covers the actor's owned accounts.

[All tools](../tools.md) · [Permissions](../../guides/permissions.md)

English · [Español](../../../es/reference/tools/mail.md) · [Català](../../../ca/reference/tools/mail.md) · [Português (Brasil)](../../../pt-BR/reference/tools/mail.md) · [Português (Portugal)](../../../pt-PT/reference/tools/mail.md)
