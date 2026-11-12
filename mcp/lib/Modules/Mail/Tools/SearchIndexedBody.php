<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Tools;
use OCA\Mcp\Mcp\Tools\{Tool,WriteTool,ToolDefinition,ToolContext};
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Mcp\Confirmation\{PlanState,WriteGate};
use OCA\Mcp\Mcp\Operations\OperationJournal;
final class SearchIndexedBody implements Tool {
    public function __construct(private \OCA\Mcp\Modules\Mail\Integration\MailContentFeatures $service){}
    public static function definition():ToolDefinition {return new ToolDefinition('mail_search_indexed_body','mail','search_indexed_body','Searches explicitly indexed current messages in up to twenty owned mailbox IDs. Uses PostgreSQL simple lexical all-term matching, not substring or provider BODY search. Returns at most fifty metadata rows with keyset pagination and explicit coverage; unindexed, stale and refused messages are not searched.',['type'=>'object','properties'=>['mailbox_ids'=>['type'=>'array','minItems'=>1,'maxItems'=>20,'uniqueItems'=>true,'items'=>['type'=>'integer','minimum'=>1]],'query'=>['type'=>'string','minLength'=>1,'maxLength'=>256],'limit'=>['type'=>'integer','minimum'=>1,'maximum'=>50,'default'=>20],'before_id'=>['type'=>'integer','minimum'=>1]],'required'=>['mailbox_ids','query'],'additionalProperties'=>false],app:'mail');}
    public function execute(array $arguments,ToolContext $context):array {return ToolResult::json($this->service->search($context->userId,$arguments));}
}
