<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Tools;
use OCA\Mcp\Modules\Mail\Tags\MailMessageTags;
use OCA\Mcp\Modules\Mail\Presentation\ConfigurationSchema as Schema;
use OCA\Mcp\Mcp\Tools\{WriteTool,ToolContext,ToolDefinition};
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Mcp\Confirmation\WriteGate;
final class SetMessageTag implements WriteTool {
    public function __construct(private MailMessageTags $tags){}
    public static function definition():ToolDefinition {return new ToolDefinition('mail_set_message_tag','mail','tag_messages',
        'Preview and confirm assignment or removal of one owned native Mail tag on one exact owned message, using an explicit boolean. Requires the qualified v4 native dependency and fresh exact tag-cache provenance from native Mail synchronization. No automatic refresh, tag creation, system flag options or sending. Inspect the durable operation after an uncertain result; never repeat the write.',
        Schema::object(['account_id'=>Schema::id(),'message_id'=>Schema::id(),'tag_id'=>Schema::id(),'value'=>['type'=>'boolean']],['account_id','message_id','tag_id','value'],true),app:'mail');}
    public function preview(array $arguments,ToolContext $context):array {return $this->tags->preview($context->userId,$arguments);}
    public function execute(array $arguments,ToolContext $context):array {return ToolResult::json(WriteGate::confirmed($arguments)?$this->tags->set($context->userId,$arguments):$this->preview($arguments,$context));}
}
