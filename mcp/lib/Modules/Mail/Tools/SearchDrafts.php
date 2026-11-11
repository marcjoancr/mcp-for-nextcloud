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
final class SearchDrafts implements Tool {
    public function __construct(private \OCA\Mcp\Modules\Mail\Integration\MailContentFeatures $service){}
    public static function definition():ToolDefinition {return new ToolDefinition('mail_search_drafts','mail','search_drafts','Lists bounded cached drafts only in the currently configured owned Drafts folder. Requires a separate search_drafts grant. Cached discovery is not live Draft proof; import checks the live source. Deleted messages are excluded.',['type'=>'object','properties'=>['account_id'=>['type'=>'integer','minimum'=>1],'subject_contains'=>['type'=>'string','maxLength'=>256],'limit'=>['type'=>'integer','minimum'=>1,'maximum'=>50,'default'=>20],'before_id'=>['type'=>'integer','minimum'=>1]],'required'=>['account_id'],'additionalProperties'=>false],app:'mail');}
    public function execute(array $arguments,ToolContext $context):array {return ToolResult::json($this->service->drafts($context->userId,$arguments));}
}
