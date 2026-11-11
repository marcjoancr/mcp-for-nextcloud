<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail\Integration;

/**
 * Thin read-only boundary to the Mail app: turns its accounts and cached folders into plain arrays, so the handlers
 * never hold a Mail object and are tested with doubles.
 *
 * Only three Mail calls are made, and each one reads the database of the Mail app without contacting the mail server
 * (checked in the source of every Mail release MCP supports, 5.0.0 to 5.12.5):
 * - `AccountService::findByUserId()` reads the accounts through `MailAccountMapper::findByUserId()`, a SELECT on
 *   `mail_accounts` filtered by owner;
 * - `AccountService::find()` reads one account through `MailAccountMapper::find()`, a SELECT filtered by owner and id,
 *   and throws a ClientException for any other account;
 * - `MailboxMapper::findAll()` is a SELECT on `mail_mailboxes` by account id.
 * `MailManager::getMailboxes()` is deliberately not used: it runs `MailboxSync::sync()` first, which logs in to the
 * IMAP server. Nothing that flags, moves, syncs or writes is ever reached from here.
 *
 * Accounts delegated to the user by somebody else are left out: an account counts only when its owner is the user,
 * whatever a future Mail release returns from these calls.
 */
final class MailGateway implements \OCA\Mcp\Modules\Mail\Contracts\MailReader {
	/**
	 * Receives the resolver of the Mail services.
	 *
	 * @param MailServices $services the Mail classes, resolved by name after the per-user enablement check
	 * @return void
	 */
	public function __construct(
		private MailServices $services,
        private \OCA\Mcp\Modules\Mail\Access\MailAccessGuard $access,
        private ?MailNativeBoundary $boundary=null,
	) {
	}

	/**
	 * The mail accounts the user owns, as stored by the Mail app.
	 *
	 * @param string $userId authenticated user UID
	 * @return list<array{id:int, name:string, email:string}> one row per owned account, in the order of the Mail app
	 * @throws MailUnavailableException when Mail is not available for the user
	 */
	public function accounts(string $userId): array {
        $this->access->assertReadable($userId);
		$rows = [];
		foreach ($this->services->accountService($userId)->findByUserId($userId) as $account) {
			if ((string)$account->getUserId() !== $userId) {
				continue;
			}
			if ($this->visibleAccount($userId,$account)) $rows[] = $this->accountRow($account);
		}
		return $rows;
	}

	/**
	 * One account the user owns, by id.
	 *
	 * @param string $userId authenticated user UID
	 * @param int $accountId Mail account id
	 * @return object|null the OCA\Mail\Account; null when it does not exist, belongs to somebody else or is delegated
	 * @throws MailUnavailableException when Mail is not available for the user
	 */
	public function ownedAccount(string $userId, int $accountId): ?object {
        $this->access->assertReadable($userId);
		$exception = MailServices::CLIENT_EXCEPTION_CLASS;
		try {
			$account = $this->services->accountService($userId)->find($userId, $accountId);
		} catch (\Exception $e) {
			if ($e instanceof $exception) {
				return null;
			}
			throw $e;
		}
		return (string)$account->getUserId() === $userId ? $account : null;
	}

