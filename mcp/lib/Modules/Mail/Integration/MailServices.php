<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail\Integration;

use OCA\Mcp\Access\AppAvailability\AppEnablement;
use OCP\App\IAppManager;
use OCP\IUserManager;


/**
 * Single place that knows the optional Nextcloud Mail classes by name, so the module compiles and the suite passes
 * with the Mail app absent. Optional Mail services are resolved by fixed class names only after a per-user enablement
 * check. Reads use the Mail cache and its IMAP client; draft writes call only the local unsent-draft APIs and never
 * the send or outbox paths.
 */
class MailServices {
	/** App id of Nextcloud Mail. */
	public const APP_ID = 'mail';

	/** OCA\Mail\Service\AccountService: the accounts of a user, as the Mail app stores them. */
	private const ACCOUNT_SERVICE_CLASS = 'OCA\\Mail\\Service\\AccountService';

	/** OCA\Mail\Db\MailboxMapper: the folder cache of the Mail app. */
	private const MAILBOX_MAPPER_CLASS = 'OCA\\Mail\\Db\\MailboxMapper';
	private const MESSAGE_MAPPER_CLASS = 'OCA\\Mail\\Db\\MessageMapper';
	private const MAIL_MANAGER_CLASS = 'OCA\\Mail\\Service\\MailManager';
	private const CLIENT_FACTORY_CLASS = 'OCA\\Mail\\IMAP\\IMAPClientFactory';
	private const SEARCH_QUERY_CLASS = 'OCA\\Mail\\Service\\Search\\SearchQuery';
	private const FLAG_CLASS = 'OCA\\Mail\\Service\\Search\\Flag';
	private const DRAFTS_SERVICE_CLASS = 'OCA\\Mail\\Service\\DraftsService';
	private const LOCAL_MESSAGE_MAPPER_CLASS = 'OCA\\Mail\\Db\\LocalMessageMapper';
	private const IMAP_MESSAGE_MAPPER_CLASS = 'OCA\\Mail\\IMAP\\MessageMapper';
	private const LOCAL_MESSAGE_CLASS = 'OCA\\Mail\\Db\\LocalMessage';

	/** OCA\Mail\Exception\ClientException: what AccountService::find() throws for an account the user does not own. */
	public const CLIENT_EXCEPTION_CLASS = 'OCA\\Mail\\Exception\\ClientException';

	/**
	 * Receives the app manager, the container the Mail services are resolved from and the user manager.
	 *
	 * @param IAppManager $appManager app enablement, checked per user on every call
	 * @param \Closure(string): mixed $resolver resolves fixed Mail services lazily by class name
	 * @param IUserManager $userManager resolves the UID to the IUser that IAppManager::isEnabledForUser() expects
	 * @return void
	 */
	public function __construct(
		private IAppManager $appManager,
		private \Closure $resolver,
		private IUserManager $userManager,
	) {
	}

	/**
	 * Whether the Mail app is enabled for the user, in a version MCP supports, on this very call.
	 *
	 * @param string $userId authenticated user UID
	 * @return bool true when the user may use the Mail app now
	 */
	public function isEnabledFor(string $userId): bool {
		$user = $this->userManager->get($userId);
		// A Mail older than MCP supports (AppEnablement::MINIMUM_VERSIONS) counts as off.
		return $user !== null && AppEnablement::forUser($this->appManager, self::APP_ID, $user);
	}

	/**
	 * OCA\Mail\Service\AccountService, used only through findByUserId() and find(), which read the account table.
	 *
	 * @param string $userId authenticated user UID, because app enablement is per user
	 * @return object OCA\Mail\Service\AccountService
	 * @throws MailUnavailableException when Mail is disabled for the user or the class is not loaded
	 */
	public function accountService(string $userId): object {
		return $this->resolve(self::ACCOUNT_SERVICE_CLASS, $userId);
	}

	/**
	 * OCA\Mail\Db\MailboxMapper, used only through findAll(), which reads the folder cache.
	 *
	 * @param string $userId authenticated user UID, because app enablement is per user
	 * @return object OCA\Mail\Db\MailboxMapper
	 * @throws MailUnavailableException when Mail is disabled for the user or the class is not loaded
	 */
	public function mailboxMapper(string $userId): object {
		return $this->resolve(self::MAILBOX_MAPPER_CLASS, $userId);
	}

