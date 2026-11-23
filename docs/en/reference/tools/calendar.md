<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Calendar

| Module | Read | Write |
| --- | --- | --- |
| **Calendar** | list calendars and events (recurrence, time zones, all-day) | create, edit, move, delete and transfer events through the real CalDAV pipeline, with participants. The writes are off until an administrator grants them in the admin matrix (no terminal step), and each one returns a plan first: **nothing is written before the user approves it.** |

| Tool | Grant | Notes |
| --- | --- | --- |
| `calendar_list_calendars` `{}` | calendar.read | Calendars visible to the user, own and shared. |
| `calendar_list_events` `{calendar?, from?, to?}` | calendar.read | Events in the range (default: next 7 days), with recurrence expanded. |
| `calendar_create_event` | calendar.create | Creates an event. The optional `recurrence` is a bounded weekly or monthly rule. `attendees` accepts internal account UIDs. |
| `calendar_update_event` | calendar.edit | Updates the event or the selected `recurrence_id`. The guest list of a series and its recurring timing remain restricted. |
| `calendar_move_event` | calendar.move | MOVE within the same owner; attendees are not notified, as in the Calendar app. |
| `calendar_delete_event` | calendar.delete | Deletes a series into the trash bin or excludes one original occurrence when `recurrence_id` is specified. |
| `calendar_transfer_event` | calendar.transfer | MOVE to a calendar of another owner shared with write access; refuses attendees. |

Calendar exposes reading and writing through MCP. The writes start off in the admin matrix (Administration → MCP) and only appear for those who received the grant; every write also requires `confirm: true`, the Nextcloud ACL (and `confirm_shared` on someone else's calendar) and the optional ETag. Compatibility: the app declares `min-version` 33 and `max-version` 35 in `info.xml`; the internal part (the core's `EmbeddedCalDavServer`) is covered by the tests and by that range, and a new Nextcloud version requires a new version of the app. The Calendar app must be enabled for the user. Sabre and Symfony Console come from the Nextcloud core and stay out of the package's `vendor/`.

Create, update and delete use `send_invitations: false` by default (`x-nc-scheduling: false`). `true` hands the invitation to Nextcloud's CalDAV scheduling, including existing guests and CANCEL. The plan and the result report `imipEnabled`, read from the native `dav/sendInvitations` setting: with it off the server does not send email (the internal iTIP still reaches the guest's inbox) and the assistant must tell the user so. The app never confirms email delivery. Writes on someone else's calendar still require `confirm_shared: true` after user confirmation.

**Confirmation before every write (no state on the server).** The event writes in the table, called without `confirm: true`, only return a plan (`requiresConfirmation: true`): current and proposed values, attendees added/removed, destination, consequence for invitations/CANCEL/trash bin and the current `etag`. Nothing is written or scheduled. The assistant must show the plan, ask the user and only after an explicit yes repeat the call with `confirm: true` and the same arguments, resending the `etag` of the plan (optional; if it comes and differs, the write is refused and a new plan is needed). There is no table, token or approval record: the server does not prove the human yes, but permissions, ACL, grants and `confirm_shared` keep applying. The selftest uses the handlers directly and does not go through this flow.

See the live `mcp_guide` for the authoritative schema and available operations.

## Meetings with attendees

- Use `calendar_search_events` and `calendar_read_event` to find events and read visible details, including invitation responses. Search applies the confidentiality of the event before matching text.
- Use `calendar_find_slots` with a window, duration and internal account IDs. A suggested slot carries the result of the availability checks. Availability can be unknown, and a suggestion does not reserve time.
- Use `calendar_create_event_with_external_attendees` or `calendar_update_event_with_external_attendees` for explicit email addresses. Read the contact first to select an address if needed. These tools have independent assignments and respect the server setting for external attendees. The preview distinguishes scheduling from mail delivery.
- Use `calendar_respond_to_invitation` to change your own response on your calendar. Repeat the confirmed call with the preview ETag.
- For a series occurrence, give the original `recurrence_id` rather than the new start of a moved exception. Excluding an occurrence can send a scheduling update for the series with that single date excluded.

