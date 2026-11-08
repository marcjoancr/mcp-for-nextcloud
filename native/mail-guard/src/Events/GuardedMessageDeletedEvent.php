<?php
/** SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Events;
/** Source cache already removed under generation and identity row locks. Other native listeners still receive it. */
final class GuardedMessageDeletedEvent extends MessageDeletedEvent {}
