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
use OCA\Mcp\Modules\Mail\Settings\MailSettings;
final class WriteAccountSettings implements WriteTool {
    public function __construct(private MailSettings $service){}
    public static function definition():ToolDefinition {return new ToolDefinition('mail_write_account_settings','mail','write_settings',
        'Previews and confirms an allowlisted preference patch. Omission preserves fields and authentication. Reuse the approved operation_id after a lost response.',
        Schema::object(['account_id'=>Schema::id(),'changes'=>['type'=>'object','properties'=>['editor_mode'=>['type'=>'string','enum'=>['plaintext','html']],'signature_above_quote'=>['type'=>'boolean'],'show_subscribed_only'=>['type'=>'boolean']],'additionalProperties'=>false]],['account_id','changes'],true),app:'mail');}
    public function preview(array $arguments,ToolContext $context):array {return $this->service->preview($arguments,$context->userId);}
    public function execute(array $arguments,ToolContext $context):array {
        if(!WriteGate::confirmed($arguments))return ToolResult::json($this->preview($arguments,$context));
        return ToolResult::json($this->service->write($arguments,$context->userId));
    }
}
