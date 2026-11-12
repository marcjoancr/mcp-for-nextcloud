<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Tools;
use OCA\Mcp\Mcp\Tools\{WriteTool,ToolContext,ToolDefinition};
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Mcp\Confirmation\{PlanState,WriteGate};
use OCA\Mcp\Mcp\Operations\OperationJournal;
use OCA\Mcp\Modules\Mail\Transmission\MailDeliveryOperations;
final class ScheduleDraft implements WriteTool {
    public function __construct(private MailDeliveryOperations $delivery){}
    public static function definition():ToolDefinition {return new ToolDefinition('mail_schedule','mail','schedule','Approves one immutable local draft for an explicit RFC3339 instant and matching IANA timezone. Live original authorization is required when due.',[
        'type'=>'object','properties'=>['draft_id'=>['type'=>'integer','minimum'=>1],'send_at'=>['type'=>'string','maxLength'=>32,'description'=>'RFC3339 with explicit offset'],'timezone'=>['type'=>'string','maxLength'=>100,'description'=>'Matching IANA timezone'],'operation_id'=>OperationJournal::property(),PlanState::ARGUMENT=>PlanState::property()],
        'required'=>['draft_id','send_at','timezone'],'additionalProperties'=>false],app:'mail');}
    public function execute(array $arguments,ToolContext $context):array {return ToolResult::json(WriteGate::confirmed($arguments)?$this->delivery->schedule($context->userId,$arguments):$this->preview($arguments,$context));}
    public function preview(array $arguments,ToolContext $context):array {return $this->delivery->previewSchedule($context->userId,$arguments);}
}
