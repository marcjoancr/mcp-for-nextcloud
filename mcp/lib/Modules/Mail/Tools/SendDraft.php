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
final class SendDraft implements WriteTool {
    public function __construct(private MailDeliveryOperations $delivery){}
    public static function definition():ToolDefinition {return new ToolDefinition('mail_send','mail','send','Approves one immutable local draft for one immediate SMTP attempt. Requires persistent OAuth source selection. Acceptance is not delivery; never repeat uncertain attempts.',[
        'type'=>'object','properties'=>['draft_id'=>['type'=>'integer','minimum'=>1],'operation_id'=>OperationJournal::property(),PlanState::ARGUMENT=>PlanState::property()],
        'required'=>['draft_id'],'additionalProperties'=>false],app:'mail');}
    public function execute(array $arguments,ToolContext $context):array {return ToolResult::json(WriteGate::confirmed($arguments)?$this->delivery->send($context->userId,$arguments):$this->preview($arguments,$context));}
    public function preview(array $arguments,ToolContext $context):array {return $this->delivery->previewSend($context->userId,$arguments);}
}
