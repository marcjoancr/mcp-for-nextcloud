<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Tools;
use OCA\Mcp\Modules\Mail\Mailboxes\MailboxMutations;
use OCA\Mcp\Modules\Mail\Presentation\ConfigurationSchema as Schema;
use OCA\Mcp\Mcp\Tools\{WriteTool,ToolContext,ToolDefinition};
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Mcp\Confirmation\WriteGate;
final class RenameMailbox implements WriteTool {
    public function __construct(private MailboxMutations $folders){}
    public static function definition():ToolDefinition {return new ToolDefinition('mail_rename_mailbox','mail','rename_mailboxes',
        'Preview and confirm one owned native Mail folder action. Requires qualified v3 native dependency. Names are literal leaf names; rename keeps the parent and is limited to 100 descendants. Existing roles and messages are preserved. Inspect operation status after uncertainty; never repeat a write under a new operation ID. Does not send mail.',
        Schema::object(['account_id'=>Schema::id(),'mailbox_id'=>Schema::id(),'name'=>['type'=>'string','minLength'=>1,'maxLength'=>255]],['account_id','mailbox_id','name'],true),app:'mail');}
    public function preview(array $a,ToolContext $c):array {return $this->folders->preview($a,$c->userId,'rename_mailbox');}
    public function execute(array $a,ToolContext $c):array {return ToolResult::json(WriteGate::confirmed($a)?$this->folders->write($a,$c->userId,'rename_mailbox'):$this->preview($a,$c));}
}
