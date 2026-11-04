<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Talk

| Module | Read | Write |
| --- | --- | --- |
| **Talk** | conversations and messages (never marks anything as read) | reply (optionally quoting a message or linking a Deck card or calendar event you can see), direct message to a user, batch of messages, share or quote a file, create a group conversation (separate grant, off by default). **Nothing is sent before the user approves: a call without `confirm: true` returns only the plan (recipient, final text, attachments, what would be created or shared) and runs nothing; with `confirm: true` it checks the permissions again and sends. No approval id, token or record is kept on the server.** |



## Publishing a prepared summary

`talk_publish_summary` publishes the `message` text supplied by the caller to an existing `conversation_token`. It requires the independent `talk.publish_summary` grant. Show the complete preview and destination to the user, then repeat with `confirm: true` and the returned `plan_state`. The authenticated user must still be able to write to the conversation. Changes to the approved text, destination, participant set or latest message require another preview. Conversations with more than 1,000 participants and incomplete one-to-one conversations are refused. The final checks and native send are separate operations, so concurrent changes can occur between them. A delivery error may leave an uncertain result. Check the conversation before trying again.

For form responses, first use `forms_read_responses` with `forms.read_responses` and native results permission. The assistant prepares a summary from the responses it can access. Review personal information and the destination's readership before approving publication.

## Reading older messages

`talk_read_messages` returns the latest page first, ordered from oldest to newest within each page. To read the preceding page, pass the returned `next_before_message_id` as `before_message_id` with the same `conversation_token`. The cursor message is excluded. A null cursor means the beginning of the available history has been reached. Each page checks access again and preserves unread messages and notifications.

See the live `mcp_guide` for the authoritative schema and available operations.

[Operational guides](../../guides/README.md).

English · [Español](../../../es/reference/tools/talk.md) · [Català](../../../ca/reference/tools/talk.md) · [Português (Brasil)](../../../pt-BR/reference/tools/talk.md) · [Português (Portugal)](../../../pt-PT/reference/tools/talk.md)