	public function messageMapper(string $userId): object {
		return $this->resolve(self::MESSAGE_MAPPER_CLASS, $userId);
	}

	public function mailManager(string $userId): object {
		return $this->resolve(self::MAIL_MANAGER_CLASS, $userId);
	}

	/** The Mail app's local compose-draft service. */
	public function draftsService(string $userId): object {
		return $this->resolve(self::DRAFTS_SERVICE_CLASS, $userId);
	}

	/** The Mail app's owner-scoped local message mapper. */
	public function localMessageMapper(string $userId): object {
		return $this->resolve(self::LOCAL_MESSAGE_MAPPER_CLASS, $userId);
	}

	/** The Mail app's IMAP message mapper for attachment reads. */
	public function imapMessageMapper(string $userId): object {
		return $this->resolve(self::IMAP_MESSAGE_MAPPER_CLASS, $userId);
	}

	/** Makes the Mail app's local unsent draft entity without a hard dependency on Mail. */
	public function newLocalDraft(string $userId): object {
		$class = self::LOCAL_MESSAGE_CLASS;
		if (!$this->isEnabledFor($userId) || !class_exists($class)) {
			throw new MailUnavailableException();
		}
		$message = new $class();
		$message->setType($class::TYPE_DRAFT);
		return $message;
	}

	/** The Mail app's local draft type constant. */
	public function localDraftType(string $userId): int {
		$class = self::LOCAL_MESSAGE_CLASS;
		if (!$this->isEnabledFor($userId) || !class_exists($class)) {
			throw new MailUnavailableException();
		}
		return $class::TYPE_DRAFT;
	}

	public function attachmentService(string $userId): object {
        return $this->resolve('OCA\\Mail\\Service\\Attachment\\AttachmentService', $userId);
    }

    public function localOutgoingType(string $userId): int {
        $class=self::LOCAL_MESSAGE_CLASS;if(!$this->isEnabledFor($userId)||!class_exists($class))throw new MailUnavailableException();
        return $class::TYPE_OUTGOING;
    }

    public function tagMapper(string $uid):object {return $this->resolve('OCA\\Mail\\Db\\TagMapper',$uid);}
    public function guardedMailboxes(string $uid):object {return $this->resolve('OCA\\Mail\\Service\\GuardedMailboxMutations',$uid);}
    public function guardedMutations(string $uid): object {return $this->resolve('OCA\\Mail\\Service\\GuardedMessageMutations',$uid);}

    public function smtpFactory(string $userId): object {return $this->resolve('OCA\\Mail\\SMTP\\SmtpClientFactory',$userId);}

    public function aliasesService(string $userId): object {
        return $this->resolve('OCA\\Mail\\Service\\AliasesService', $userId);
    }

    public function clientFactory(string $userId): object {
		return $this->resolve(self::CLIENT_FACTORY_CLASS, $userId);
	}

	public function newSearchQuery(string $userId): object {
		$class = self::SEARCH_QUERY_CLASS;
		if (!$this->isEnabledFor($userId) || !class_exists($class)) {
			throw new MailUnavailableException();
		}
		return new $class();
	}

	public function flag(string $userId, string $constant, bool $isSet): object {
		$class = self::FLAG_CLASS;
		if (!$this->isEnabledFor($userId) || !class_exists($class) || !defined($class . '::' . $constant)) {
			throw new MailUnavailableException();
		}
		$value = constant($class . '::' . $constant);
		return $isSet ? $class::is($value) : $class::not($value);
	}

	/**
	 * Resolves a Mail class by name only after the app is confirmed enabled and the class exists.
	 *
	 * @param string $class fully qualified Mail class name, never a hard reference from the module
	 * @param string $userId authenticated user, because app enablement is per user
	 * @return object the Mail service, untyped on purpose: the class may not exist in this installation
	 * @throws MailUnavailableException when Mail is disabled, the class is absent or the container returns no object
	 */
	protected function resolve(string $class, string $userId): object {
		if (!$this->isEnabledFor($userId) || !class_exists($class)) {
			throw new MailUnavailableException();
		}
		$service = ($this->resolver)($class);
		if (!is_object($service)) {
			throw new MailUnavailableException();
		}
		return $service;
	}
}
