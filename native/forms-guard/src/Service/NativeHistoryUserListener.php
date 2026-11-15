<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
final class NativeHistoryUserListener implements \OCP\EventDispatcher\IEventListener {
 public function handle(\OCP\EventDispatcher\Event $event):void {if($event instanceof \OCP\User\Events\BeforeUserCreatedEvent){NativeHistoryIdentity::beforeCreate($event->getUid());}}
}
