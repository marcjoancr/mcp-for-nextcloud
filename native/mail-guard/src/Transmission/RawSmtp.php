<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Transmission;
use OCA\Mail\Account;
use OCA\Mail\SMTP\SmtpClientFactory;
use OCP\IConfig;
/** Exact pinned raw Horde path; mandatory TLS prevents its insecure-530 recursive resend branch. */
final class RawSmtp {
    public function __construct(private SmtpClientFactory $factory,private IConfig $config){}
    public function check(Account $account):void {
        if(!$this->config->getSystemValueBool('app.mail.mcp-transmission.enabled',false)||!$this->config->getSystemValueBool('app.mail.verify-tls-peer',true)||$this->config->getSystemValueBool('app.mail.debug',false)||$account->getMailAccount()->getDebug()||!in_array($account->getMailAccount()->getOutboundSslMode(),['tls','ssl'],true))throw new \RuntimeException('Approved transmission requires its enabled gate and verified private TLS transport.');
    }
    /** Caller MUST commit attempting before entering this method, including factory construction. */
    public function send(Account $account,array $artifact,\Closure $preWire):void {
        $this->check($account);$transport=$this->factory->create($account);
        if(!$transport instanceof \Horde_Mail_Transport_Smtphorde)throw new \RuntimeException('Unsupported SMTP transport.');
        $smtp=$transport->getSMTPObject();
        try{
            if(!$smtp->isSecureConnection())throw new \RuntimeException('Verified TLS is required.');
            $this->check($account);$preWire();
            if(!hash_equals($artifact['smtpHash'],hash('sha256',$artifact['smtp'])))throw new \RuntimeException('Frozen SMTP artifact changed.');
            if(!str_ends_with($artifact['smtp'],"\r\n")||preg_match('/[^\x00-\x7f]/',$artifact['smtp']))throw new \RuntimeException('Frozen SMTP framing is unsupported.');
            // Pinned Connection::write(resource) appends CRLF. Remove only that terminal framing
            // from its input so reconstructed DATA equals the complete frozen RFC822 artifact.
            $smtp->setParam('chunk_size',0);
            $accepted=$smtp->send($artifact['from'],$artifact['recipients'],substr($artifact['smtp'],0,-2));
            if(!is_array($accepted)||count($accepted)!==count($artifact['recipients']))throw new \RuntimeException('SMTP acceptance evidence unavailable.');
            foreach($artifact['recipients'] as $recipient)if(($accepted[$recipient]??null)!==true)throw new \RuntimeException('SMTP acceptance evidence incomplete.');
        }finally{try{$smtp->logout();}catch(\Throwable){}}
    }
}
