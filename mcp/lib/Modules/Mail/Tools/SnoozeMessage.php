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
use OCA\Mcp\Modules\Mail\Snooze\MailSnoozeOperations;
final class SnoozeMessage implements WriteTool {
    public function __construct(private MailSnoozeOperations $delivery){}
    public static function definition():ToolDefinition {return new ToolDefinition('mail_snooze','mail','snooze','Approves moving one message to the configured Snooze folder and its exact future return. Requires original persistent OAuth connection and current snooze and wake grants.',[
        'type'=>'object','properties'=>['message_id'=>['type'=>'integer','minimum'=>1],'wake_at'=>['type'=>'string','maxLength'=>32,'description'=>'RFC3339 instant with explicit offset'],'timezone'=>['type'=>'string','maxLength'=>64,'description'=>'Matching IANA timezone'],'operation_id'=>OperationJournal::property(),PlanState::ARGUMENT=>PlanState::property()],
        'required'=>['message_id','wake_at','timezone'],'additionalProperties'=>false],app:'mail');}
    public function execute(array $arguments,ToolContext $context):array {return ToolResult::json(WriteGate::confirmed($arguments)?$this->delivery->schedule($context->userId,$arguments):$this->preview($arguments,$context));}
    public function preview(array $arguments,ToolContext $context):array {return $this->delivery->preview($context->userId,$arguments);}
}
