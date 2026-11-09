<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Tools;
use OCA\Mcp\Modules\Mail\Tags\MailTagCreation;
use OCA\Mcp\Modules\Mail\Presentation\ConfigurationSchema as Schema;
use OCA\Mcp\Mcp\Tools\{WriteTool,ToolDefinition,ToolContext};
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Mcp\Confirmation\WriteGate;
final class CreateTag implements WriteTool {
    public function __construct(private MailTagCreation $tags){}
    public static function definition():ToolDefinition {return new ToolDefinition('mail_create_tag','mail','create_tags',
        'Preview and confirm one new user-owned native Mail tag, shared across the user\'s accounts. Creates local tag metadata only; does not assign it to any message, contact IMAP/SMTP, sync or send. Requires the qualified native tag/index boundary. Name must encode completely without a collision or truncation; existing canonical labels are refused, never adopted as a successful creation. Review name, color and scope, then repeat with confirm=true, plan_state and operation_id. Inspect the same operation after a lost response; never create again under a different ID.',
        Schema::object(['name'=>['type'=>'string','minLength'=>1,'maxLength'=>128],'color'=>['type'=>'string','pattern'=>'^#[0-9a-fA-F]{6}$']],['name','color'],true),app:'mail');}
    public function preview(array $a,ToolContext $c):array{return $this->tags->preview($a,$c->userId);}
    public function execute(array $a,ToolContext $c):array{return ToolResult::json(WriteGate::confirmed($a)?$this->tags->write($a,$c->userId):$this->preview($a,$c));}
}
