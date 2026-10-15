<div align="center">

# MCP for Cloud

**A native Nextcloud app that turns your Nextcloud into an MCP server.**
Let Claude and other MCP clients work with your files, notes, calendars, contacts, tasks, Deck boards and Talk conversations, with each user's own permissions, and nothing leaves your server that the admin did not allow.

[![Tests](https://github.com/sintax-tech/mcp-for-nextcloud/actions/workflows/tests.yml/badge.svg)](https://github.com/sintax-tech/mcp-for-nextcloud/actions/workflows/tests.yml)
[![Nextcloud 32–35](https://img.shields.io/badge/Nextcloud-32--35-0082c9?logo=nextcloud&logoColor=white)](https://nextcloud.com)
[![Nextcloud App Store](https://img.shields.io/badge/App%20Store-mcp-0082c9?logo=nextcloud&logoColor=white)](https://apps.nextcloud.com/apps/mcp)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777bb4?logo=php&logoColor=white)](https://www.php.net)
[![MCP](https://img.shields.io/badge/MCP-2025--06--18%20%7C%202026--07--28-111)](https://modelcontextprotocol.io)
[![License: AGPL-3.0](https://img.shields.io/badge/License-AGPL--3.0-blue)](#license)

English · [Português (Brasil)](README.pt-BR.md)

</div>

---

## Why

AI assistants are most useful when they can reach the tools a team already uses. Plugging them into Nextcloud usually means scripts with shared passwords, broad API tokens or data copied elsewhere. **MCP for Cloud** runs **inside** your Nextcloud:

- **One URL per instance.** `https://cloud.example.com/apps/mcp/`. No subdomain, no extra service, no Node/Docker/AppAPI.
- **Sign in with Nextcloud.** Clients such as claude.ai use OAuth ("Sign in"): the user logs into their own Nextcloud and clicks **Allow**. App passwords (HTTP Basic) still work for other clients.
- **Admin in control.** Service on/off switch, per-user eligibility, **user × permission matrix** and **grants by native Nextcloud group**. Reading is on by default for eligible users; every write, move, delete, transfer, restore or share is **off until the admin grants it**, sharing a file and creating a public link being two separate grants. Groups only grant permissions; individual user overrides (`Allow` or `Deny`) always take precedence.
- **Nextcloud ACLs and optional per-resource scope apply.** The app never widens what a user can already do in Nextcloud, and administrators can further narrow a user's MCP access to specific folders, calendars or task lists (*TODO: Files scope consumer wiring B5, Calendar/Tasks scope activation B6 and admin scope UI B7 in progress*).
- **Exclude sensitive files from MCP access by system tag.** Administrators can configure system tags (recommended: restricted or invisible) to make tagged files and folders completely invisible to the tools and resources of this MCP connector, including everything below a tagged folder (the server log tools only while "Hide the text of log messages" is on). While any exclusion tag is configured, folder sharing, folder public links, folder copying and moving a folder to another directory are refused in MCP so a hidden descendant cannot be exposed indirectly. It fails closed: if a tag lookup errors, the item stays hidden. Backups taken by the app inherit the tag before they receive any content.

## What's new in 1.1 (in progress)

- **1.1.0: group permissions, per-resource scope and hardened tag exclusion.**
  - **Permissions by native Nextcloud group:** administrators can grant *Can connect* and module operations to native Nextcloud groups (single `'Database'` backend) in *Access and permissions → Groups*, with impact preview before saving. Groups only grant; they never deny or narrow, and individual user overrides (`Allow` or `Deny`) take precedence over group grants. Non-native groups (LDAP, SAML, third-party apps or multi-backend groups) are shown as disabled with the reason; grant access to their members individually. See [Group permissions, guarantees and limits](#group-permissions-guarantees-and-limits). (*TODO: F1/F2 group revocation refinements, Ticket 12 override reset/undo UI, and Ticket 13 effective access inspector are in progress.*)
  - **Per-resource scope (Files, Calendar and Tasks):** administrators can restrict a user from "All resources the user has in Nextcloud" to an explicit allow-list of folders (and their descendants), event calendars (`VEVENT`) or task lists (`VTODO`), up to 200 items per user and module, without revoking existing OAuth connections. Precedence is always `Exclusion by system tag > Per-resource scope > Native Nextcloud ACL`. Notes is outside the Files folder scope. See [Per-resource scope](#per-resource-scope). (*TODO: B5 Files scope consumer wiring, B6 Calendar/Tasks scope activation, and B7/Ticket 13 admin editor and inspector UI are in progress.*)
  - **Excluded from MCP access — folder protection and reference filtering:** while any exclusion tag is configured, MCP refuses to share, create/update a public link, copy, or move to another directory any **folder** (and hides `url` on existing folder public links in `files_list_shares`), while individual files and same-parent folder renames keep working. Structured file references in Talk attachments/quotes and Tasks `ATTACH` links (across any host, IP, loopback or alias) are checked against tag exclusion and per-resource scope and omitted when excluded or out of scope. Root resolution above mount points fixes false "not found" results on external storage and individually shared team-folder files when exclusion tags are active ([#4](https://github.com/sintax-tech/mcp-for-nextcloud/issues/4)).

Upgrading from 1.0.8 runs the native schema migrations automatically (tables `mcp_group_grants`, `mcp_policy_state`, `mcp_auth_users`, `mcp_policy_events`, `mcp_recheck`, `mcp_group_life`, `mcp_scope_modes` and `mcp_scope_items`, plus generation columns on OAuth tables): no manual step, no `occ`, and every existing user grant and connection continues as before. **Important:** once you adopt group grants or per-resource scopes, do not downgrade the app to 1.0.8; see [Upgrading, incidents and rollback](#upgrading-incidents-and-rollback).

## What's new in 1.0

From 1.0.0 on the app is published in the [Nextcloud App Store](https://apps.nextcloud.com/apps/mcp), signed with the store certificate. The full list, including the security fixes, is in [`CHANGELOG.md`](CHANGELOG.md).

- **1.0.0 and 1.0.1: the App Store.** First signed release in the store (no functional change since 0.11.1); 1.0.1 adds the `ai` category.
- **1.0.2: server log.** A read-only module for IT staff, `logs_list` and `logs_analyze`, over `nextcloud.log` through the core's own reader. Only administrators and the groups they choose see the tools, the grant starts off, IPs are masked, the output is marked as untrusted and every read is audited. See [Features](#features). It also fixes `files_image_search`, which failed on Nextcloud 33.
- **1.0.3: Nextcloud 32–35.** The app declares support for Nextcloud 32 to 35, checked by API contract tests against Deck 1.16–1.19 and Talk 22–25. Fixes: editing a Deck card on 1.18/1.19 no longer clears its start date or colour, and Talk respects the lobby when reading and writing.
- **1.0.4: store description.** The description in the App Store (English, Portuguese and Spanish) now lists the server log module added in 1.0.2. No functional change.
- **1.0.5: locked files and licensing.** A note or file open in Text or Nextcloud Office, locked by another person or by a WebDAV client is no longer a generic error: the plan warns about it, and the write is refused before any change with the app or person holding the lock, since when, until when and what to do. Your own manual lock still lets you write, the app never unlocks or takes over a lock, a version restore no longer writes under an open editor, and the checkout upload answers 423. The app refuses the locks Nextcloud reports when it checks; locks of people and apps are also enforced by Nextcloud at the write (with one exception for Text on delete and rename), while a WebDAV token lock is refused only by the app's own check, which is not atomic with the write and may miss a lock taken during the same request (see the known limitations in [`CHANGELOG.md`](CHANGELOG.md)). The repository now follows REUSE, with `NOTICE`, third-party notices and the "Developed by Sintax" credit on the admin page.
- **1.0.6: hidden tags, audited.**
  - **Fix:** with a hidden system tag configured, ordinary files and folders are visible again; since 0.8.0 they were all reported as not found. Backups of hidden files stay hidden. Thanks to @NightDragon1 (#5).
  - **Security:** the tag-based hiding was audited on every access path and the gaps were closed: batch undo, Talk quotes, Notes categories, MCP resources, files reached through local shares, settings that cannot be read, cached decisions and folders named "0". In doubt, the app now refuses. Thanks to @matt-ek (#4) for the report behind the audit.
  - **Server log:** a new admin option, on by default, hides the text of log messages while files are hidden by tags; it can be turned off when your IT team needs the full details. The app's own error log now keeps only the error type and code.
  - **Docs and CI:** the technical README is now available in English ([`mcp/README.md`](mcp/README.md)) and Portuguese ([`mcp/README.pt-BR.md`](mcp/README.pt-BR.md)), and GitHub Actions runs the test suite on PHP 8.2, 8.3, 8.4 and 8.5 and checks the package on every push and pull request.
- **1.0.7: task categories and a clearer admin page.**
  - **Tasks:** the Tasks tools read, write and filter task categories (the VTODO `CATEGORIES` property): `categories` on `tasks_read_task` and `tasks_list_tasks`, `categories` on create and edit (omit to keep them, `[]` clears them, a list replaces them) and a `category` filter on the list. The confirmation plan shows each name before and after a change ([#6](https://github.com/sintax-tech/mcp-for-nextcloud/issues/6)).
  - **Admin page:** five tabs with their own address, searchable pickers for the server log groups and the exclusion tags, and a permission matrix that can show one app at a time and keeps the names in view. Each cell says Allowed, Denied or Unavailable (with the reason), the "All" menu asks for confirmation that counts who changes, and "Undo" brings the values back for 10 seconds ([#2](https://github.com/sintax-tech/mcp-for-nextcloud/issues/2)).
  - **Excluded from MCP access:** the tag section says what it does and what it does not: it hides files from this connector only, who can assign each tag type, and that the server log tools respect it only while "Hide the text of log messages" is on ([#4](https://github.com/sintax-tech/mcp-for-nextcloud/issues/4)).
- **1.0.8: new name.** The app is now called **MCP for Cloud**: the Nextcloud App Store rules do not allow the Nextcloud name in the name of an app. Nothing changes for you: the app ID, the settings, the permissions and the connections are the same, and there is nothing to do.

Upgrading from 0.7.0 or later runs the schema migrations by itself (tables `mcp_file_batches`, `mcp_oauth_spent` and `mcp_logs_groups`): no manual step, no `occ`, and nothing changes in `config.php`.

## Features

| Module | Read | Write (each one needs an admin grant) |
|---|---|---|
| **Files** | list, search (by name or content with snippets via fulltextsearch), search images by tag/date, view images and batch previews (JPEG, PNG, WebP, GIF, TIFF, PDF), read text (TXT/MD, PDF, DOCX, ODT; a scan without text returns `text_layer: false` and, with the optional Workflow OCR app active, gains text in the background), list and read file versions | edit text with a diff and a partial replacement, with a verified backup in `/MCP backups` before every write (Nextcloud versioning applies as to any edit); restore a stored version; check any file (DOCX, XLSX, PDF, images, up to the upload limit) out to an agent with a shell through a one-time link; reorganize folders: scan the tree, create a folder, copy, move, move a batch and undo it. **Never deletes the file.** Also list the shares you created, share an own file or folder with a person, a group or a public link (with a generated password when asked), and remove a share — which removes the access, not the file. *(While any exclusion tag is configured, sharing, linking, copying or moving a folder to another directory is refused in MCP; individual files and same-parent folder renames continue to work.)* |
| **Notes** | list, read, search (by title and Markdown content) | create, edit, move between categories, delete (only when the trash bin can recover it) |
| **Calendar** | list calendars and events (recurrence, time zones, all-day) | create, edit, move, delete and transfer events through the real CalDAV pipeline, with participants. The writes are off until an administrator grants them in the admin matrix (no terminal step), and each one returns a plan first: **nothing is written before the user approves it.** |
| **Contacts** | list personal/shared address books, search and read contacts, including the read-only accounts catalog of the instance (colleagues, with `accountId` for invitations) under the admin user-enumeration settings | create and edit while preserving unknown vCard fields; permanently delete only after a verified `.vcf` backup in `/MCP backups/Contacts` |
| **Tasks** | list task calendars and VTODO tasks, including undated tasks, optionally only those with a category; read tasks, with their categories | create, edit, complete and delete simple tasks through CalDAV, including their categories; deletion uses calendar trash and is refused when retention is zero |
| **Deck** | boards, stacks, cards, and a **follow-up** of the boards you manage: cards grouped by assignee, with due date, overdue flag (in your own time zone) and a link to the card | create, edit, move and delete cards, and assign or unassign the board members; create a board with its lists and first cards, or a list in a board you manage; delete a board or a list only when it is empty, straight to the Deck trash |
| **Server log** | for administrators and the groups they choose: list the entries of `nextcloud.log` with filters, and summarize them (totals, frequent signatures, URLs, user agents, entries per hour). Off by default for everyone; IPs masked, output marked as untrusted, every read audited. While exclusion tags are configured, the admin option "Hide the text of log messages" (on by default) hides the text of messages and exceptions, the user agent and the URL detail, and refuses the `contains` filter; turned off, the logs come back in full for diagnosis | — (read only) |
| **Talk** | conversations and messages (never marks anything as read) | reply (optionally quoting a message or linking a Deck card or calendar event you can see), direct message to a user, batch of messages, share or quote a file, create a group conversation (separate grant, off by default). **Nothing is sent before the user approves: a call without `confirm: true` returns only the plan (recipient, final text, attachments, what would be created or shared) and runs nothing; with `confirm: true` it checks the permissions again and sends. No approval id, token or record is kept on the server.** |

Plus:

- **Conversational confirmation before every write (`confirm: true`)**: any tool that changes data (`operation != 'read'`) only executes when called with `confirm: true`. Without it, the server returns the plan (`requiresConfirmation: true`) and makes no changes. The assistant must present the plan to the user and only repeat the call with `confirm: true` after explicit approval. No approval state or tokens are kept on the server.
- **MCP Resources**: dual-era support for Model Context Protocol resources (`resources: {}`). Clients can list `mcp://guide` to read the tool guide directly, discover resource templates (`nc://files/{path}`, `nc://notes/{id}`) filtered by user grants and apps, and read text files (with automatic text extraction), notes, and small binary blobs (<= 512 KiB). Ungranted, missing, or hidden resources fail closed.
- **OCR through Workflow OCR**: when the Workflow OCR app is installed, scanned PDFs gain a text layer that `files_read` reads normally. Without it, a PDF or image with no text comes back with `text_layer: false` and a notice, and the admin page shows the OCR status with a link to install it. Nothing is blocked.
- **Tool guide** (`mcp_guide`): the model asks the server what each tool does, its parameters, limits and whether it needs confirmation, built from the same definitions `tools/list` serves, filtered by the user's grants and apps. The guide is in English and the model relays it in the user's language.
- **Friendly tool titles** in the client ("Search files", "List calendars") and MCP annotations (`readOnlyHint`, `destructiveHint`) so clients can ask before risky actions.
- **Safety confirmations** for resources that belong to someone else: shared folders, team folders, other people's Deck boards and calendars. The server refuses the first call and returns a ready-made message, and the assistant must ask the user before repeating it with `confirm_shared: true`. *(Deck and Calendar since 0.6.6; Files and Notes since 0.7.0)*
- **Optional apps respected**: tools and admin matrix columns of an app that is disabled (for everyone or for a given user) are hidden.
- **Localized UI**: admin matrix, personal page, consent screen and every message a tool shows follow the language of the user's Nextcloud account. All modules are covered (Files, Notes, Calendar, Contacts, Tasks, Deck, Talk, the shared messages, the checkout pages and the calendar selftest). English, Brazilian Portuguese, Spanish and German are included (German by @matt-ek; Spanish is a first translation and still needs a native review); tool descriptions stay English, because the model is what reads them.
- **Both MCP eras**: stateless MCP `2026-07-28` (`server/discover`) and the classic `initialize` flow (`2025-06-18` and earlier) on the same endpoint.

## Contacts and tasks

Contacts use core CardDAV and only your own and shared address books. Editing changes supplied fields and preserves unknown properties, parameters, groups and the vCard version. The Contacts app must be enabled for the user. Tasks use VTODO in calendars supporting that component and work without the optional Tasks app or Calendar UI app. Task categories (the `CATEGORIES` property) are free text, as in the Tasks app: `categories` is returned when reading and listing, and on create and edit a list replaces all the categories, `[]` clears them and omitting it keeps them; `tasks_list_tasks` can filter by one `category`, ignoring case.

Both modules have separate `read`, `create`, `edit` and `delete` grants; every write starts disabled. Calls without `confirm: true` return the actual before/after plan and create neither backups nor DAV writes. Shared collections require `confirm_shared: true`. An ETag is optional; when provided it must match, and confirmed edits/deletions always use the current ETag as `If-Match`.

**Contact deletion is permanent: Nextcloud has no contact trash bin.** Its plan shows every vCard field and this warning. After confirmation, the full original vCard is saved and read back in the acting user's `/MCP backups/Contacts/<address-book>/<name-or-uid>.<YYYYmmdd-HHMMSS>-<unique-suffix>.vcf`. Existing files are never selected for overwrite; backup failure prevents deletion. The result gives `backupPath`; import that file through Contacts to recover the contact. This copy is a safety net, not native contact trash.

Task deletion uses native calendar trash and is refused when `dav/calendarRetentionObligation` is `0`. Recurring tasks can be read; writes to recurring tasks or tasks with participants are refused to protect their series and scheduling. Completed tasks are excluded from listings by default; use `include_completed` to include them. Contact searches and task listings accept `limit` (up to 200) and `offset`, and return `nextOffset` for more results.

## Requirements

- Nextcloud **32 to 35**, each release checked by the API contract tests against the oldest build of its major (with the matching Deck and Talk); Deck 1.16+ and Talk 22+ on 32, up to Deck 1.19 and Talk 25 on 35
- PHP **8.2+** with `zip`, `mbstring` and `dom`
- Optional apps, only for their own tools (hidden while the app is disabled): Notes, Calendar, Contacts, Deck, Talk (`spreed`), Versions (`files_versions`, required for edits), Deleted files (`files_trashbin`, required for deletes)
- Deck and Talk are optional: their tools appear only when the app is enabled in a supported version; an older release counts as disabled, its tools stay hidden and the admin page says which version brings them back

## Installation

**From the App Store (recommended).** As an administrator, open *Apps*, search for **MCP for Cloud** and click **Download and enable**. From a terminal, the equivalent is `sudo -u www-data php /var/www/nextcloud/occ app:install mcp`. The store package is signed, and updates arrive through the normal Nextcloud app updater.

**Manual build (alternative, for development).**

1. **Build the package** (on any machine with PHP and Composer):

   ```bash
   cd mcp
   ./scripts/package.sh          # creates build/mcp-<version>.tar.gz
   ```

   The script bundles only production files and checks the archive, including macOS metadata (`._*`), extra top-level folders and missing assets.

2. **Copy and enable it on the server:**

   ```bash
   scp build/mcp-<version>.tar.gz user@server:/tmp/
   ssh user@server
   sudo tar -xzf /tmp/mcp-<version>.tar.gz -C /var/www/nextcloud/apps/
   sudo chown -R www-data:www-data /var/www/nextcloud/apps/mcp
   sudo -u www-data php /var/www/nextcloud/occ app:enable mcp
   ```

   Adjust paths and the web server user to your setup. With Docker, run `occ` inside the container.

Then, **configure** in *Administration settings → MCP for Cloud* (the app has its own entry with an icon in the settings menu): turn the service on, mark who may connect (individually or by native Nextcloud group), grant write permissions where needed, optionally restrict users to specific resources, and select the system tags that exclude sensitive files from MCP access. The page has five tabs, each with its own address: **Overview** (endpoint to copy, service switch, app version, eligible/connected users, active connections and the editable checkout upload limit in MiB, shown next to PHP's `post_max_size` ceiling), **Access and permissions** (the **Users** permission matrix — with search, group, "who can connect / connected" and app filters, a fixed column of names, Allowed, Denied or Unavailable in every cell, an "All" menu per module for the users on the page that asks for confirmation and offers "Undo" for 10 seconds, and pagers on top and bottom — and the **Groups** view to grant *Can connect* and module operations to native Nextcloud groups with impact preview before saving; *TODO: Ticket 12 override reset/undo UI, Ticket 13 effective access inspector, and B7 per-resource scope UI*), **Clients and connections** (OAuth clients and active connections), **Data protection** (**Excluded from MCP access**, the system tags that exclude files) and **Advanced** (**Server log**: the groups whose members may read it besides the administrators, **Log privacy**: the switch that hides the text of log messages while files are excluded by tags, and **OCR**).

Server log access groups, group grants, policy revisions and per-resource scopes are stored in the app's own versioned tables, created automatically by its native migrations without any terminal command. Existing appconfig server log groups are imported on first read. The admin APIs return a `version`/`revision` with GET and require it on writes; stale saves get 409 without overwriting concurrent changes.

## Connecting a client

Every client talks to the same URL, `https://cloud.example.com/apps/mcp/`, and authenticates as the person using it, with that person's own permissions. Only the authentication step differs:

| Client | Authentication | Something the administrator has to do first |
|---|---|---|
| **Claude** — claude.ai, Claude Desktop, Cowork, Claude Code | OAuth **Sign in**, Claude's published identity (CIMD) | Nothing: `claude.ai` is allowed by default |
| **ChatGPT** | OAuth **Sign in**, OpenAI's published identity (CIMD) | Nothing: `chatgpt.com` is allowed by default |
| **Gemini CLI** | OAuth with the app's built-in native client | Tick **Allow local programs** and copy the `client_id` |
| **Any other MCP client** | App password (HTTP Basic) | Nothing |

### Claude (claude.ai, Claude Desktop, Cowork and Claude Code — recommended)

1. *Settings → Connectors → Add custom connector*.
2. URL: `https://cloud.example.com/apps/mcp/`.
3. Authentication: **Sign in** (OAuth), client: **Claude's published identity** (CIMD). No headers needed.
4. **Connect**: your Nextcloud opens, you log in and click **Allow**.

`claude.ai` is in the allowed client hosts out of the box, so nothing has to be changed on the server. If an administrator narrowed that list, Claude is refused with `invalid_client` before the login page even opens: ask them to put `claude.ai` back in *Administration settings → MCP for Cloud → OAuth clients → Allowed client hosts*.

### ChatGPT

1. In ChatGPT, add a new connector for an MCP server and paste `https://cloud.example.com/apps/mcp/` as its URL.
2. Authentication: **Sign in** (OAuth). ChatGPT identifies itself with the client identity document published at `chatgpt.com`; the app downloads that document to learn the redirect URIs it registered. No headers, no app password.
3. **Connect**: your Nextcloud opens, you log in and click **Allow**.

`chatgpt.com` is allowed by default, so an installation that was never configured already accepts ChatGPT. If an administrator narrowed the list to other hosts, ChatGPT is refused with `invalid_client` before the login page opens — ask them to add `chatgpt.com` back in *Administration settings → MCP for Cloud → OAuth clients → Allowed client hosts*.

### Gemini CLI

Two steps, one from the administrator and one from the user, with no terminal on the server.

**Administrator** — *Administration settings → MCP for Cloud → OAuth clients*:

1. Tick **Allow local programs (native client)**. It is off by default.
2. The section then reveals the `client_id` to copy — `nextcloud-mcp-native` — and the redirect URIs it accepts.

**User** — create or edit `~/.gemini/settings.json` on your own machine:

```json
{
  "mcpServers": {
    "nextcloud": {
      "url": "https://cloud.example.com/apps/mcp/",
      "oauth": {
        "clientId": "nextcloud-mcp-native"
      }
    }
  }
}
```

3. In Gemini CLI, run `/mcp auth` (or `/mcp auth nextcloud`).
4. Your Nextcloud opens in the browser: log in and click **Allow**. Gemini CLI is listening on a free port it picked itself, so the browser returns to `http://localhost:<port>/oauth/callback`. The app accepts `http://localhost/oauth/callback`, `http://127.0.0.1/oauth/callback` and `http://[::1]/oauth/callback`, and ignores the port for loopback addresses (RFC 8252 7.3), so the random port still matches.

Why a built-in client: Gemini CLI has no published client identity document, so the app ships one fixed public client for local programs. Its id is the same for everyone on the instance, and that is safe because it grants nothing by itself — the login, the eligibility, the personal connection, the administrator's grants, the consent screen and PKCE all still apply. Turning the switch off also stops the client from exchanging an authorization code and from refreshing a token.

### Administrators: OAuth clients

*Administration settings → MCP for Cloud → OAuth clients* decides which clients may connect, without any terminal step:

- **Allowed client hosts** — the hosts whose published client identity document the app is willing to download, separated by commas; `claude.ai` and `chatgpt.com` by default. Exact host names only: no `https://`, no port, no path, no `*`, and at least one host has to stay. The host is the one in the URL the client uses as its `client_id`. Saving replaces the whole list, and removing a host refuses that client immediately, even when its document is already cached.
- **Allow local programs (native client)** — enables the `nextcloud-mcp-native` client described above and shows the `client_id` and the accepted redirect URIs to copy.

Both controls are administrator-only and CSRF-protected, and they report a refused save. A configuration you set yourself is not overwritten by the defaults: an `oauth_client_hosts` value saved before 0.8.0 keeps being used as it is.

### Other MCP clients (app password)

Create an app password in *Personal settings → Security*, enable the connection in *Personal settings → MCP for Cloud*, then configure the client with the URL above and HTTP Basic auth (`username:app-password`). Quick check:

```bash
curl -u 'alice:APP-PASSWORD' \
  -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl","version":"0"}}}' \
  https://cloud.example.com/apps/mcp/
```

### Revoking access

- **User**: *Personal settings → MCP for Cloud → Disconnect* blocks the next request and revokes OAuth tokens. The same page lists the user's own OAuth clients (client, signed in, expires) with **Revoke** for each one. App passwords are revoked in *Security*.
- **Admin**: remove eligibility (individually or by removing the group grant / group membership) or turn the service off. Both apply on the next request. *Administration settings → MCP for Cloud → Active connections* lists every OAuth connection (user, client, signed in, expires) and revokes one, or all of a user, at once; the user can sign in again while they may connect.

## Security

- **Access precedence on every call:** after checking the authenticated identity, enabled Nextcloud account, service switch, user eligibility (individual override or native group grant), personal connection, enabled app, log role (for `logs`), and the admin grant for that exact operation, resource access always follows:
  `Exclusion by system tag > Per-resource scope > Native Nextcloud ACL`.
  Exclusion and per-resource scope only narrow access; they never grant or widen what Nextcloud ACLs allow. Excluded and out-of-scope resources return the exact same "not found" response as nonexistent items and are omitted from listings, search and previews.
- OAuth: PKCE S256, exact redirect URI match (a loopback redirect URI may use any port, per RFC 8252 7.3), client identity documents accepted only from the allowed hosts the administrator edits in the admin page (default `claude.ai` and `chatgpt.com`), the public-client method `none` as the only accepted token authentication method, tokens stored only as HMAC hashes, one-time rotating refresh tokens.
- A client identity document is downloaded over HTTPS from an allowed host only, without following redirects, capped at 64 KiB and cached for an hour. Dropping a host from the list blocks the client at once, cache or no cache.
- Every redirect back to a client carries `iss` with this server's issuer identifier (RFC 9207) — on the authorization code, on a denial and on redirectable errors alike. Errors raised before the redirect URI is validated are shown on a local page instead of being redirected.
- The native client is off by default. Once enabled it is a public client with a fixed, non-secret `client_id` that may only return to a loopback callback, and it grants no access on its own: the login, the eligibility, the personal connection, the administrator's grants, the consent screen and PKCE all still apply. Turning it off also stops it from exchanging an authorization code or refreshing a token.
- The admin "OAuth clients" section is administrator-only, CSRF-protected, refuses an invalid host list instead of writing it, and shows the effective state and any save error.
- No passwords, tokens or file contents in logs. Errors returned to clients are generic.
- The consent screen is CSRF-protected and cannot be framed.
- Found a vulnerability? Please report it privately to the maintainers instead of opening a public issue.

### Group permissions, guarantees and limits

In *Administration settings → MCP for Cloud → Access and permissions → Groups*, administrators can grant *Can connect* and module operations to Nextcloud groups, with a preview of how many members gain or lose effective access before saving (*TODO: Ticket 12 UI to reset individual overrides back to inheriting and to reset a group, and Ticket 13 effective access inspector are in progress*).

- **How group grants combine with user overrides:** groups only **grant** (`Allow`); they never deny or narrow. If a user has an individual override (`Allow` or `Deny`) for *Can connect* or for a specific operation, that individual override always wins. Without an individual override, the user inherits `Allow` if any of their trusted groups grants the permission; otherwise the module's default applies (`Allow` for `read`, except `logs.read`; `Deny` for every write and for *Can connect*).
- **Why only native Nextcloud groups (`'Database'` backend) can grant permissions:** a group can grant MCP permissions only when Nextcloud serves it exclusively through a single backend whose reported name is `'Database'`. Groups coming from LDAP, SAML, third-party apps, an unidentified backend, or multiple backends at once appear disabled in the group list with the reason. External backends do not reliably deliver membership or deletion events to Nextcloud and can share group IDs across backends; **to give MCP access to members of an external group, grant permissions to those users individually in the *Users* tab.**
- **What "awaiting confirmation" (`needs_confirm`) means and how to resolve it:** if a group creation or deletion is rolled back or observed while another database transaction is open, or if a group's backend classification changes, the app marks the group as **awaiting confirmation**. While in this state, the group grants nothing, and its saved permissions are preserved rather than deleted automatically. To restore the group, open *Access and permissions → Groups*, review the group's permissions and click **Confirm current policy**.
  - A group awaiting confirmation only makes a user's access temporarily unavailable (HTTP 503, without revoking their OAuth connections) when the user's eligibility or tool permission depended **exclusively** on that group. If the user has an individual `Allow` override or another valid group that grants the permission, they continue working normally. In `tools/list`, a tool whose grant is temporarily unknown is omitted rather than failing the whole list (and if a tool accepts alternative grants, one known allowed grant is enough).
  - When an administrator removes *Can connect* from a group (or deletes a group), if the app cannot verify remaining eligibility for any member with active credentials, the save is refused (503) without changing anything so the administrator can retry. (*TODO: F1/F2 refinements on branch `fix/1-1-0-r2-groups-f1-f2` — marking a deleted group `pending_delete` before enumerating credential holders, refreshing group state on new connections, and preserving connections when an individual `Allow` override is reset to `Inherit` while a group still keeps the user eligible.*)
- **Guarantee and declared limits:** the app guarantees enforcement **by delivered events** — it applies every user and group signal that Nextcloud delivers outside a foreign database transaction. Because Nextcloud does not expose transactional hooks or authenticated backend identities, the following limits apply by design:
  1. **Group deleted and recreated with the same ID (`GID`) when signals are lost:** if the PHP process dies before the end-of-request processing runs, during the short window between Nextcloud's commit and that deferred processing (including a delete and recreate within the same transaction), or if a foreign transaction is still open when the process exits, a newly recreated group with the same `GID` may inherit the previous group's saved policy, and concurrent requests during that window may still evaluate the old policy.
  2. **Backend name `'Database'`:** Nextcloud does not reserve or authenticate group backend names (`INamedBackend`). Any group backend installed by a server administrator that reports its name as `'Database'` is treated as the native database backend.
  3. **Backend source changing away and back between observations:** if a group's backend changes to an untrusted source and changes back to `'Database'` before the app observes it, the intermediate change is not detected (any observed loss of trust suspends the group into awaiting confirmation).
  4. **In-memory caches in long-running processes (`occ` / workers):** Nextcloud's `Database::groupExists` caches positive results in memory without invalidating on `deleteGroup`, `AppManager` caches enabled apps per process, and `IUserManager::get` memoizes `IUser` objects. Normal web requests use a fresh PHP process per request and are unaffected, whereas long-running CLI or worker processes may observe stale state until they finish.
  5. **Decided exception when revoking OAuth connections on uncertain user events:** by default, when in doubt about whether a user lost eligibility (or when an account is deleted), the app revokes the user's OAuth connections and requires signing in again. The single intentional exception is when a user event is captured inside another caller's open database transaction (where the app never runs SQL, because a SQL error on PostgreSQL would abort Nextcloud's own transaction) and, by the time the app processes the queued recheck without an event timestamp, the user is still eligible: in that case, neither old nor new credentials are revoked, so a user who remained eligible is not unnecessarily disconnected.

### Excluded from MCP access

In *Administration settings → MCP for Cloud → Data protection → Excluded from MCP access*, administrators choose the system tags (restricted or invisible tags are recommended) that hide sensitive files and folders from this MCP connector:

- **Inheritance and storage coverage:** a tag on a file or folder covers the item itself and every file and folder below it at any depth, whether created through MCP, WebDAV or the Nextcloud Files UI. An item reached through a local share inherits the exclusion of its owner's source folder chain. Team folders (`groupfolders`), external storage (`files_external`) and federated shares are checked along their mounted path (including resolving the real parent above mount roots so external storage and individually shared team-folder files are not falsely blocked).
- **Surface covered:** all `files_*` tools (listing, tree, read, name and full-text search, image previews and image search, text edit/replace, create, upload, `mkdir`, copy, move, batch move and undo, versions, shares and one-time `files_checkout` download/upload links), `notes_*`, MCP resources (`nc://files/{path}` and `nc://notes/{id}`), Talk (`talk_attach_file` and `talk_quote_file` check the caller's view of the file and refuse excluded files as not found; `talk_read_messages`, `talk_reply` and `talk_quote` set `attachmentId: null` without a file name when a room attachment is excluded or out of scope), Tasks (`ATTACH` properties pointing to local Nextcloud URLs `/f/`, `/s/` or `/remote.php/...` on any host, IP, loopback or alias, as well as Calendar `X-NC-FILE-ID`/`FILENAME` parameters, are stripped from tool outputs and confirmation plans when the target file is excluded or out of scope while preserved intact in the server's DAV object on edits; public folder links are always stripped while exclusion tags are active), Calendar (event outputs carry no `ATTACH`/file URLs), Deck (card outputs expose only `attachmentCount`) and Server log (while "Hide the text of log messages" is on).
- **Folder operations while exclusion tags are configured:** whenever at least one exclusion tag is configured, the MCP connector refuses to **share** (`files_share`), **create or update a public link** (`files_share` with `link`), **copy** (`files_copy`), or **move to another directory** (`files_move`, `files_move_batch`, `files_undo_batch`) any **folder**, and hides `url` (`url: null`) on existing folder public links in `files_list_shares`.
  - **Why:** Nextcloud's storage API cannot guarantee complete visibility of every descendant inside a folder (unreadable subfolders return empty, unavailable mounts disappear from listings, and listing can have side effects). Sharing, linking, copying, or moving a folder into a shared directory could otherwise expose an excluded file inside it.
  - **What still works and how to perform folder operations:** all operations on **individual files**, renaming a folder within the **same parent directory** (which preserves locks and cannot cross into a new share), and removing an existing folder share (`files_unshare`) continue to work in MCP. Visibility is checked first (an excluded folder or an unreadable tag configuration always answers "not found"); only a visible folder receives the refusal message explaining that folder sharing, copying and moving to another directory must be done in the Nextcloud Files web interface while exclusion tags are active.
- **Fail-closed:** a configuration that cannot be validated or a tag query that fails keeps the item hidden, and the admin page explains the state and offers the repair button (*TODO: A5/Ticket 13 tag & scope diagnostic inspector in progress*).
- **Declared limits:**
  - **This connector only:** the exclusion applies only to this MCP connector. It does not cover other AI tools, other connectors that read the same Nextcloud, the Nextcloud web interface, or files a user uploads manually to an AI.
  - **Federated shares:** checked only against local system tags on this Nextcloud instance; tags on the remote Nextcloud server cannot be read.
  - **Server log:** file names and paths inside log messages are hidden only while **Hide the text of log messages** is enabled (on by default); a bare file name written by another app into a structured log field is not redacted.
  - **Free text:** existing free-text mentions of a file name or path inside Talk messages, calendar descriptions, task descriptions or Deck cards are not redacted.
  - **External storage mounted twice with different roots:** mounting the *same* external directory twice with different roots (for example `/ext` and `/ext/Open`, where a tagged parent folder lies outside the narrower mount) creates two separate Nextcloud storages with distinct file IDs; a tag applied in one mount does not cover reads through the other. Tag the file in the mount where it is read. Two mounts of the *same* root (an alias) share file IDs and are protected together.
  - **Exclusion list cache window (~3 s across processes):** changes saved in the admin UI apply immediately on that web node. If the exclusion tag list is changed via `occ` or on another web node in a multi-node setup, Nextcloud's local appconfig cache (`AppConfig::LOCAL_CACHE_TTL` = 3 s, measured at ~3–4 s on Nextcloud 32–35) may serve the previous list until the TTL expires.
  - **No badge in Nextcloud Files:** the Nextcloud Files interface shows the system tag name as usual, without a separate badge indicating MCP exclusion.

### Per-resource scope

Administrators can optionally restrict an individual user so that, instead of accessing all resources their Nextcloud account can see, they can only access an explicit allow-list of resources in MCP (*TODO: B5 Files scope consumer wiring, B6 Calendar/Tasks scope activation, and B7/Ticket 13 admin scope UI & inspector are in progress*):

- **Supported modules:**
  - **Files** (*TODO: B5 consumer wiring and activation in progress*): allowed folders (by file ID), covering the selected folders and all descendants inside them. `/MCP backups` is restricted to the backups of those allowed folders.
  - **Calendar** (*TODO: B6/B7 activation and admin UI in progress*): allowed event calendars (`VEVENT`). Reading, creating, editing, deleting, moving and transferring events require the calendar (and, on move/transfer, both source and destination calendars) to be in the user's allow-list.
  - **Tasks** (*TODO: B6/B7 activation and admin UI in progress*): allowed task lists (`VTODO`), configured **independently** of Calendar even when events and tasks live in the same underlying Nextcloud calendar.
- **Notes is outside the Files resource scope:** the Notes module has its own storage model and is not restricted by a user's Files folder allow-list (system-tag exclusion still applies to Notes).
- **Precedence and response:** `Exclusion by system tag > Per-resource scope > Native Nextcloud ACL`. Per-resource scope only narrows access; it never grants access to a folder, calendar or task list that Nextcloud ACLs deny, and a system-tagged file inside an allowed folder remains hidden. Out-of-scope resources return the exact same "not found" message as nonexistent or excluded items, and structured references to out-of-scope files in Talk and Tasks are omitted just like excluded files.
- **Who configures it and session behavior:** only administrators can view or edit a user's resource scope (up to 200 items per user and module; every admin query and change is recorded in the audit log without logging resource names), while each user can view their own active scope in *Personal settings → MCP for Cloud*. Changing a user's scope **does not revoke** their active OAuth connections; the new scope applies on the next request.
- **Empty scope, deleted resources and recreated accounts:** setting a module to *Restricted* with an empty list blocks all resources of that module (treated as not found) without erasing the user's module grants. If a selected folder, calendar or task list is deleted in Nextcloud, the scope never widens automatically. If a Nextcloud user account is deleted and another account is recreated with the same UID before background cleanup runs, the new account inherits the previous restriction (never failing open to unrestricted access) until an administrator resets it.
- **What is not supported in 1.1.0:** per-resource scope for Notes, Contacts, Deck and Talk; per-resource scope by group (groups only grant permissions; a user with a per-resource scope remains restricted even when granted module access through a group); connector-level collection exclusion for non-Files modules; and free-text redaction.

### Upgrading, incidents and rollback

- **Zero-step upgrade from 1.0.8:** updating the app runs its native database migrations automatically and seeds the initial policy state without `occ` or manual SQL. Existing individual user grants, server log groups, OAuth settings, exclusion tags and active connections are preserved.
- **Before adopting group grants or per-resource scopes:** the new tables remain empty, and rolling the code back to 1.0.8 leaves existing individual user permissions intact.
- **After adopting group grants or per-resource scopes — do not downgrade to 1.0.8:** downgrading the code to 1.0.8 on the same database is **not supported and unsafe**:
  1. 1.0.8 does not read `mcp_group_grants`, so every permission granted through a group stops applying;
  2. 1.0.8 does not check the OAuth generation and epoch columns (`auth_gen` and `auth_global_epoch`), so OAuth tokens that 1.1.0 invalidated by advancing a user's generation or the global epoch (without deleting rows) would be accepted again until they expire;
  3. 1.0.8 does not read `mcp_scope_modes` or `mcp_scope_items`, so users restricted to specific folders, calendars or task lists would regain unrestricted MCP access to all of their Nextcloud resources.
- **Safe incident recovery after adoption:** either restore **both code and database together** from a coordinated backup taken before the upgrade (with the MCP service turned off during the restore), or roll forward to a fixed 1.1.x build keeping the 1.1.0 schema and epochs intact.

## Development

```bash
cd mcp
composer install
vendor/bin/phpunit          # unit tests with OCP mocks, no Nextcloud needed
```

Code layout: `lib/Tools/<Module>` (one `ToolModule` per app), `lib/OAuth` (authorization server), `lib/Service` (MCP protocol, grant policy), `lib/Controller`, `templates`, `js`, `css`, `l10n`. Detailed technical notes live in [`mcp/README.md`](mcp/README.md).

The original Node.js stdio prototype (read-only) was removed; it remains in the git history up to commit 27739f0 (tag v0.11.0).

## Changelog

See [`CHANGELOG.md`](CHANGELOG.md).

## Roadmap

- Native review of the Spanish translation

## License

AGPL-3.0-or-later, with the additional section 7(b) attribution term in [NOTICE](NOTICE). The complete license is in [LICENSES/AGPL-3.0-or-later.txt](LICENSES/AGPL-3.0-or-later.txt). Preserve the Sintax attribution in the administration page, documentation and copyright notices when redistributing or making modified versions available over a network.

## Credits

The package includes Smalot PdfParser, Symfony Polyfill Mbstring and the Composer autoloader. See versions, licenses, copyright and development dependencies in [THIRD-PARTY-NOTICES.md](mcp/THIRD-PARTY-NOTICES.md).

**Contributors.** Thanks to @NightDragon1 (#5) for the root visibility check and to @matt-ek (#4) for the report behind the hidden-tags audit and (#10) for the German translation.

## About Sintax

Developed by [Sintax](https://sintax.tech) · sintax.tech.

- Website: https://sintax.tech
- GitHub: https://github.com/sintax-tech
- Contact: contato@sintax.tech
- Other Nextcloud apps by Sintax: [MCP for Cloud](https://apps.nextcloud.com/apps/mcp) · [Audiolog](https://apps.nextcloud.com/apps/audiolog)
