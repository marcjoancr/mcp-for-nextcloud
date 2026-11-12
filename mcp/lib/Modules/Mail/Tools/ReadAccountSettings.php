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
use OCA\Mcp\Modules\Mail\Integration\MailSettingsGateway;
final class ReadAccountSettings implements Tool {
    public function __construct(private MailSettingsGateway $service){}
    public static function definition():ToolDefinition {return new ToolDefinition('mail_read_account_settings','mail','read_settings',
        'Reads allowlisted preferences and an opaque revision of an owned account. Never returns credentials or contacts mail servers.',
        Schema::object(['account_id'=>Schema::id()],['account_id'],false),app:'mail');}
    public function execute(array $arguments,ToolContext $context):array {
        return ToolResult::json($this->service->readSettings($context->userId,(int)$arguments['account_id']));
    }
}