	/**
	 * The folders of an account, from the cache of the Mail app; empty when Mail has not synchronized them yet.
	 *
	 * @param string $userId authenticated user UID, for the enablement check
	 * @param int $accountId account id to authorize before reading cached folders
	 * @return array{account:array{id:int,name:string,email:string},mailboxes:list<array<string,mixed>>}|null authorized account and cached rows
	 * @throws MailUnavailableException when Mail is not available for the user
	 */
	public function mailboxes(string $userId, int $accountId): ?array {
		$account = $this->ownedAccount($userId, $accountId);
		if ($account === null || !$this->visibleAccount($userId,$account)) { return null; }
		$rows = [];
		foreach ($this->services->mailboxMapper($userId)->findAll($account) as $mailbox) {
            if (!$this->access->mailboxAllowed($userId,$accountId,(int)$mailbox->getId())) continue;
			$rows[] = [
				'id' => (int)$mailbox->getId(),
				'name' => (string)$mailbox->getName(),
				'delimiter' => $mailbox->getDelimiter() === null ? null : (string)$mailbox->getDelimiter(),
				'specialUse' => (string)($mailbox->getSpecialUse() ?? '[]'),
				'unread' => $mailbox->getUnseen() === null ? null : (int)$mailbox->getUnseen(),
				'total' => $mailbox->getMessages() === null ? null : (int)$mailbox->getMessages(),
				'selectable' => $mailbox->getSelectable() === null ? null : (bool)$mailbox->getSelectable(),
                'freshness' => $this->freshness($userId,$accountId,(int)$mailbox->getId()),
			];
		}
		return ['account' => $this->accountRow($account), 'mailboxes' => $rows];
	}

	/**
	 * The fields of an account that identify it to the user: never a password, a token, a server or a port.
	 *
	 * @param object $account OCA\Mail\Account
	 * @return array{id:int, name:string, email:string} the row of the account
	 */
    /** Whole-account selections only; null retains unrestricted semantics. */
    public function allowedAccounts(string $uid):?array {
        $items=$this->access->items($uid);if($items===null)return null;
        return array_values(array_map(static fn(string $id):int=>(int)substr($id,8),array_filter($items,static fn(string $id):bool=>str_starts_with($id,'account:'))));
    }
    /** Admit a local draft through its owned scalar identity before loading body/recipients/attachments. */
    public function requireDraft(string $uid,int $draft):void {
        $this->access->assertReadable($uid);if($this->access->unrestricted($uid))return;
        $rows=$this->services->boundedMetadata($uid)->identities($uid,'draft',[$draft]);
        if(count($rows)!==1)throw new \OCA\Mcp\Mcp\Tools\Results\ToolFailure('Mail draft not found.');
        $this->access->requireAccount($uid,$rows[0]['account_id']);
    }
    public function unrestricted(string $uid):bool {return $this->access->unrestricted($uid);}
    public function requireUnrestricted(string $uid):void {$this->access->requireUnrestricted($uid);}
    public function requireMailbox(string $uid,int $account,int $mailbox):void {
        $this->access->assertReadable($uid);
        if(!$this->access->unrestricted($uid)){
            $rows=$this->services->boundedMetadata($uid)->identities($uid,'mailbox',[$mailbox]);
            if(count($rows)!==1||$rows[0]['account_id']!==$account)throw new \OCA\Mcp\Mcp\Tools\Results\ToolFailure('Mail resource not found.');
        }
        $this->access->requireMailbox($uid,$account,$mailbox);
    }
    public function requireAccount(string $uid,int $id):object {
        $this->access->requireAccount($uid,$id);
        return $this->ownedAccount($uid,$id) ?? throw new \OCA\Mcp\Mcp\Tools\Results\ToolFailure('Mail account not found.');
    }
    private function visibleAccount(string $uid,object $account):bool {
        $id=(int)$account->getId();if($this->access->accountAllowed($uid,$id))return true;
        foreach($this->services->mailboxMapper($uid)->findAll($account) as $box)if($this->access->mailboxAllowed($uid,$id,(int)$box->getId()))return true;
        return false;
    }
    private function freshness(string $uid,int $account,int $box):array {
        if(!$this->boundary?->refreshSourceVerified())return ['status'=>'unknown'];
        try{return $this->services->refreshSnapshots($uid)->freshness($uid,$account,$box);}catch(\Throwable){return ['status'=>'unknown'];}
    }

	private function accountRow(object $account): array {
		return [
			'id' => (int)$account->getId(),
			'name' => (string)$account->getName(),
			'email' => (string)$account->getEmail(),
		];
	}
}
