<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Comments;
use OCP\{Server,IDBConnection};
use OCP\Comments\CommentsEvent;
use OCP\Comments\Events\{CommentAddedEvent,BeforeCommentUpdatedEvent,CommentUpdatedEvent,CommentDeletedEvent};
use OCA\Forms\Mcp\Service\{FormsRevisionFence,OriginContext,FormsEffectOutbox};
final class CommentsOutbox {
    private array $pending=[];
    private bool $delivering=false;
    public function __construct(private IDBConnection $db,private FormsRevisionFence $fence) {}
    public function capture(CommentsEvent $event,GuardedCommentsManager $manager): void {
        if (!$this->fence->active()) {throw new \LogicException('Forms comment event requires its mutation transaction');}
        if (!in_array($event::class,[CommentAddedEvent::class,BeforeCommentUpdatedEvent::class,CommentUpdatedEvent::class,CommentDeletedEvent::class],true)) {throw new \RuntimeException('Unqualified Forms comment event');}
        if (count($this->pending)>=100) {throw new \RuntimeException('Forms comment event budget exceeded');}
        $uid=CommentsRuntime::actor();
        $comment=$event->getComment();
        $authority=CommentsRuntime::admit($uid,(int)$comment->getObjectId());
        $origin=OriginContext::active()?OriginContext::current():null;
        $payload=['event'=>$event::class,'comment'=>CommentSnapshot::freeze($comment),'uid'=>$uid,'authority'=>$authority,
            'origin'=>$origin===null?null:['origin'=>$origin['origin'],'input'=>$origin['input'],'action'=>$origin['action']]];
        $json=json_encode($payload,JSON_THROW_ON_ERROR);
        if (strlen($json)>1048576) {throw new \RuntimeException('Forms comment event exceeds budget');}
        $key=bin2hex(random_bytes(32));
        $operation=Server::get(FormsEffectOutbox::class)->operationKey()??'';
        $encrypted=Server::get(\OCP\Security\ICrypto::class)->encrypt($json);
        $this->db->executeStatement('INSERT INTO *PREFIX*forms_mcp_comment_events (effect_key,payload) VALUES (?,?)',[$key,$encrypted]);
        $this->db->executeStatement('INSERT INTO *PREFIX*forms_mcp_effects (effect_key,operation_key,kind,form_id,entity_id,phase,payload_ref) VALUES (?,?,?,?,?,?,?)',
            [$key,$operation,'comment',(int)$comment->getObjectId(),(int)$comment->getId(),'pending',$key]);
        $this->pending[]=[$key,$manager];
    }
    public function drain(): void {
        if ($this->delivering || $this->db->inTransaction()) {return;}
        $this->delivering=true;
        $pending=$this->pending;
        $this->pending=[];
        $deadline=microtime(true)+20;
        try {
            foreach ($pending as [$key,$manager]) {
                if (microtime(true)>$deadline) {break;}
                $this->fence->withLock(function()use($key,$manager) {
                    // The durable claim commits before every consumer. Lost acknowledgement forbids replay.
                    if ($this->db->executeStatement('UPDATE *PREFIX*forms_mcp_effects SET phase = ? WHERE effect_key = ? AND phase = ?',['uncertain',$key,'pending'])!==1) {return;}
                    try {
                        $encrypted=$this->db->executeQuery('SELECT payload FROM *PREFIX*forms_mcp_comment_events WHERE effect_key = ?',[$key])->fetchOne();
                        if (!is_string($encrypted) || strlen($encrypted)>2097152) {throw new \RuntimeException('Invalid comment envelope');}
                        $payload=json_decode(Server::get(\OCP\Security\ICrypto::class)->decrypt($encrypted),true,64,JSON_THROW_ON_ERROR);
                        $deliver=function()use($payload,$manager):void {
                            $authority=CommentsRuntime::admit($payload['uid'],(int)$payload['comment']['object_id'],true);
                            if ($authority!==$payload['authority']) {throw new \RuntimeException('Native comment authority changed');}
                            $class=$payload['event'];
                            if (!in_array($class,[CommentAddedEvent::class,BeforeCommentUpdatedEvent::class,CommentUpdatedEvent::class,CommentDeletedEvent::class],true)) {throw new \RuntimeException('Unknown comment event');}
                            $manager->deliver(new $class(CommentSnapshot::thaw($payload['comment'])));
                        };
                        if ($payload['origin']===null) {$deliver();}
                        else {OriginContext::within($payload['uid'],$payload['origin']['origin'],'effect',$payload['origin']['input'],$deliver);}
                        $this->db->executeStatement('UPDATE *PREFIX*forms_mcp_effects SET phase = ? WHERE effect_key = ?',['delivered',$key]);
                    } catch (\Throwable) { /* A refused or ambiguous consumer is never replayed. */ }
                });
            }
        } finally {$this->delivering=false;}
    }
}
