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
final class WakeMessage implements WriteTool {
    public function __construct(private MailSnoozeOperations $delivery){}
    public static function definition():ToolDefinition {return new ToolDefinition('mail_wake','mail','wake','Approves returning one guarded snoozed message now to its exact original folder. No fallback destination or retry after uncertain MOVE.',[
        'type'=>'object','properties'=>['snooze_id'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}$'],'operation_id'=>OperationJournal::property(),PlanState::ARGUMENT=>PlanState::property()],
        'required'=>['snooze_id'],'additionalProperties'=>false],app:'mail');}
    public function execute(array $arguments,ToolContext $context):array {return ToolResult::json(WriteGate::confirmed($arguments)?$this->delivery->wake($context->userId,$arguments):$this->preview($arguments,$context));}
    public function preview(array $arguments,ToolContext $context):array {return $this->delivery->previewWake($context->userId,$arguments);}
}
