<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Tools;
use OCA\Mcp\Mcp\Tools\{Tool,WriteTool,ToolDefinition,ToolContext};
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Mcp\Confirmation\{PlanState,WriteGate};
use OCA\Mcp\Mcp\Operations\OperationJournal;
final class IndexMessages implements WriteTool {
    public function __construct(private \OCA\Mcp\Modules\Mail\Content\MailBodyIndex $service){}
    public static function definition():ToolDefinition {return new ToolDefinition('mail_index_messages','mail','index_messages','Explicitly indexes up to twenty selected messages after approval. Searches only complete supported body text up to 20000 characters and 64 KiB; attachments are excluded. Truncation, unsupported content and PostgreSQL lexeme limits produce explicit refusal coverage without searchable prefixes. Requires separate index_messages permission; never crawls a mailbox.',['type'=>'object','properties'=>['message_ids'=>['type'=>'array','minItems'=>1,'maxItems'=>20,'uniqueItems'=>true,'items'=>['type'=>'integer','minimum'=>1]],'operation_id'=>OperationJournal::property(),PlanState::ARGUMENT=>PlanState::property()],'required'=>['message_ids'],'additionalProperties'=>false],app:'mail');}
    public function execute(array $arguments,ToolContext $context):array {if(!WriteGate::confirmed($arguments))return ToolResult::json($this->preview($arguments,$context));return ToolResult::json($this->service->index($arguments,$context->userId));}
    public function preview(array $arguments,ToolContext $context):array {return $this->service->preview($arguments,$context->userId);}
}
