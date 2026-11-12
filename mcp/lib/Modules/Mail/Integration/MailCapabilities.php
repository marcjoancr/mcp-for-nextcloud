<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail\Integration;

use OCA\Mcp\Mcp\Operations\BeforeEffectFailure;
use OCP\App\IAppManager;
use OCP\IConfig;

/** Evidence for future native paths, never an authorization grant or a replacement for A/D availability. */
final class MailCapabilities {
    public function __construct(
        private MailServices $native,
        private IAppManager $apps,
        private IConfig $config,
        private ?MailNativeBoundary $boundary = null,
        private ?MailTagBoundary $tagBoundary = null,
        private ?MailDeliveryGateway $delivery = null,
    ) {}

    /** Read-only metadata: never instantiate an IMAP/SMTP client or run a lifecycle experiment here. */
    public function forUser(string $uid): array { return $this->evaluate($uid); }

    /** Recheck only the requested boundary on latency-bounded mutation callbacks. */
    private function evaluate(string $uid, ?string $only = null): array {
        $needs = static fn(string ...$capabilities): bool => $only === null || in_array($only, $capabilities, true);
        $enabled = $this->native->isEnabledFor($uid);
        $mail = $enabled ? self::version($this->apps->getAppVersion('mail')) : null;
        $server = $enabled ? self::version($this->config->getSystemValueString('version', '')) : null;
        $known = $enabled && $mail === '5.12.5' && MailRuntimePairs::matches($this->config->getSystemValueString('version',''),$this->apps->getAppVersion('mail'));
        $evidence = !$enabled ? 'unavailable' : ($known ? 'characterized' : 'unverified');
        $unknown = ['status' => $enabled ? 'unverified' : 'unavailable', 'reason' => $enabled ? 'native_pair_not_characterized' : 'mail_unavailable'];
        $capabilities = array_fill_keys(['reply_parent_local', 'reply_parent_imap', 'sender_alias_local', 'sender_alias_imap', 'mutation_session', 'flags', 'move', 'tags',
            'create_mailbox', 'rename_mailbox', 'subscribe_mailbox', 'refresh', 'create_tag'], $unknown);
        if ($known) {
            $capabilities['reply_parent_local'] = ['status' => 'verified', 'reason' => 'local_parent_with_sync_hold'];
            $capabilities['reply_parent_imap'] = ['status' => 'unsupported', 'reason' => 'native_flush_omits_reply_headers'];
            $capabilities['sender_alias_local'] = ['status' => 'verified', 'reason' => 'local_alias_preserved'];
            $capabilities['sender_alias_imap'] = ['status' => 'unsupported', 'reason' => 'native_flush_uses_account_identity'];
            $capabilities['mutation_session'] = ['status' => 'unsupported', 'reason' => 'native_store_can_reselect_without_expected_generation'];
            foreach (['flags', 'move', 'tags'] as $key) {
                $capabilities[$key] = ['status' => 'blocked', 'reason' => 'mutation_session_not_verified'];
            }
            foreach (['create_mailbox', 'rename_mailbox', 'subscribe_mailbox', 'refresh'] as $key) {
                $capabilities[$key] = ['status' => 'unverified', 'reason' => 'native_sync_effects_and_budget_not_verified'];
            }
        }
        if($known && $needs('flags','mutation_session') && $this->boundary?->verified()){
            $capabilities['mutation_session']=['status'=>'verified','reason'=>'native_guarded_flags_only'];
            $capabilities['flags']=['status'=>'verified','reason'=>'native_guarded_flags_v1'];
            $capabilities['move']=['status'=>'blocked','reason'=>'complete_copyuid_and_cache_contract_not_verified'];
        }
        if($known && $needs('move','mutation_session') && $this->boundary?->movesVerified()){
            $capabilities['mutation_session']=['status'=>'verified','reason'=>'native_guarded_mutations_v2'];
            $capabilities['move']=['status'=>'verified','reason'=>'native_command_bound_copyuid_and_cache_v2'];
        }
        if($known && $needs('create_mailbox','rename_mailbox','subscribe_mailbox') && $this->boundary?->foldersVerified()){foreach(['create_mailbox','rename_mailbox','subscribe_mailbox'] as $key)$capabilities[$key]=['status'=>'verified','reason'=>'native_targeted_folder_mutations_v3'];}
        if($known){
            $capabilities['tags']=['status'=>'blocked','reason'=>'native_tag_cache_identity_and_creation_correlation_missing'];
            $capabilities['refresh']=['status'=>'unverified','reason'=>'native_sync_owner_lease_and_budget_not_verified'];
        }
        if($known&&$needs('create_tag','tags')&&$this->tagBoundary?->verified()){
            $capabilities['create_tag']=['status'=>'verified','reason'=>'native_local_tag_insert_with_atomic_journal'];
            $capabilities['tags']=['status'=>'blocked','reason'=>'native_tag_cache_identity_not_verified'];
        }
        if($known&&$needs('tags')&&$this->boundary?->tagsVerified())$capabilities['tags']=['status'=>'verified','reason'=>'native_exact_tag_cache_and_one_shot_keyword_v4'];
        if($known&&$needs('refresh')&&$this->boundary?->refreshVerified())$capabilities['refresh']=['status'=>'verified','reason'=>'native_owned_bounded_refresh_v5'];
        if($known&&$needs('reply_parent_imap','sender_alias_imap')&&$this->boundary?->draftsVerified()){
            $capabilities['reply_parent_imap']=['status'=>'verified','reason'=>'native_draft_identity_v6'];
            $capabilities['sender_alias_imap']=['status'=>'verified','reason'=>'native_owned_draft_alias_v6'];
        }
        $delivery=$known&&$this->delivery?->available($uid);
        foreach(['cancel_delivery','read_delivery','repair_sent']as$key)$capabilities[$key]=$delivery?['status'=>'verified','reason'=>'native_frozen_delivery_v11']:['status'=>'unverified','reason'=>'native_delivery_not_qualified'];
        foreach(['send','schedule']as$key)$capabilities[$key]=$delivery&&$this->delivery->enabled()?['status'=>'verified','reason'=>'native_frozen_delivery_v11']:['status'=>'disabled','reason'=>'sending_not_authorized'];
        return ['native_pair' => ['nextcloud' => $server, 'mail' => $mail], 'evidence' => $evidence,
            'capabilities' => $capabilities];
    }

    /** Future callers still need current tool selection, ownership, revision and write-journal admission. */
    public function requireSupported(string $uid, string $capability): void {
        $row = $this->evaluate($uid, $capability)['capabilities'][$capability] ?? null;
        if (!is_array($row) || $row['status'] !== 'verified') {
            throw new BeforeEffectFailure('This Mail capability has no verified native execution boundary. Use native Mail.');
        }
    }

    private static function version(string $value): ?string {
        if (preg_match('/^([0-9]+\.[0-9]+\.[0-9]+)(?:\.[0-9]+)?$/D', $value, $m) !== 1) return null;
        return $m[1];
    }
}
