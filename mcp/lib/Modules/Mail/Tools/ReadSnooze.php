<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Tools;
use OCA\Mcp\Mcp\Tools\{Tool,ToolContext,ToolDefinition};
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Modules\Mail\Snooze\MailSnoozeOperations;
final class ReadSnooze implements Tool {
    public function __construct(private MailSnoozeOperations $delivery){}
    public static function definition():ToolDefinition {return new ToolDefinition('mail_read_snooze','mail','read_snooze','Reads original and currently proven UID identities and independent native snooze phases. Requires original actor, connection, snooze and wake permissions. Never moves or retries.',[
        'type'=>'object','properties'=>['snooze_id'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}$']],'required'=>['snooze_id'],'additionalProperties'=>false],app:'mail');}
    public function execute(array $arguments,ToolContext $context):array {return ToolResult::json($this->delivery->read($context->userId,$arguments['snooze_id']));}
}
