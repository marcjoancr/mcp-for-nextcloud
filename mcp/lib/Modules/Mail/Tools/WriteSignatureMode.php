<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Tools;
use OCA\Mcp\Modules\Mail\Signatures\MailSignatureMode;
use OCA\Mcp\Modules\Mail\Presentation\ConfigurationSchema as Schema;
use OCA\Mcp\Mcp\Tools\{WriteTool,ToolDefinition,ToolContext};
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Mcp\Confirmation\WriteGate;
final class WriteSignatureMode implements WriteTool {
    public function __construct(private MailSignatureMode $service){}
    public static function definition():ToolDefinition {return new ToolDefinition('mail_write_signature_mode','mail','write_signature_mode',
        'Preview and confirm the signature mode stored by native Mail: 0 plain text or 1 HTML, for this owned account or its exact owned alias. Preserves signature bytes, editor mode, identity, credentials and certificates. Current Mail UI and Composer infer presentation from content and editor mode; this stored mode does not promise a rendering or format conversion. Requires whole-account scope and an independent grant. Provisioned identities refuse. Review current mode and revision, then use the exact plan_state and operation_id. Inspect the same operation ID after a lost response; replay never writes again. No network or transmission.',
        Schema::object(['account_id'=>Schema::id(),'alias_id'=>Schema::id(),'signature_mode'=>['type'=>'integer','enum'=>[0,1]]],['account_id','signature_mode'],true),app:'mail');}
    public function preview(array $args,ToolContext $context):array{return $this->service->preview($args,$context->userId);}
    public function execute(array $args,ToolContext $context):array{return ToolResult::json(WriteGate::confirmed($args)?$this->service->write($args,$context->userId):$this->preview($args,$context));}
}
