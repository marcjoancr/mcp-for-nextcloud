<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Exports;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Modules\Files\Creation\FileCreation;
use OCA\Mcp\Modules\Files\Editing\ContentPlan;
use OCA\Mcp\Modules\Files\Editing\ContentWriter;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Platform\FileAccess\Paths\PathGuard;
use OCA\Mcp\Platform\FileAccess\Policy\FileTypeGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\IRootFolder;
/** Both creation and replacement use the existing Files policy, locks, Versions and backup boundary. */
final class FormsExportDestination {
    public function __construct(private IRootFolder $root,private FilesAccessGuard $access,private FileCreation $creation,
        private ContentPlan $plans,private ContentWriter $writer,private FileTypeGuard $types) {}
    public function snapshot(string $uid,array $args):array {
        $this->access->clearCache();$this->access->assertScopeReadable();$path=PathGuard::normalize($args['path']);
        if(!str_ends_with(strtolower($path),'.'.($args['format']??'csv')))throw new ToolFailure('The destination extension must match the export format.');
        $root=$this->root->getUserFolder($uid);
        if(!($args['overwrite']??false)) {
            $plan=$this->creation->planUpload($root,$uid,$path,null);$target=$this->creation->target($root,$path);
            return ['path'=>$path,'overwrite'=>false,'folder_id'=>(int)$target['folder']->getId(),'plan'=>$plan];
        }
        if(!isset($args['etag']))throw new ToolFailure('Replacing an export requires the current destination ETag.');
        $file=NodeAccess::requireFile(NodeAccess::get($root,$path,$this->access));NodeAccess::checkEtag($file,$args['etag']);
        if(!$file->isReadable()||!$file->isUpdateable()||$file->getSize()>26214400)throw new ToolFailure('The export destination is unavailable.');
        $this->types->assertWritableName($file->getName());
        return ['path'=>$path,'overwrite'=>true,'file_id'=>(int)$file->getId(),'etag'=>(string)$file->getEtag(),
            'plan'=>$this->plans->describe($file,$uid,$path,['action'=>'forms_export_results','format'=>$args['format']??'csv','overwrite'=>true])];
    }
    public function save(string $uid,array $args,array $approved,string $content):array {
        if(strlen($content)>26214400)throw new ToolFailure('The complete export exceeds its destination byte quota.');
        $current=$this->snapshot($uid,$args);
        if($current!==$approved)throw new ToolFailure('The approved export destination changed. Preview a new export.');
        $root=$this->root->getUserFolder($uid);
        if(!$approved['overwrite']) {
            $target=$this->creation->target($root,$approved['path']);
            $file=$this->creation->write($target['folder'],$target['name'],$content,$uid);
            $receipt=$this->creation->receipt($target,$file,$uid);
        } else {
            $file=NodeAccess::requireFile(NodeAccess::get($root,$approved['path'],$this->access));
            if((int)$file->getId()!==$approved['file_id'])throw new ToolFailure('The export destination changed.');
            $receipt=$this->writer->commit($root,$uid,$file,$approved['path'],$content,$approved['etag'],'',false);
            $file=NodeAccess::requireFile(NodeAccess::get($root,$approved['path'],$this->access));
        }
        $stream=$file->fopen('rb');if(!is_resource($stream))throw new ToolFailure('The export write could not be verified.');
        try{$ctx=hash_init('sha256');$size=hash_update_stream($ctx,$stream,26214401);$digest=hash_final($ctx);}finally{if(is_resource($stream))fclose($stream);}
        if($size!==strlen($content)||!hash_equals(hash('sha256',$content),$digest))throw new ToolFailure('The export write could not be verified.');
        return array_intersect_key($receipt,array_flip(['path','fileId','file_id','size','bytes','etag','backup']))+['file_id'=>(int)$file->getId(),'sha256'=>$digest];
    }
}
