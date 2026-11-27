<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail\Integration;

use OCA\Mcp\Modules\Mail\Contracts\MailOwnedDraftSender;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IDBConnection;

final class OfficialMailSender implements MailOwnedDraftSender {
	public function __construct(
		private IConfig $config,
		private IAppManager $apps,
		private IDBConnection $db,
		private MailServices $mail,
	) {}

	public function available(): bool {
		try {
			if (!$this->config->getSystemValueBool('app.mcp.official-apps.mail')
				|| !$this->config->getSystemValueBool('app.mail.mcp-transmission.enabled')
				|| $this->config->getSystemValueBool('app.mail.debug')
				|| $this->config->getSystemValueString('version') !== '35.0.2.2'
				|| $this->db->getDatabaseProvider(true) !== IDBConnection::PLATFORM_MARIADB
				|| $this->config->getSystemValue('dbreplica', []) !== []
				|| $this->config->getSystemValue('dbsharding', []) !== []
				|| $this->apps->getAppVersion('mail') !== '5.12.5') {
				return false;
			}
			$root = $this->apps->getAppPath('mail');
			foreach (OfficialMailManifest::FILES as $path => $digest) {
				$file = $root . '/' . $path;
				if (!is_file($file) || !is_readable($file) || hash_file('sha256', $file) !== $digest) {
					return false;
				}
			}
			return true;
		} catch (\Throwable) {
			return false;
		}
	}

	public function accountIdentity(string $uid, object $account): array {
		try {
			$source = $account->getMailAccount();
			$accountId = (int)$account->getId();
			$sentMailboxId = $source->getSentMailboxId();
			if ($account->getUserId() !== $uid || $source->getProtocol() !== 'imap' || $source->getDebug()
				|| !$source->getOutboundHost() || !$source->getOutboundPort()
				|| !is_int($sentMailboxId) || $sentMailboxId < 1
				|| (int)$this->mail->mailboxMapper($uid)->findById($sentMailboxId)->getAccountId() !== $accountId) {
				throw new \RuntimeException();
			}
			return [
				'name' => (string)$account->getName(),
				'email' => (string)$account->getEmail(),
				'sent_mailbox_id' => $sentMailboxId,
				'auth' => $source->getAuthMethod(),
				'smtp_host' => $source->getOutboundHost(),
				'smtp_port' => $source->getOutboundPort(),
				'smtp_security' => $source->getOutboundSslMode(),
				'smtp_user' => $source->getOutboundUser(),
				'imap_host' => $source->getInboundHost(),
				'imap_port' => $source->getInboundPort(),
				'imap_security' => $source->getInboundSslMode(),
				'imap_user' => $source->getInboundUser(),
			];
		} catch (\Throwable) {
			throw new ToolFailure('Sending requires an owned IMAP and SMTP account with Sent configured and debugging off.');
		}
	}

	public function send(string $uid, object $account, array $draft, \Closure $authorize): string {
		[$sentMailbox, $antiAbuse, $send, $copy] = $this->mail->officialSendHandlers($uid);
		[$message, $recipientClass] = $this->mail->officialSendMessage($uid, $draft['account_id']);
		$recipients = [];
		foreach (['to' => $recipientClass::TYPE_TO, 'cc' => $recipientClass::TYPE_CC, 'bcc' => $recipientClass::TYPE_BCC] as $field => $type) {
			foreach ($draft[$field] as $entry) {
				$recipient = new $recipientClass();
				$recipient->setType($type);
				$recipient->setEmail($entry['email']);
				$recipient->setLabel($entry['label']);
				$recipients[] = $recipient;
			}
		}
		$message->setRecipients($recipients);
		$message->setSubject($draft['subject']);
		$message->setBodyPlain($draft['body_plain']);
		$message->setHtml(false);
		$message->setPgpMime(false);
		$message->setSmimeSign(false);
		$message->setSmimeEncrypt(false);
		$message->setRequestMdn(false);
		$message->setAiGenerated(true);
		$message->setAttachments([]);

		// These are the native chain stages. Keep the message in memory so no native outbox worker can deliver it.
		$client = $this->mail->clientFactory($uid)->getClient($account);
		try {
			$sentMailbox->process($account, $message, $client);
			$antiAbuse->process($account, $message, $client);
			if ($message->getStatus() !== null) {
				return 'uncertain';
			}
			$authorize();
			$send->process($account, $message, $client);
			if ($message->getStatus() !== $message::STATUS_RAW || !is_string($message->getRaw())) {
				return 'uncertain';
			}
			try {
				$copy->process($account, $message, $client);
				return $message->getStatus() === $message::STATUS_PROCESSED ? 'accepted' : 'accepted_copy_unconfirmed';
			} catch (\Throwable) {
				return 'accepted_copy_unconfirmed';
			}
		} finally {
			try {
				$client->logout();
			} catch (\Throwable) {
				// Closing the connection does not change a confirmed SMTP result.
			}
		}
	}
}
