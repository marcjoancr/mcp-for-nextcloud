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
final class CancelDelivery implements WriteTool {
    public function __construct(private MailDeliveryOperations $delivery){}
    public static function definition():ToolDefinition {return new ToolDefinition('mail_cancel_delivery','mail','cancel_delivery','Cancels an owned queued delivery before its attempt. Requires original connection and current source permission. Cannot recall mail.',[
        'type'=>'object','properties'=>['delivery_id'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}$'],'operation_id'=>OperationJournal::property(),PlanState::ARGUMENT=>PlanState::property()],
        'required'=>['delivery_id'],'additionalProperties'=>false],app:'mail');}
    public function execute(array $arguments,ToolContext $context):array {return ToolResult::json(WriteGate::confirmed($arguments)?$this->delivery->cancel($context->userId,$arguments):$this->preview($arguments,$context));}
    public function preview(array $arguments,ToolContext $context):array {return $this->delivery->previewCancel($context->userId,$arguments);}
}
