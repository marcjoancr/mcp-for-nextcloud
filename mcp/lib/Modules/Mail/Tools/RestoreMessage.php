<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Tools;
use OCA\Mcp\Modules\Mail\Messages\MailMessageMoves;
use OCA\Mcp\Modules\Mail\Presentation\ConfigurationSchema as Schema;
use OCA\Mcp\Mcp\Tools\{WriteTool,ToolContext,ToolDefinition};
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Mcp\Confirmation\WriteGate;
final class RestoreMessage implements WriteTool {
    public function __construct(private MailMessageMoves $moves){}
    public static function definition():ToolDefinition {return new ToolDefinition('mail_restore_message','mail','restore_messages',
        'Preview and confirm restore of one owned message using native UID MOVE and exact COPYUID. Same account only; requires the qualified native v2 dependency. Inspect operation status after uncertainty and never repeat. Sending stays disabled.',
        Schema::object(['account_id'=>Schema::id(),'message_id'=>Schema::id(),'destination_mailbox_id'=>Schema::id()],['account_id','message_id','destination_mailbox_id'],true),app:'mail');}
    public function preview(array $arguments,ToolContext $context):array {return $this->moves->preview($context->userId,$arguments,'restore');}
    public function execute(array $arguments,ToolContext $context):array {return ToolResult::json(WriteGate::confirmed($arguments)?$this->moves->apply($context->userId,$arguments,'restore'):$this->preview($arguments,$context));}
}
