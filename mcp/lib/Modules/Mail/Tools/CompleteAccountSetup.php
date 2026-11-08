<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Tools;
use OCA\Mcp\Mcp\Tools\{Tool,WriteTool,ToolContext,ToolDefinition};
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Mcp\Confirmation\WriteGate;
use OCA\Mcp\Modules\Mail\Presentation\ConfigurationSchema as Schema;
use OCA\Mcp\Modules\Mail\Accounts\MailAccountSetup;
final class CompleteAccountSetup implements WriteTool {
    public function __construct(private MailAccountSetup $service){}
    public static function definition():ToolDefinition {return new ToolDefinition('mail_complete_account_setup','mail','complete_setup',
        'Previews and confirms an explicitly selected owned native account result for a pending setup operation in this connection. Repeat the original non-secret intent. Requires current original-action and connection-read access; never creates accounts or probes.',
        Schema::object(['setup_operation_id'=>['type'=>'string','minLength'=>1,'maxLength'=>128],'account_id'=>Schema::id(),'intent'=>Schema::intent()],['setup_operation_id','account_id','intent'],true),app:'mail');}
    public function preview(array $arguments,ToolContext $context):array {return $this->service->previewCompletion($arguments,$context->userId);}
    public function execute(array $arguments,ToolContext $context):array {
        if(!WriteGate::confirmed($arguments))return ToolResult::json($this->preview($arguments,$context));
        return ToolResult::json($this->service->complete($arguments,$context->userId));
    }
}
