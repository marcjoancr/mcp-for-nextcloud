<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Exports;
use OCA\Mcp\Access\BackupPolicy\BackupLocation;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Modules\Forms\Attachments\FormsAnswerFiles;
use OCA\Mcp\Modules\Forms\Attachments\FormsFileImpact;
use OCA\Mcp\Modules\Forms\Attachments\FormsFilePreimage;
use OCP\Files\IRootFolder;
use OCP\Files\File;
use OCP\Files\Node;
use OCP\IURLGenerator;
/** Original approval, exact output and independent Files policy remain mandatory inside native storage admission. */
final class FormsLinkedFiles implements FormsLinkedFileSink {
    public function __construct(private IRootFolder $root,private GrantPolicy $policy,private \Closure $authorize,
        private \Closure $native,private FormsExportDestination $destination,private FormsExportJobs $jobs,
        private FormsLinkedAudience $audience,private FormsLinkedRecovery $recovery,
        private FormsAnswerFiles $files,private FormsFileImpact $impact,private IURLGenerator $urls,private BackupLocation $backups) {}
    private function original(array $claim):string {
        $origin=$claim['origin'];$context=($this->authorize)($origin);
        if(($context['uid']??null)!==($origin['uid']??null))throw new \RuntimeException('Original linked Files authority changed.');
        return $context['uid'];
    }
    private function arguments(array $claim):array {
        return array_replace($claim['arguments'],['path'=>$claim['last_verified']['path'],'overwrite'=>true,'etag'=>$claim['last_verified']['etag']]);
    }
    private function assertAudience(string $uid,array $claim):void {
        $expected=$claim['destination']['audience']['digest']??null;
        if(!is_string($expected)||preg_match('/^[a-f0-9]{64}$/D',$expected)!==1
            ||!hash_equals($expected,$this->audience->snapshot($uid,$claim['last_verified']['path'])['digest']))
            throw new \RuntimeException('The approved linked Files audience or location changed.');
    }
    public function snapshot(array $claim):array {
        $uid=$this->original($claim);
        return $this->policy->resourceBoundary($uid,function()use($uid,$claim):array {
            $this->assertAudience($uid,$claim);$snapshot=$this->destination->snapshot($uid,$this->arguments($claim),false);
            $last=$claim['last_verified'];
            if(($snapshot['file_id']??null)!==$last['file_id']||($snapshot['preimage']['size']??null)!==$last['size']
                ||!hash_equals($last['sha256'],$snapshot['preimage']['sha256']??''))
                throw new \RuntimeException('The last verified linked output changed.');
            $file=$this->root->getUserFolder($uid)->get($last['path']);
            if(!$file instanceof File)throw new \RuntimeException('The linked output is unavailable.');
            return $snapshot+['_linked_recovery'=>$this->recovery->capture($uid,$file,$snapshot['preimage'])];
        });
    }
    public function references(array $claim,array $submission,\Closure $source):array {
        $uid=$this->original($claim);
        $visible=FormsExportFileReferences::collect(
            static fn(int $offset,int $limit):array=>$source('forms_list_response_files',['id'=>$claim['form_id'],'submission_id'=>$submission['id'],'offset'=>$offset,'limit'=>$limit]),
            fn(array $refs):array=>$this->policy->resourceBoundary($uid,fn():array=>$this->files->listing($uid,$refs)));
        $out=[];
        foreach($visible as $file)$out[$file['file_id']]=['name'=>$file['name'],'url'=>$this->urls->linkToRouteAbsolute('files.View.showFile',['fileid'=>$file['file_id']])];
        return $out;
    }
    private function admitted(string $uid,array $claim,array $job,\Closure $source):void {
        $this->original($claim);$this->jobs->assertDelivery($uid,$job,$source);$this->assertAudience($uid,$claim);
        $refs=array_map(static fn(int $id):array=>['file_id'=>$id],array_values($job['payload']['file_ids']??[]));
        $refs[]=['file_id'=>$claim['last_verified']['file_id']];$this->impact->assertVisible($uid,['files'=>$refs]);
    }
    private function backupPath(string $uid,Node $node,?string $child):string {
        $relative=$this->root->getUserFolder($uid)->getRelativePath($node->getPath());
        if(!is_string($relative)||$node->getOwner()?->getUID()!==$uid)throw new \RuntimeException('Original backup ownership is unavailable.');
        $path=trim($relative,'/').($child===null?'':'/'.$child);$path=ltrim($path,'/');
        $reserved=$this->backups->current()['folder'];
        if($path!==$reserved&&!str_starts_with($path,$reserved.'/'))throw new \RuntimeException('The original backup configuration changed.');
        return $path;
    }
    public function write(array $claim,string $content,array $job,\Closure $source):array {
        $uid=$this->original($claim);$approved=$job['payload']['destination'];
        if($this->snapshot($claim)!==$approved)throw new \RuntimeException('The linked destination or recovery approval changed.');
        $proof=$approved['_linked_recovery']??throw new \RuntimeException('Linked recovery proof is unavailable.');
        unset($approved['_linked_recovery']);$copy='';$native=($this->native)();
        $output=function(Node $node,?string $child,\Closure $filePolicy,\Closure $write,?array $preimage=null)use($uid,$claim,$job,$source,$native,$proof,&$copy):mixed {
            $authorize=function(string $stage='preflight')use($uid,$claim,$job,$source,$node,$child,$filePolicy,$preimage,$proof,&$copy):void {
                $this->policy->resourceBoundary($uid,function()use($uid,$claim,$job,$source,$node,$child,$filePolicy,$preimage,$stage,$proof,&$copy):void {
                    $this->admitted($uid,$claim,$job,$source);
                    if($child!==null||!$node instanceof File||(int)$node->getId()!==$claim['last_verified']['file_id'])
                        throw new \RuntimeException('Linked output identity changed.');
                    $filePolicy();FormsFilePreimage::assert($node,$preimage??throw new \RuntimeException('Linked preimage is unavailable.'));
                    if($stage==='content')$this->recovery->assert($uid,$node,$proof,$copy,'content');
                });
            };
            return $native->deferredFileBoundary($claim['origin'],$node,$child,$authorize,$write);
        };
        $backup=function(Node $node,?string $child,\Closure $filePolicy,\Closure $write)use($uid,$claim,$job,$source,$native):mixed {
            if(!method_exists($native,'deferredBackupBoundary'))throw new \RuntimeException('Deferred backup storage has not been qualified.');
            $authorize=function(string $stage='preflight')use($uid,$claim,$job,$source,$node,$child,$filePolicy):void {
                $this->policy->resourceBoundary($uid,function()use($uid,$claim,$job,$source,$node,$child,$filePolicy):void {
                    $this->admitted($uid,$claim,$job,$source);$filePolicy();$path=$this->backupPath($uid,$node,$child);
                    if($this->audience->snapshot($uid,$path)['recipients']!==[])throw new \RuntimeException('The private recovery copy has additional recipients.');
                });
            };
            return $native->deferredBackupBoundary($claim['origin'],$node,$child,$authorize,$write);
        };
        $ready=function(string $path)use($uid,$claim,$proof,&$copy):void {
            $file=$this->root->getUserFolder($uid)->get($claim['last_verified']['path']);
            if(!$file instanceof File)throw new \RuntimeException('The original output is unavailable.');
            $this->recovery->assert($uid,$file,$proof,$path,'after_backup');$copy=$path;
        };
        return $this->policy->resourceBoundary($uid,fn():array=>$this->destination->save($uid,$this->arguments($claim),$approved,$content,$output,false,$backup,$ready));
    }
}
