<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Integration;
use OCA\Mcp\Mcp\Operations\BeforeEffectFailure;
use OCA\Mcp\Modules\Mail\Mutations\RefreshRequest;
/** Owned cache-only target. No implicit synchronization or authentication in review/read/status. */
class MailRefreshTarget {
    public function __construct(private MailServices $native,private MailSettingsGateway $settings,private MailCapabilities $capabilities){}
    public function snapshot(string $uid,array $args):array {
        $this->capabilities->requireSupported($uid,'refresh');$account=(int)$args['account_id'];$box=(int)$args['mailbox_id'];$profile=$this->settings->readConnection($uid,$account);$helper=$this->native->refreshSnapshots($uid);
        try{$helper->requireStorage();$s=$helper->capture($uid,$account,$box);}catch(\Throwable){throw new BeforeEffectFailure('The owned mailbox is outside the qualified refresh boundary. Use native Mail.');}
        $reset=$args['allow_cache_reset']??false;$r=['kind'=>'refresh','account_id'=>$account,'mailbox_id'=>$box,'mailbox'=>$s['mailbox'],'profile_revision'=>$profile['revision'],'cache_revision'=>$s['fingerprint'],'allow_cache_reset'=>$reset];if(!RefreshRequest::valid($r))throw new BeforeEffectFailure('Unsupported refresh target.');
        if($s['needs_reset']&&!$reset)throw new BeforeEffectFailure('This cache needs an explicitly reviewed reset. Preview with allow_cache_reset=true or use native Mail.');return ['request'=>$r,'cached_messages'=>count($s['messages']),'needs_reset'=>$s['needs_reset']];
    }
}
