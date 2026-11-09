<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Tools;
use OCA\Mcp\Modules\Mail\Accounts\MailIdentityAdministration;
use OCA\Mcp\Modules\Mail\Presentation\ConfigurationSchema as Schema;
use OCA\Mcp\Mcp\Tools\{WriteTool,ToolDefinition,ToolContext};
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Mcp\Confirmation\WriteGate;
final class UpdateAlias implements WriteTool {
    public function __construct(private MailIdentityAdministration $service){}
    public static function definition():ToolDefinition {return new ToolDefinition('mail_update_alias','mail','edit_identities',
        'Preview and confirm this owned local Mail alias action. Only name and email fields are accepted; omitted signature, certificate and account fields are preserved. Provisioned identities and unresolved account operations refuse. Deletion also refuses local draft/outgoing references. Uses a qualified native mapper and atomic local journal; no credential entry, remote synchronization or sending. Native UI activity remains independent. Inspect the same operation ID after a lost response; never deduplicate creation by name or address.',
        Schema::object(['account_id'=>Schema::id(),'alias_id'=>Schema::id(),'changes'=>Schema::object(['name'=>['type'=>'string','maxLength'=>255],'email'=>['type'=>'string','minLength'=>1,'maxLength'=>254]],[])],['account_id','alias_id','changes'],true),app:'mail');}
    public function preview(array $args,ToolContext $context):array {return $this->service->preview($args,$context->userId,'update');}
    public function execute(array $args,ToolContext $context):array {return ToolResult::json(WriteGate::confirmed($args)?$this->service->write($args,$context->userId,'update'):$this->preview($args,$context));}
}
