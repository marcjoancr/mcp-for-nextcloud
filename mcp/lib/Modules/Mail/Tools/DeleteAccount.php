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
use OCA\Mcp\Modules\Mail\Accounts\MailAccountRemoval;
final class DeleteAccount implements WriteTool {
    public function __construct(private MailAccountRemoval $service){}
    public static function definition():ToolDefinition {return new ToolDefinition('mail_delete_account','mail','delete_accounts',
        'Previews native removal of an owned account and aliases. Refuses provisioned accounts, local drafts/outgoing items and pending or uncertain account operations. Never deletes remote messages or Files sources. Confirm with the approved operation_id and plan_state.',
        Schema::object(['account_id'=>Schema::id()],['account_id'],true),app:'mail');}
    public function preview(array $arguments,ToolContext $context):array {return $this->service->preview($arguments,$context->userId);}
    public function execute(array $arguments,ToolContext $context):array {
        if(!WriteGate::confirmed($arguments))return ToolResult::json($this->preview($arguments,$context));
        return ToolResult::json($this->service->write($arguments,$context->userId));
    }
}
