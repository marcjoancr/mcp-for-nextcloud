<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Tools;
use OCA\Mcp\Modules\Mail\Mailboxes\MailboxRoles;
use OCA\Mcp\Modules\Mail\Presentation\ConfigurationSchema as Schema;
use OCA\Mcp\Mcp\Tools\{WriteTool,ToolContext,ToolDefinition};
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Mcp\Confirmation\WriteGate;
final class ConfigureMailboxes implements WriteTool {
    public function __construct(private MailboxRoles $roles){}
    public static function definition():ToolDefinition {return new ToolDefinition('mail_configure_mailboxes','mail','configure_mailboxes',
        'Preview and confirm explicit Drafts, Sent, Trash or Archive folder roles for one owned Mail account. Supply existing selectable personal folder IDs in that account. Omitted roles and all authentication/security settings are preserved; clearing roles is unsupported. Does not sync folders or send mail.',
        Schema::object(['account_id'=>Schema::id(),'roles'=>['type'=>'object','properties'=>['drafts_mailbox_id'=>Schema::id(),'sent_mailbox_id'=>Schema::id(),'trash_mailbox_id'=>Schema::id(),'archive_mailbox_id'=>Schema::id()],'additionalProperties'=>false]],['account_id','roles'],true),app:'mail');}
    public function preview(array $a,ToolContext $c):array {return $this->roles->preview($a,$c->userId);}
    public function execute(array $a,ToolContext $c):array {return ToolResult::json(WriteGate::confirmed($a)?$this->roles->write($a,$c->userId):$this->preview($a,$c));}
}
