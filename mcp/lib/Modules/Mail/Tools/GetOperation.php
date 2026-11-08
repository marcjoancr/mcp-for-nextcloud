<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Tools;
use OCA\Mcp\Mcp\Tools\{Tool,ToolContext,ToolDefinition};
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Modules\Mail\Operations\MailOperationReader;
final class GetOperation implements Tool {
    public function __construct(private MailOperationReader $reader) {}
    public static function definition(): ToolDefinition {
        return new ToolDefinition('mail_get_operation','mail','read_operations',
            'Reads the persisted outcome of one Mail write for this user and connection. Never retries or sends. Resource references are minimal; read the draft separately if permitted.',
            ['type'=>'object','properties'=>['operation_id'=>['type'=>'string','minLength'=>1,'maxLength'=>128]],'required'=>['operation_id'],'additionalProperties'=>false],app:'mail');
    }
    public function execute(array $arguments,ToolContext $context): array {
        return ToolResult::json($this->reader->get($context->userId,$arguments['operation_id']));
    }
}
