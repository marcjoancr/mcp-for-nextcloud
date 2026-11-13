<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Comments;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Comments\IComment;
use OCP\Comments\ICommentsManager;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
/** Native form admission and the Forms transaction are supplied by the qualified companion. */
final class FormsComments {
    public function __construct(private ICommentsManager $comments,private IUserManager $users,private IUserSession $session,
        private IDBConnection $db,private LoggerInterface $logger) {}
    public static function isAuthor(IComment $comment,string $uid):bool {return $comment->getActorType()==='users'&&$comment->getActorId()===$uid;}
    private function row(IComment $c,string $uid):array {
        return ['id'=>$c->getId(),'message'=>$c->getMessage(),'actorId'=>$c->getActorId(),
            'actorDisplayName'=>$this->users->getDisplayName($c->getActorId())??'','creationDateTime'=>$c->getCreationDateTime()?->format('c'),
            'parentId'=>$c->getParentId(),'canEdit'=>self::isAuthor($c,$uid),'canDelete'=>self::isAuthor($c,$uid)];
    }
    private function current(int $formId,string $id):IComment {
        if(!ctype_digit($id)||(int)$id<1)throw new ToolFailure(ToolFailure::NOT_FOUND);
        // This public API queries the database and refreshes the core runtime cache.
        $rows=$this->comments->getCommentsWithVerbForObjectSinceComment('forms',(string)$formId,['comment'],(int)$id,'asc',1,true);
        $comment=$rows[0]??null;
        if(!$comment instanceof IComment||(string)$comment->getId()!==$id||$comment->getObjectType()!=='forms'||(string)$comment->getObjectId()!==(string)$formId)throw new ToolFailure(ToolFailure::NOT_FOUND);
        return $comment;
    }
    public function snapshot(string $uid,int $formId,?string $commentId):array {
        if($this->session->getUser()?->getUID()!==$uid)throw new ToolFailure(ToolFailure::FORBIDDEN);
        return $commentId===null?[]:$this->row($this->current($formId,$commentId),$uid);
    }
    public function read(string $uid,array $args):array {
        $limit=min(100,max(1,$args['limit']??50));$offset=max(0,$args['offset']??0);
        $rows=$this->comments->getForObject('forms',(string)$args['id'],$limit+1,$offset);$more=count($rows)>$limit;
        return ['comments'=>array_map(fn(IComment $c):array=>$this->row($c,$uid),array_slice($rows,0,$limit)),'hasMore'=>$more,'nextOffset'=>$more?$offset+$limit:null];
    }
    private function assertSaved(IComment $comment,string $uid,int $formId):void {
        $id=$comment->getId();
        if(!ctype_digit($id)||(int)$id<1)$this->unverified();
        $row=$this->db->executeQuery('SELECT * FROM *PREFIX*comments WHERE id = ?',[(int)$id])->fetch();
        if(!$row||$row['object_type']!=='forms'||(string)$row['object_id']!==(string)$formId
            ||$row['actor_type']!=='users'||$row['actor_id']!==$uid||$row['verb']!=='comment'
            ||$row['message']!==$comment->getMessage())$this->unverified();
    }
    private function unverified():never {
        $this->logger->debug('MCP Forms comment persistence could not be verified.',['app'=>'mcp']);
        throw new ToolFailure('The Forms comment change could not be verified.');
    }
    /** Called only inside the companion's owned native transaction after fresh form/comment-policy admission. */
    public function apply(string $uid,string $action,array $args,array $approved):array {
        if($this->session->getUser()?->getUID()!==$uid)throw new ToolFailure(ToolFailure::FORBIDDEN);
        if($action==='forms_add_comment') {
            $comment=$this->comments->create('users',$uid,'forms',(string)$args['id']);
            $comment->setMessage($args['message']);$comment->setVerb('comment');
            if($this->comments->save($comment)!==true)$this->unverified();
            $this->assertSaved($comment,$uid,$args['id']);
            $user=$this->session->getUser();
            $this->comments->setReadMark('forms',(string)$args['id'],new \DateTime(),$user);
            return ['id'=>$comment->getId()];
        }
        $id=$args['comment_id'];
        $row=$this->db->executeQuery('SELECT * FROM *PREFIX*comments WHERE id = ? FOR UPDATE',[(int)$id])->fetch();
        if(!$row||$row['object_type']!=='forms'||(string)$row['object_id']!==(string)$args['id']||$row['actor_type']!=='users'||$row['actor_id']!==$uid||$row['message']!==($approved['message']??null))throw new ToolFailure('The approved comment changed or is unavailable.');
        $comment=$this->current($args['id'],$id);
        if($this->row($comment,$uid)!==$approved||!self::isAuthor($comment,$uid))throw new ToolFailure('The approved comment changed or is unavailable.');
        // The native DAV author rule is already checked above under the same locked row.
        // Use the public manager result: CommentNode discards delete()'s false return.
        if($action==='forms_update_comment') {
            $comment->setMessage($args['message']);
            if($this->comments->save($comment)!==true)$this->unverified();
            $this->assertSaved($comment,$uid,$args['id']);
        } elseif($action==='forms_delete_comment') {
            if($this->comments->delete($id)!==true)$this->unverified();
            if($this->db->executeQuery('SELECT id FROM *PREFIX*comments WHERE id = ?',[(int)$id])->fetch()!==false)$this->unverified();
        }
        else throw new \InvalidArgumentException('Unsupported Forms comment action.');
        return ['id'=>$id];
    }
}
