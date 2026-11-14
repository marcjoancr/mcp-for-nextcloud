<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Comments;
use OCP\Comments\{IComment,CommentsEvent,ICommentsEventHandler};
use OCP\Server;
use OCA\Forms\Mcp\Service\FormsRevisionFence;
final class GuardedCommentsManager extends \OC\Comments\Manager {
    private bool $parentMetadata=false;
    private array $legacy=[];
    private \OCP\EventDispatcher\IEventDispatcher $nativeDispatcher;
    public static function createManager(\Psr\Container\ContainerInterface $container): self {
        $manager=new self($container->get(\OCP\IDBConnection::class),$container->get(\Psr\Log\LoggerInterface::class),$container->get(\OCP\IConfig::class),
            $container->get(\OCP\AppFramework\Utility\ITimeFactory::class),$container->get(\OCP\IEmojiHelper::class),$container->get(\OCP\IInitialStateService::class),
            $container->get(\OCP\Files\IRootFolder::class),$container->get(\OCP\EventDispatcher\IEventDispatcher::class));
        $manager->nativeDispatcher=$manager->eventDispatcher;
        $manager->eventDispatcher=new CommentsDispatcher($manager->nativeDispatcher,$manager);
        return $manager;
    }
    public function registerEventHandler(\Closure $closure) {
        $handler=null;
        $resolve=static function()use($closure,&$handler): ICommentsEventHandler {
            return $handler??= $closure();
        };
        $this->legacy[]=$resolve;
        parent::registerEventHandler(static function()use($resolve) {
            // Resolve legacy consumers only when actually delivering their event.
            return new class($resolve) implements ICommentsEventHandler {
                private ?ICommentsEventHandler $handler=null;
                public function __construct(private \Closure $factory) {}
                public function handle(CommentsEvent $event) {
                    if ($event->getComment()->getObjectType()==='forms') {return;}
                    $this->handler??=($this->factory)();
                    $this->handler->handle($event);
                }
            };
        });
    }
    public function deliver(CommentsEvent $event): void {
        foreach ($this->legacy as $factory) {
            $handler=$factory();
            if (!$handler instanceof ICommentsEventHandler) {throw new \RuntimeException('Invalid legacy comment consumer');}
            $handler->handle($event);
        }
        $this->nativeDispatcher->dispatchTyped($event);
    }
    private function mutate(callable $work): mixed {
        return Server::get(FormsRevisionFence::class)->transaction(function()use($work) {
            Server::get(FormsRevisionFence::class)->afterTransaction(fn()=> $this->commentsCache->clear());
            try {return $work();} finally {$this->commentsCache->clear();}
        });
    }
    public function save(IComment $comment) {
        if ($comment->getObjectType()!=='forms') {
            if ($comment->getId()!=='') {
                $storedType=$this->dbConn->executeQuery('SELECT object_type FROM *PREFIX*comments WHERE id = ?',[(int)$comment->getId()])->fetchOne();
                if ($storedType==='forms') {throw new \RuntimeException('A Forms comment cannot change its object type');}
            }
            return parent::save($comment);
        }
        return $this->mutate(function()use($comment) {
            $uid=CommentsRuntime::actor();
            CommentsRuntime::admit($uid,(int)$comment->getObjectId());
            CommentsRuntime::mutation($comment->getId()===''?'forms_add_comment':'forms_update_comment',$comment->getId(),$this->parentMetadata);
            if (!$this->parentMetadata) {
                CommentsRuntime::author($comment,$uid);
                if ($comment->getId()!=='') {
                    $this->uncache($comment->getId());
                    $old=$this->get($comment->getId());
                    CommentsRuntime::author($old,$uid);
                    if ($old->getObjectType()!=='forms' || $old->getObjectId()!==$comment->getObjectId()) {throw new \RuntimeException('Comment object changed');}
                }
            }
            if ($comment->getParentId()!=='0') {
                $this->uncache($comment->getParentId());
                $parent=$this->get($comment->getParentId());
                if ($parent->getObjectType()!=='forms' || $parent->getObjectId()!==$comment->getObjectId()) {throw new \RuntimeException('Comment parent belongs to another object');}
            }
            if (!parent::save($comment)) {throw new \RuntimeException('Native comment persistence failed');}
            return true;
        });
    }
    protected function updateChildrenInformation($id,\DateTime $date): void {
        $previous=$this->parentMetadata;
        $this->parentMetadata=true;
        try {parent::updateChildrenInformation($id,$date);} finally {$this->parentMetadata=$previous;}
    }
    public function delete($id) {
        $this->uncache($id);
        try {$comment=$this->get($id);} catch (\OCP\Comments\NotFoundException) {return parent::delete($id);}
        if ($comment->getObjectType()!=='forms') {return parent::delete($id);}
        return $this->mutate(function()use($id) {
            $this->uncache($id);
            $comment=$this->get($id);
            $uid=CommentsRuntime::actor();
            CommentsRuntime::admit($uid,(int)$comment->getObjectId());
            CommentsRuntime::mutation('forms_delete_comment',$comment->getId());
            CommentsRuntime::author($comment,$uid);
            if (!parent::delete($id)) {throw new \RuntimeException('Native comment deletion failed');}
            return true;
        });
    }
    public function deleteReferencesOfActor($actorType,$actorId): bool {
        // Account cleanup can affect Forms and other objects in one native bulk UPDATE.
        return $this->mutate(fn()=>parent::deleteReferencesOfActor($actorType,$actorId));
    }
    public function deleteCommentsAtObject($objectType,$objectId): bool {
        if ($objectType!=='forms') {return parent::deleteCommentsAtObject($objectType,$objectId);}
        // Native owner-authorized form cleanup also runs after the form row was deleted.
        return $this->mutate(fn()=>parent::deleteCommentsAtObject($objectType,$objectId));
    }
    public function deleteCommentsExpiredAtObject(string $objectType,string $objectId=''): bool {
        if ($objectType!=='forms') {return parent::deleteCommentsExpiredAtObject($objectType,$objectId);}
        return $this->mutate(fn()=>parent::deleteCommentsExpiredAtObject($objectType,$objectId));
    }
}
