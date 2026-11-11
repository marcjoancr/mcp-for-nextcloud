<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Integration;
use OCA\Mcp\Modules\Mail\Access\MailAccessGuard;
use OCA\Mcp\Mcp\Operations\BeforeEffectFailure;
/** A restricted caller may reuse only a tag already visible on an admitted owned message. */
final class MailTagVisibility {
    public function __construct(private MailServices $native,private MailAccessGuard $access){}
    public function requireVisible(string $uid,int $tag):?array {
        $items=$this->access->items($uid);if($items===null)return null;
        $accounts=[];$boxes=[];
        foreach($items as $item){[$kind,$id]=explode(':',$item,2);if($kind==='account')$accounts[]=(int)$id;else $boxes[]=(int)$id;}
        $reader=$this->native->boundedMetadata($uid);
        if(!is_callable([$reader,'tagSource']))throw new BeforeEffectFailure('Mail tag not found.');
        $source=$reader->tagSource($uid,$tag,$accounts,$boxes);
        if(!is_array($source)||!is_int($source['account_id']??null)||!is_int($source['mailbox_id']??null))throw new BeforeEffectFailure('Mail tag not found.');
        $this->access->requireMailbox($uid,$source['account_id'],$source['mailbox_id']);return $source;
    }
}
