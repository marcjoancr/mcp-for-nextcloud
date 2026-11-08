<?php
/** SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Events;
/** Targeted folder metadata change; automatic special-role inference must not widen its approved scope. */
final class GuardedMailboxesSynchronizedEvent extends MailboxesSynchronizedEvent {}
