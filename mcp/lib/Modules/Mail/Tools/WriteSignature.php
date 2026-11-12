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
use OCA\Mcp\Modules\Mail\Signatures\MailSignatures;
final class WriteSignature implements WriteTool {
    public function __construct(private MailSignatures $service){}
    public static function definition():ToolDefinition {return new ToolDefinition('mail_write_signature','mail','write_signatures',
        'Previews and confirms a signature replacement; null explicitly removes it. The complete signature is shown as text. Authentication and unrelated settings remain unchanged.',
        Schema::object(['account_id'=>Schema::id(),'alias_id'=>Schema::id(),'signature'=>['type'=>['string','null'],'maxLength'=>20000]],['account_id','signature'],true),app:'mail');}
    public function preview(array $arguments,ToolContext $context):array {return $this->service->preview($arguments,$context->userId);}
    public function execute(array $arguments,ToolContext $context):array {
        if(!WriteGate::confirmed($arguments))return ToolResult::json($this->preview($arguments,$context));
        return ToolResult::json($this->service->write($arguments,$context->userId));
    }
}
