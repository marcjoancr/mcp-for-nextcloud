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
use OCA\Mcp\Modules\Mail\Sieve\{MailSieveOperations,SieveSchema};
final class ReadSieveStatus implements Tool {
    public function __construct(private MailSieveOperations $service){}
    public static function definition():ToolDefinition {return SieveSchema::definition('read_sieve_status',false);}
    public function execute(array $arguments,ToolContext $context):array {return ToolResult::json($this->service->read($context->userId,'mail_read_sieve_status',$arguments));}
}
