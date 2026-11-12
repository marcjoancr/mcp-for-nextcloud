<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCA\Mcp\Modules\Mail\Integration\{MailNativeBoundary,MailSignatureModeGateway};
use OCP\{Server,IConfig};
/** Diagnostic process only. Shipping eligibility remains false. */
final class SignatureModeFixtureBoundary extends MailNativeBoundary {
    public function signatureModeVerified():bool{return $this->deliverySourceVerified()&&$this->runtimeMatches(Server::get(IConfig::class)->getSystemValueString('version',''));}
    public static function install():void {
        if(getenv('MCP_IT_MAIL_GUARD')!=='1')throw new \RuntimeException('Synthetic fixture required.');$b=new self(Server::get(\OCP\App\IAppManager::class));if(!$b->signatureModeVerified())throw new \RuntimeException('Exact signature source required.');(new \ReflectionProperty(MailSignatureModeGateway::class,'boundary'))->setValue(Server::get(MailSignatureModeGateway::class),$b);
    }
}
