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
final class AddAccount implements WriteTool {
    public function __construct(private MailAccountSetup $service){}
    public static function definition():ToolDefinition {return new ToolDefinition('mail_add_account','mail','create_accounts',
        'Previews account setup intent and, after confirmation, returns the native Mail assistant. This tool never accepts credentials or creates a native account itself. Opening the assistant is not completion.',
        Schema::object(['intent'=>Schema::intent(true)],['intent'],true),app:'mail');}
    public function preview(array $arguments,ToolContext $context):array {return $this->service->preview($arguments,$context->userId);}
    public function execute(array $arguments,ToolContext $context):array {
        if(!WriteGate::confirmed($arguments))return ToolResult::json($this->preview($arguments,$context));
        return ToolResult::json($this->service->begin($arguments,$context->userId));
    }
}