[Operational guides](../../guides/README.md).

## Rooms and resources

Use `calendar_list_resources` to discover rooms and equipment visible to you. Use `calendar_check_resource_availability` to ask CalDAV about a window. An unknown status means the server could not verify the window. Use `calendar_reserve_resource` on a non-recurring event you organize in your own calendar. Review the scheduling plan and confirm it with the ETag. The result reports the room's response separately from the event update. When changing the time or cancelling the event, enable scheduling so the request also reaches the room.

## Appointment links

Use `calendar_list_appointments` to find booking configurations on your own visible calendars. Create, edit or retire a configuration with `calendar_create_appointment`, `calendar_update_appointment` and `calendar_delete_appointment`. Review the time zone, weekly schedule, appointment length, booking window and visibility in the plan. Weekly slots use local HH:mm times. Durations and buffers are in minutes, and the booking horizon is in days. Repeat the approved write with the plan's `plan_state`.

Private visibility keeps the link off your public profile. Anyone with the URL can still book. Public visibility also lists it on your profile. Deleting the configuration retires its booking link. Calendar 6.6.2, 6.6.3 and 6.6.4 expose these tools when the administrator grants them and the connection selects them.

## Invitations, recovery and exchange

- Filter `calendar_search_events` by `participation_status: needs-action` to find invitations awaiting your reply. The filter also accepts `accepted`, `declined` and `tentative`.
- Use `calendar_list_trashed_events` to find a deleted event's ID and `calendar_restore_trashed_event` to restore it to its original calendar. Recovery follows retention and the scope of calendars you own. Native restore keeps the event data without sending invitations.
- `calendar_create_calendar` creates a personal calendar under unrestricted calendar scope. `calendar_list_calendar_shares` and `calendar_share_calendar` list and manage direct local-account access on calendars you own. Access can be read-only, writable or removed.
- `calendar_import_ics` reads a visible Files document and copies up to 100 events or 1 MiB into a calendar you own. Preview identifies duplicates and conflicts. Import omits organizers, attendees, alarms and attachments. `calendar_export_ics` returns an iCalendar document of selected UIDs from one calendar, including fully visible series exceptions.
- `calendar_list_appointment_bookings` lists pending and confirmed bookings from your visible Calendar 6.6.2, 6.6.3 or 6.6.4 appointment links. Filter by date and optionally by appointment configuration. The result includes the guest name and time slot and keeps email and confirmation tokens private.
- `calendar_set_event_reminders` replaces display alarms relative to the start of an event or series. Enter zero to five lead times in minutes. Custom, email, absolute, repeated and occurrence-specific alarms are preserved.

Writes in these workflows require `confirm: true` and the preview's `plan_state`.

## Availability and conflicts

`calendar_check_availability` requires the independent `calendar.availability` permission and explicit tool selection. Supply `from` (inclusive) and `to` (exclusive), with a maximum window of 31 days. Use optional `calendars` to select up to 20 accessible calendar paths; omit it to use all visible calendars, subject to the same limit. `min_duration_minutes` filters free intervals (default 30, range 5–1440).

The response contains UTC busy intervals, overlap conflicts and common free intervals. Intervals that merely touch do not conflict. Recurring events, recurrence exceptions and timezones follow the existing calendar expansion rules; cancelled and transparent events do not block time. Date-only arguments and floating all-day values follow the existing UTC civil-date convention.

No event title, description or attendee data is returned. Native shares, Calendar scope restrictions and event classification apply. If hidden events, invalid data or scan limits prevent a reliable calculation, the tool returns an error instead of claiming that time is free. The scan allows up to 2,000 calendar objects per calendar and 10,000 occurrences in total. Zero-duration events also make availability unavailable under this conservative policy. Availability is a snapshot of the accessible calendars, not a reservation or a claim about calendars the caller cannot access.

English · [Español](../../../es/reference/tools/calendar.md) · [Català](../../../ca/reference/tools/calendar.md) · [Português (Brasil)](../../../pt-BR/reference/tools/calendar.md) · [Português (Portugal)](../../../pt-PT/reference/tools/calendar.md)
