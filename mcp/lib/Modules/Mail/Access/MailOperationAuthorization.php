<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Access;
use OCA\Mcp\Modules\Mail\Integration\MailServices;
use OCA\Mcp\Mcp\Operations\OperationJournal;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\ResourceScope\{FilesScope,FilesAccessGuard};
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Platform\FileAccess\Paths\PathGuard;
use OCP\Files\IRootFolder;
/** Builds private immutable original authorization evidence before effects. Never stores body, MIME or credentials. */
final class MailOperationAuthorization {
    private const MESSAGE_WRITES=['mail_set_message_flag','mail_set_message_tag','mail_move_message','mail_archive_message','mail_trash_message','mail_restore_message','mail_refresh_mailbox','mail_export_message','mail_save_attachment','mail_index_messages','mail_snooze'];
    public function __construct(private MailAccessGuard $access,private MailServices $native,private OperationJournal $journal,
        private GrantPolicy $policy,private FilesScope $files,private FilesAccessGuard $fileGuard,private IRootFolder $roots,private \OCA\Mcp\Modules\Mail\Integration\MailTagVisibility $tags,private MailDeliveryOrigin $deliveryOrigins){}
    public function capture(string $uid,string $tool,array $args):void {
        $this->access->assertReadable($uid);
        $refs=['v'=>1,'mail'=>[],'global'=>false,'files'=>[],'tools'=>[]];
        $add=function(int $account,int $mailbox=0)use(&$refs,$uid):void {
            if($mailbox===0)$this->access->requireAccount($uid,$account);else $this->access->requireMailbox($uid,$account,$mailbox);
            $row=['account'=>$account,'mailbox'=>$mailbox];if(!in_array($row,$refs['mail'],true))$refs['mail'][]=$row;
        };
        if(in_array($tool,['mail_add_account','mail_create_tag'],true)){$this->access->requireUnrestricted($uid);$refs['global']=true;}
        if(isset($args['account_id'])&&!in_array($tool,self::MESSAGE_WRITES,true)){$r=$this->identity($uid,'account',(int)$args['account_id']);$add($r['account_id']);}
        if(isset($args['target_account_id'])){$r=$this->identity($uid,'account',(int)$args['target_account_id']);$add($r['account_id']);}
        if(isset($args['draft_id'])){$r=$this->identity($uid,'draft',(int)$args['draft_id']);$add($r['account_id']);}
        $sources=$args['message_ids']??[];
        foreach(['message_id','reply_to_message_id','forward_message_id'] as $key)if(isset($args[$key]))$sources[]=(int)$args[$key];
        if(count($sources)>50)throw new ToolFailure('Mail authorization exceeds its bound.');
        foreach(array_unique($sources) as $id){$r=$this->identity($uid,'message',$id);$add($r['account_id'],$r['mailbox_id']);}
        foreach(['mailbox_id','destination_mailbox_id'] as $key)if(isset($args[$key])){$r=$this->identity($uid,'mailbox',(int)$args[$key]);$add($r['account_id'],$r['mailbox_id']);}
        if(in_array($tool,['mail_archive_message','mail_trash_message'],true)){
            $account=$this->native->accountService($uid)->find($uid,(int)$args['account_id']);
            $entity=$account->getMailAccount();$id=$tool==='mail_archive_message'?$entity->getArchiveMailboxId():$entity->getTrashMailboxId();
            $r=$this->identity($uid,'mailbox',(int)$id);if($r['account_id']!==(int)$args['account_id'])throw new ToolFailure('Mail resource not found.');$add($r['account_id'],$r['mailbox_id']);
        }
        if($tool==='mail_configure_mailboxes')foreach($args['roles']??[] as $id){$r=$this->identity($uid,'mailbox',(int)$id);if($r['account_id']!==(int)$args['account_id'])throw new ToolFailure('Mail resource not found.');$add($r['account_id'],$r['mailbox_id']);}
        if($tool==='mail_set_message_tag'){$visible=$this->tags->requireVisible($uid,(int)$args['tag_id']);if($visible!==null)$add($visible['account_id'],$visible['mailbox_id']);else $refs['global']=true;}
        if($tool==='mail_snooze'){
            $identity=$this->identity($uid,'message',(int)$args['message_id']);$account=$this->native->accountService($uid)->find($uid,$identity['account_id']);$dest=$account->getMailAccount()->getSnoozeMailboxId();
            $r=$this->identity($uid,'mailbox',(int)$dest);if($r['account_id']!==$identity['account_id'])throw new ToolFailure('Mail resource not found.');$add($r['account_id'],$r['mailbox_id']);$refs['tools'][]='mail_wake';
        }
        if(isset($args['snooze_id'])){$r=$this->native->guardedSnooze($uid)->accessContext($uid,$args['snooze_id']);$this->deliveryOrigins->assertCurrent($uid,$r);foreach(['sourceMailboxId','snoozeMailboxId']as$key)$add($r['accountId'],$r[$key]);$refs['tools']=array_merge($refs['tools'],['mail_snooze','mail_wake']);}
        if(isset($args['delivery_id'])){$r=$this->native->guardedDelivery($uid)->accessContext($uid,$args['delivery_id']);$this->deliveryOrigins->assertCurrent($uid,$r);$add($r['accountId']);$refs['tools'][]=$r['sourceTool'];}
        foreach(['setup_operation_id','target_operation_id','evidence_operation_id','repair_of_operation_id'] as $key)if(isset($args[$key])){
            $old=$this->journal->privateResources($uid,$args[$key]);$original=$old['authorization']??null;
            if(!is_array($original))throw new ToolFailure('Operation not found.');$this->assertReferences($uid,$original);
            foreach($original['mail'] as $r)$add($r['account'],$r['mailbox']);$refs['global']=$refs['global']||$original['global'];
            $refs['files']=array_merge($refs['files'],$original['files']??[]);$refs['tools']=array_merge($refs['tools'],$original['tools']??[]);
        }
        $paths=$args['add_files']??[];
        if($paths!==[])$refs['tools'][]='files_read';
        if(isset($args['reply_to_message_id'])||isset($args['forward_message_id']))$refs['tools'][]='mail_read_message';
        foreach($paths as $path)$refs['files'][]=$this->fileChain($uid,$path);
        if(in_array($tool,['mail_save_attachment','mail_export_message'],true))$refs['files'][]=$this->fileChain($uid,dirname(PathGuard::normalize($args['path'])));
        if(count($refs['mail'])>200||count($refs['files'])>20)throw new ToolFailure('Mail authorization exceeds its bound.');
        $refs['tools']=array_values(array_unique($refs['tools']));
        if(!empty($args['forward_attachment_ids']))$refs['tools'][]='mail_read_attachment';
        if(!empty($args['add_files'])||!empty($args['remove_attachment_ids']))$refs['tools']=array_values(array_unique([...$refs['tools'],'files_read','mail_attach_files']));
        $this->access->authorizeReferences($uid,$refs);
        $this->journal->recordAuthorization($uid,$refs);
    }
    private function identity(string $uid,string $kind,int $id):array {
        $reader=null;
        try{$reader=$this->native->boundedMetadata($uid);}catch(\OCA\Mcp\Modules\Mail\Integration\MailUnavailableException){/* Accepted older native artifacts have no scalar scope adapter. */}
        if($reader!==null&&is_callable([$reader,'identities'])){
            $rows=$reader->identities($uid,$kind,[$id]);if(count($rows)!==1)throw new ToolFailure('Mail resource not found.');return $rows[0];
        }
        // Preserve the original owner-scoped APIs only for an unrestricted legacy caller.
        $this->access->requireUnrestricted($uid);$box=0;
        if($kind==='account')$account=$id;
        elseif($kind==='draft')$account=(int)$this->native->draftsService($uid)->getMessage($id,$uid)->getAccountId();
        else {
            $manager=$this->native->mailManager($uid);$box=$kind==='message'?(int)$manager->getMessage($uid,$id)->getMailboxId():$id;
            $account=(int)$manager->getMailbox($uid,$box)->getAccountId();
        }
        $owned=$this->native->accountService($uid)->find($uid,$account);
        if((string)$owned->getUserId()!==$uid||(int)$owned->getId()!==$account)throw new ToolFailure('Mail resource not found.');
        return ['id'=>$id,'account_id'=>$account,'mailbox_id'=>$box];
    }
    private function fileChain(string $uid,string $path):array {
        $this->fileGuard->assertScopeReadable();$path=PathGuard::normalize($path==='.'?'/':$path);
        $node=NodeAccess::run(fn()=>NodeAccess::get($this->roots->getUserFolder($uid),$path,$this->fileGuard));
        $chain=$this->files->candidates($node);if($chain===[]||count($chain)>256)throw new ToolFailure('Files reference unavailable.');return $chain;
    }
    /** Returns additional tool dependencies only after trusted private-store reference authorization. */
    public function historical(string $uid,string $id):array {
        $raw=$this->journal->privateResources($uid,$id);$refs=$raw['authorization']??null;
        if(!is_array($refs)){
            if(!$this->access->unrestricted($uid)||$this->policy->scopeItems($uid,'files')!==null)throw new ToolFailure('Operation not found.');
            return [];
        }
        $this->assertReferences($uid,$refs);return $refs['tools'];
    }
    private function assertReferences(string $uid,array $refs):void {
        $this->access->authorizeReferences($uid,$refs);
        if(!is_array($refs['files']??null)||count($refs['files'])>20||!is_array($refs['tools']??null)||count($refs['tools'])>10)throw new ToolFailure('Operation not found.');
    }
}
