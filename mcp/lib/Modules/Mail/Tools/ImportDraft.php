<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Tools;
use OCA\Mcp\Mcp\Tools\{Tool,WriteTool,ToolDefinition,ToolContext};
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Mcp\Confirmation\{PlanState,WriteGate};
use OCA\Mcp\Mcp\Operations\OperationJournal;
final class ImportDraft implements WriteTool {
    public function __construct(private \OCA\Mcp\Modules\Mail\Content\MailDraftImport $service){}
    public static function definition():ToolDefinition {return new ToolDefinition('mail_import_draft','mail','import_draft','Copies one supported IMAP draft into a new protected local draft, retaining the original. Preview exact recipients, sender replacement, body sizes, attachment hashes and source revision; obtain approval and repeat with confirm, plan_state and operation_id. Unsupported crypto, reference chains or MIME metadata refuse before staging. Never sends mail.',['type'=>'object','properties'=>['message_id'=>['type'=>'integer','minimum'=>1],'target_account_id'=>['type'=>'integer','minimum'=>1],'target_alias_id'=>['type'=>'integer','minimum'=>1],'operation_id'=>OperationJournal::property(),PlanState::ARGUMENT=>PlanState::property()],'required'=>['message_id','target_account_id'],'additionalProperties'=>false],app:'mail');}
    public function execute(array $arguments,ToolContext $context):array {if(!WriteGate::confirmed($arguments))return ToolResult::json($this->preview($arguments,$context));return ToolResult::json($this->service->copy($arguments,$context->userId));}
    public function preview(array $arguments,ToolContext $context):array {return $this->service->preview($arguments,$context->userId);}
}
