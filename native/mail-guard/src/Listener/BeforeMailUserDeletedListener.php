<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Listener;
use OCP\EventDispatcher\{Event,IEventListener};
use OCP\User\Events\BeforeUserDeletedEvent;
/** @template-implements IEventListener<BeforeUserDeletedEvent> */
final class BeforeMailUserDeletedListener implements IEventListener {
    public function __construct(private \OCA\Mail\Lifecycle\MailErasureService $erasure){}
    public function handle(Event $event):void {if($event instanceof BeforeUserDeletedEvent)$this->erasure->before($event->getUser());}
}
