<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Integration;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Operations\{OperationJournal,LocalCommitUnknown};
/** Optional native services; feature-specific fences preserve independent grants at every boundary. */
class MailContentFeatures {
    public function __construct(private MailServices $services,private MailContentReader $reader,private OperationJournal $journal){}
    private function run(string $uid,string $tool,\Closure $work):array {
        if(!$this->reader->available($uid))throw new ToolFailure('The qualified native Mail content feature is unavailable.');
        $fence=$this->reader->featureFence($uid,$tool,fn(array $refs)=>$this->journal->checkpoint($refs));
        try{return $work($fence);}catch(\Throwable $error){if(get_class($error)===MailServices::CONTENT_COMMIT_UNKNOWN_CLASS)throw new LocalCommitUnknown('Native content commit outcome unavailable.',($error->connectionDiscarded??false)===true);throw new ToolFailure('Mail content changed, is unsupported, or is unavailable. Preview again before a new operation.');}
    }
    public function draftPreview(string $uid,int $message,int $target,?int $alias):array {return $this->run($uid,'mail_import_draft',fn($f)=>$this->services->guardedDraftImport($uid)->preview($uid,$message,$target,$alias,$f));}
    public function copy(string $uid,array $approved,string $key):array {return $this->run($uid,'mail_import_draft',fn($f)=>$this->services->guardedDraftImport($uid)->copy($uid,$approved,$key,$f));}
    public function copyOutcome(string $uid,string $key):array {return $this->run($uid,'mail_import_draft',fn($f)=>$this->services->guardedDraftImport($uid)->outcome($uid,$key,$f)??[]);}
    public function drafts(string $uid,array $a):array {return $this->run($uid,'mail_search_drafts',fn($f)=>$this->services->guardedDraftSearch($uid)->search($uid,(int)$a['account_id'],$a['subject_contains']??null,(int)($a['limit']??20),isset($a['before_id'])?(int)$a['before_id']:null,$f));}
    public function indexPreview(string $uid,array $ids):array {return $this->run($uid,'mail_index_messages',fn($f)=>$this->services->guardedBodyIndex($uid)->preview($uid,$ids,$f));}
    public function index(string $uid,array $approved):array {return $this->run($uid,'mail_index_messages',fn($f)=>$this->services->guardedBodyIndex($uid)->index($uid,$approved,$f));}
    public function search(string $uid,array $a):array {return $this->run($uid,'mail_search_indexed_body',fn($f)=>$this->services->guardedBodyIndex($uid)->search($uid,$a['mailbox_ids'],$a['query'],(int)($a['limit']??20),isset($a['before_id'])?(int)$a['before_id']:null,$f));}
}
