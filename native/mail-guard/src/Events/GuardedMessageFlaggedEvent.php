<?php
/** SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Events;
/** The exact cache row was already updated under native row locks; ordinary listeners still receive the event once. */
final class GuardedMessageFlaggedEvent extends MessageFlaggedEvent {}
