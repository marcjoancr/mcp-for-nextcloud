<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Tools;
use OCA\Mcp\Modules\Mail\Messages\MailMessageFlags;
use OCA\Mcp\Modules\Mail\Presentation\ConfigurationSchema as Schema;
use OCA\Mcp\Mcp\Tools\{WriteTool,ToolContext,ToolDefinition};
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Mcp\Confirmation\WriteGate;
final class SetMessageFlag implements WriteTool {
    public function __construct(private MailMessageFlags $flags){}
    public static function definition():ToolDefinition {return new ToolDefinition('mail_set_message_flag','mail','set_flags',
        'Preview and confirm seen or flagged as an explicit boolean on one owned message. Requires the verified native flag guard dependency. Inspect the durable operation after an uncertain result; never repeat the write. Does not send mail.',
        Schema::object(['account_id'=>Schema::id(),'message_id'=>Schema::id(),'flag'=>['type'=>'string','enum'=>['seen','flagged']],'value'=>['type'=>'boolean']],['account_id','message_id','flag','value'],true),app:'mail');}
    public function preview(array $arguments,ToolContext $context):array {return $this->flags->preview($context->userId,$arguments);}
    public function execute(array $arguments,ToolContext $context):array {return ToolResult::json(WriteGate::confirmed($arguments)?$this->flags->set($context->userId,$arguments):$this->preview($arguments,$context));}
}
