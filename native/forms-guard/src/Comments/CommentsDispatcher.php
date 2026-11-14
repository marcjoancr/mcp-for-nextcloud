<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Comments;
use OCP\EventDispatcher\{Event,IEventDispatcher};
final class CommentsDispatcher implements IEventDispatcher {
    public function __construct(private IEventDispatcher $dispatcher,private GuardedCommentsManager $manager) {}
    public function addListener(string $eventName,callable $listener,int $priority=0): void {$this->dispatcher->addListener($eventName,$listener,$priority);}
    public function removeListener(string $eventName,callable $listener): void {$this->dispatcher->removeListener($eventName,$listener);}
    public function addServiceListener(string $eventName,string $className,int $priority=0): void {$this->dispatcher->addServiceListener($eventName,$className,$priority);}
    public function hasListeners(string $eventName): bool {return $this->dispatcher->hasListeners($eventName);}
    public function dispatch(string $eventName,Event $event): void {$this->dispatcher->dispatch($eventName,$event);}
    public function dispatchTyped(Event $event): void {
        if ($event instanceof \OCP\Comments\CommentsEvent && $event->getComment()->getObjectType()==='forms') {
            \OCP\Server::get(CommentsOutbox::class)->capture($event,$this->manager);
            return;
        }
        $this->dispatcher->dispatchTyped($event);
    }
}
