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
final class EnableAbsence implements WriteTool {
    public function __construct(private MailSieveOperations $service){}
    public static function definition():ToolDefinition {return SieveSchema::definition('enable_absence',true);}
    public function preview(array $arguments,ToolContext $context):array {return $this->service->preview($context->userId,'mail_enable_absence',$arguments);}
    public function execute(array $arguments,ToolContext $context):array {return ToolResult::json(WriteGate::confirmed($arguments)?$this->service->apply($context->userId,'mail_enable_absence',$arguments):$this->preview($arguments,$context));}
}
