<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCA\Mcp\Modules\Mail\Integration\{MailNativeBoundary,MailSnoozeGateway};
use OCP\{Server,IConfig};
/** Test-only candidate qualification; exact source, runtime and provider remain mandatory. */
final class SnoozeFixtureBoundary extends MailNativeBoundary {
    public function snoozeVerified():bool {return $this->deliverySourceVerified()&&$this->runtimeMatches(Server::get(IConfig::class)->getSystemValueString('version',''));}
    public static function install():void {
        if(getenv('MCP_IT_MAIL_GUARD')!=='1'||getenv('MCP_IT_DELIVERY_DIRECTORY')!=='/tmp/mcp-mail-delivery-fixture')throw new \RuntimeException('Synthetic fixture identity required.');
        $boundary=new self(Server::get(\OCP\App\IAppManager::class));if(!$boundary->snoozeVerified())throw new \RuntimeException('Exact snooze candidate required.');
        (new \ReflectionProperty(MailSnoozeGateway::class,'boundary'))->setValue(Server::get(MailSnoozeGateway::class),$boundary);
    }
}
