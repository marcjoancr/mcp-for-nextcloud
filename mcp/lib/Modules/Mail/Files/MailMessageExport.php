<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Files;
use OCA\Mcp\Modules\Mail\Integration\MailContentReader;
use OCA\Mcp\Modules\Mail\Presentation\MailMessages;
use OCA\Mcp\Modules\Files\Contracts\IncomingFileWriter;
use OCA\Mcp\Mcp\Confirmation\{PlanState,PlanChanged};
use OCA\Mcp\Mcp\Operations\OperationJournal;
/** Opaque EML preservation through the normal Files policy and durable no-replay operation journal. */
final class MailMessageExport {
    public const TOOL='mail_export_message';
    public function __construct(private MailContentReader $reader,private IncomingFileWriter $files,private PlanState $states,private ?OperationJournal $journal=null){}
    public function preview(array $arguments,string $uid):array {return $this->plan($arguments,$uid,$this->reader->readRaw($uid,(int)$arguments['message_id']));}
    public function save(array $arguments,string $uid):array {
        PlanState::require($arguments);$raw=$this->reader->readRaw($uid,(int)$arguments['message_id']);$plan=$this->plan($arguments,$uid,$raw);
        if(!PlanState::matches($plan[PlanState::ARGUMENT],$arguments))throw new PlanChanged($plan);
        $this->reader->assertSource($uid,$plan['source'],self::TOOL);
        $this->journal?->checkpoint(['source_tool'=>self::TOOL,'content_source'=>$plan['source'],'content_sha256'=>$raw['sha256'],'destination'=>$plan['destination']['path']]);
        $file=$this->files->save($uid,$plan['destination']['path'],$raw['bytes'],(bool)($arguments['confirm_shared']??false));
        if(($file['requiresConfirmation']??false)===true)return $file;
        if(isset($file['fileId']))$this->journal?->checkpoint(['file_id'=>(int)$file['fileId']]);
        return ['source'=>$plan['source'],'content_sha256'=>$raw['sha256'],'file'=>$file];
    }
    private function plan(array $arguments,string $uid,array $raw):array {
        $source=MailContentReader::stableSource($raw['source']);$destination=$this->files->preview($uid,(string)$arguments['path'],$raw['byte_count']);
        $shown=['source'=>$source,'content_sha256'=>$raw['sha256'],'byte_count'=>$raw['byte_count'],'destination'=>$destination];
        return ['action'=>'export_message']+$shown+[PlanState::ARGUMENT=>$this->states->of(self::TOOL,$uid,$shown),'message'=>MailMessages::exportPlan()];
    }
}
