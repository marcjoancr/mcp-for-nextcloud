<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Tools;
use OCA\Mcp\Mcp\Tools\{Tool,ToolContext,ToolDefinition};
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Modules\Mail\Transmission\MailDeliveryOperations;
final class ReadDelivery implements Tool {
    public function __construct(private MailDeliveryOperations $delivery){}
    public static function definition():ToolDefinition {return new ToolDefinition('mail_read_delivery','mail','read_delivery','Reads sanitized durable delivery and Sent-copy evidence. Requires the original OAuth connection and current source selection. Never dispatches, retries, repairs or searches the mailbox.',[
        'type'=>'object','properties'=>['delivery_id'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}$']],'required'=>['delivery_id'],'additionalProperties'=>false],app:'mail');}
    public function execute(array $arguments,ToolContext $context):array {return ToolResult::json($this->delivery->read($context->userId,$arguments['delivery_id']));}
}
