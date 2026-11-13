<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Forms\Attachments;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Modules\Files\Creation\FileCreation;
use OCP\Files\{File, IRootFolder};
/** Native response permission never grants access to Files. Always resolve inside this actor's Files tree. */
final class FormsAnswerFiles {
    public function __construct(private IRootFolder $root, private FilesAccessGuard $access, private FormsAnswerUploads $sources, private FileCreation $creation) {}
    /** @param list<array<string,mixed>> $nativeReferences already authorized native answer references */
    public function listing(string $uid,array $nativeReferences): array {
        $this->access->clearCache();$this->access->assertScopeReadable();$result=[];
        foreach (array_slice($nativeReferences,0,100) as $ref) {
            $node=$this->visible($uid,(int)$ref['file_id']);
            if ($node!==null) {$result[]=['form_id'=>(int)$ref['form_id'],'submission_id'=>(int)$ref['submission_id'],
                'question_id'=>(int)$ref['question_id'],'file_id'=>(int)$node->getId(),'name'=>$node->getName(),'size'=>(int)$node->getSize(),'mime'=>$node->getMimetype()];}
        }
        return $result;
    }
    /** Caller must require files_create and forms_save_response_file before planning or applying. */
    public function snapshotSave(string $uid,array $nativeReferences,int $fileId,string $path): array {
        $this->access->clearCache();$this->access->assertScopeReadable();
        if (!in_array($fileId,array_column($nativeReferences,'file_id'),true)) {throw new \RuntimeException('Forms response file is unavailable');}
        $node=$this->visible($uid,$fileId);if ($node===null) {throw new \RuntimeException('Forms response file is unavailable');}
        $root=$this->root->getUserFolder($uid);$relative=$root->getRelativePath($node->getPath());
        if ($relative===null) {throw new \RuntimeException('Forms response file is unavailable');}
        $source=$this->sources->snapshot($uid,[$relative]);
        $plan=$this->creation->planUpload($root,$uid,$path,$source[0]['size']);
        $target=$this->creation->target($root,$path);
        return ['source'=>$source,'destination'=>['path'=>$target['path'],'folder_id'=>(int)$target['folder']->getId(),'etag'=>$target['folder']->getEtag()],'plan'=>$plan];
    }
    public function save(string $uid,array $manifest): array {
        $this->access->clearCache();$this->access->assertScopeReadable();
        $target=$this->creation->target($this->root->getUserFolder($uid),$manifest['destination']['path']);
        FileCreation::assertCreatable($target['folder']);
        if ((int)$target['folder']->getId()!==$manifest['destination']['folder_id'] || $target['folder']->getEtag()!==$manifest['destination']['etag']) {
            throw new \RuntimeException('Forms save destination changed after preview');
        }
        return $this->sources->withSources($uid,$manifest['source'],function(array $sources) use($uid,$target): array {
            $file=$this->creation->write($target['folder'],$target['name'],$sources[0]['stream'],$uid);
            $stream=$file->fopen('rb');if (!is_resource($stream)) {throw new \RuntimeException('Forms saved file verification failed');}
            try {$hash=hash_init('sha256');$size=hash_update_stream($hash,$stream,FormsAnswerUploads::MAX_BYTES+1);$digest=hash_final($hash);} finally {fclose($stream);}
            if ($size!==$sources[0]['size'] || !hash_equals($sources[0]['sha256'],$digest)) {throw new \RuntimeException('Forms saved file verification failed');}
            return $this->creation->receipt($target,$file,$uid)+['sha256'=>$digest];
        });
    }
    private function visible(string $uid,int $id): ?File {
        foreach ($this->root->getUserFolder($uid)->getById($id) as $node) {
            if ($node instanceof File && $this->access->isVisible($node) && $node->isReadable()) {return $node;}
        }
        return null;
    }
}
