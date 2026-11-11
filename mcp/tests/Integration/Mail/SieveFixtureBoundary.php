<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCA\Mcp\Modules\Mail\Integration\{MailNativeBoundary,MailSieveGateway};
use OCP\{Server,IConfig};
/** Explicit test-only qualification. Exact installed current-v11 source/runtime still required. */
final class SieveFixtureBoundary extends MailNativeBoundary {
    public function sieveVerified():bool{return $this->deliverySourceVerified()&&$this->runtimeMatches(Server::get(IConfig::class)->getSystemValueString('version',''));}
    public static function install():void {
        if(getenv('MCP_IT_MAIL_GUARD')!=='1'||getenv('MCP_IT_DELIVERY_DIRECTORY')!=='/tmp/mcp-mail-delivery-fixture')throw new \RuntimeException('Synthetic fixture required.');$b=new self(Server::get(\OCP\App\IAppManager::class));if(!$b->sieveVerified())throw new \RuntimeException('Exact Sieve source required.');(new \ReflectionProperty(MailSieveGateway::class,'boundary'))->setValue(Server::get(MailSieveGateway::class),$b);
    }
}
