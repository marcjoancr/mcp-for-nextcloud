<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Tools;
use OCA\Mcp\Modules\Mail\Operations\MailOperationReconciler;
use OCA\Mcp\Modules\Mail\Presentation\ConfigurationSchema as Schema;
use OCA\Mcp\Mcp\Tools\{WriteTool,ToolDefinition,ToolContext};
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Mcp\Confirmation\WriteGate;
final class ReconcileOperation implements WriteTool {
    public function __construct(private MailOperationReconciler $service){}
    public static function definition():ToolDefinition {return new ToolDefinition('mail_reconcile_operation','mail','reconcile_operations',
        'Preview and confirm a separate metadata reconciliation of one owned operation in this connection. Requires current original/source tool permissions and exact durable completion proof. Supports completed native phases, marked atomic local alias writes, completed setup binding and succeeded repairs explicitly referencing their source. Unproven claims remain protected; absence, age, names and human acknowledgment never authorize a retry. Does not connect to native mail servers, repeat effects, send, delete artifacts or force unlock.',
        Schema::object(['account_id'=>Schema::id(),'target_operation_id'=>['type'=>'string','minLength'=>1,'maxLength'=>128],'evidence_operation_id'=>['type'=>'string','minLength'=>1,'maxLength'=>128]],['account_id','target_operation_id'],true),app:'mail');}
    public function preview(array $args,ToolContext $context):array{return $this->service->preview($args,$context->userId);}
    public function execute(array $args,ToolContext $context):array{return ToolResult::json(WriteGate::confirmed($args)?$this->service->write($args,$context->userId):$this->preview($args,$context));}
}
