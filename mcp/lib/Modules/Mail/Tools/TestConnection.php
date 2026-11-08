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
use OCA\Mcp\Modules\Mail\Connection\MailConnection;
final class TestConnection implements WriteTool {
    public function __construct(private MailConnection $service){}
    public static function definition():ToolDefinition {return new ToolDefinition('mail_test_connection','mail','test_connection',
        'Explicitly previews and confirms native IMAP/SMTP authentication diagnostics independently. Hard budget: 10 seconds each, 20 total; five operations per account per minute. No SMTP envelope or message is submitted. Unsupported process/TLS policies are reported honestly.',
        Schema::object(['account_id'=>Schema::id(),'protocols'=>['type'=>'array','minItems'=>1,'maxItems'=>2,'items'=>['type'=>'string','enum'=>['imap','smtp']]]],['account_id','protocols'],true),app:'mail');}
    public function preview(array $arguments,ToolContext $context):array {return $this->service->preview($arguments,$context->userId);}
    public function execute(array $arguments,ToolContext $context):array {
        if(!WriteGate::confirmed($arguments))return ToolResult::json($this->preview($arguments,$context));
        return ToolResult::json($this->service->probe($arguments,$context->userId));
    }
}
