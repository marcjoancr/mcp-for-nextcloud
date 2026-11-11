<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCA\Mcp\Modules\Mail\Integration\{MailNativeBoundary,MailDeliveryGateway};
use OCP\{Server,IConfig};
/** Test-only candidate admission. No configurable or shipped bypass exists. */
final class DeliveryFixtureBoundary extends MailNativeBoundary {
    public function deliveryVerified():bool {return $this->deliverySourceVerified()&&$this->runtimeMatches(Server::get(IConfig::class)->getSystemValueString('version',''));}
    public static function install():void {
        if(getenv('MCP_IT_MAIL_GUARD')!=='1'||getenv('MCP_IT_DELIVERY_DIRECTORY')!=='/tmp/mcp-mail-delivery-fixture')throw new \RuntimeException('Synthetic fixture identity required.');
        $boundary=new self(Server::get(\OCP\App\IAppManager::class));
        if(!$boundary->deliveryVerified())throw new \RuntimeException('Exact candidate source and runtime required.');
        $property=new \ReflectionProperty(MailDeliveryGateway::class,'boundary');$property->setValue(Server::get(MailDeliveryGateway::class),$boundary);
    }
}
