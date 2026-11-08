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
use OCA\Mcp\Modules\Mail\Accounts\MailAccountSetup;
final class ConfigureConnection implements WriteTool {
    public function __construct(private MailAccountSetup $service){}
    public static function definition():ToolDefinition {return new ToolDefinition('mail_configure_connection','mail','configure_connection',
        'Previews desired non-secret connection changes. Confirmation records awaiting_user and returns native Mail settings navigation. Enter credentials only in Mail; complete_account_setup later verifies an explicitly selected observed result.',
        Schema::object(['account_id'=>Schema::id(),'intent'=>Schema::intent()],['account_id','intent'],true),app:'mail');}
    public function preview(array $arguments,ToolContext $context):array {return $this->service->preview($arguments,$context->userId);}
    public function execute(array $arguments,ToolContext $context):array {
        if(!WriteGate::confirmed($arguments))return ToolResult::json($this->preview($arguments,$context));
        return ToolResult::json($this->service->begin($arguments,$context->userId));
    }
}
