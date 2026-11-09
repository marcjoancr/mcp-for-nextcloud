<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Tools;
use OCA\Mcp\Modules\Mail\Sync\MailRefresh;
use OCA\Mcp\Modules\Mail\Presentation\ConfigurationSchema as Schema;
use OCA\Mcp\Mcp\Tools\{WriteTool,ToolContext,ToolDefinition};
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Mcp\Confirmation\WriteGate;
final class RefreshMailbox implements WriteTool {
    public function __construct(private MailRefresh $refresh){}
    public static function definition():ToolDefinition {return new ToolDefinition('mail_refresh_mailbox','mail','refresh',
        'Preview and confirm an explicit bounded refresh of one owned cached Mail folder. Requires the qualified native v5 dependency and independent refresh permission. Maximum 200 cached and remote messages, 20 seconds, bounded read-only IMAP observation, atomic cache commit. allow_cache_reset defaults to false; true authorizes replacing an invalid cache with new message IDs and invalidating old cursors and plans. No automatic refresh from reads or status, classifiers, thread rebuilding or sending. After uncertainty inspect the durable operation and never repeat the write.',
        Schema::object(['account_id'=>Schema::id(),'mailbox_id'=>Schema::id(),'allow_cache_reset'=>['type'=>'boolean','default'=>false]],['account_id','mailbox_id'],true),app:'mail');}
    public function preview(array $a,ToolContext $c):array{return $this->refresh->preview($c->userId,$a);}
    public function execute(array $a,ToolContext $c):array{return ToolResult::json(WriteGate::confirmed($a)?$this->refresh->refresh($c->userId,$a):$this->preview($a,$c));}
}
