<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Modules\Mail\Mutations\{MutationRequest,TagMutationRequest};
use PHPUnit\Framework\TestCase;
final class MailTagMutationRequestTest extends TestCase {
    public static function request():array{return ['kind'=>'tag','account_id'=>4,'message_id'=>17,'mailbox_id'=>5,'mailbox'=>'INBOX','generation'=>'42','uid'=>'9','profile_revision'=>'p','cache_revision'=>'c','tag_id'=>7,'imap_label'=>'$reviewed','tag_revision'=>str_repeat('a',64),'tag_cache_revision'=>str_repeat('b',64),'value'=>true];}
    public function testNativeKeywordSemanticsMatchCanonicalMailDefaultsWithoutAdmittingSystemFlags():void {
        require_once dirname(__DIR__,5).'/native/mail-guard/src/IMAP/TagKeywords.php';
        self::assertSame('$label1',\OCA\Mail\IMAP\TagKeywords::canonical('IMPORTANT'));self::assertFalse(\OCA\Mail\IMAP\TagKeywords::atom('$'.str_repeat('x',64)));
        self::assertSame(['$label1','$important','important','$labelimportant'],\OCA\Mail\IMAP\TagKeywords::forms('$label1'));
        foreach(['$label1','$label2','$custom','$junk','$phishing','\\Seen','\\seen','$bad\\seen','$bad]x','$CUSTOM','$important','bad*']as$label)self::assertSame(TagMutationRequest::keyword($label),\OCA\Mail\IMAP\TagKeywords::valid($label));
    }
    public function testOnlyOneExactKeywordRequestAndCompleteBoundProofAreAccepted():void {
        $r=self::request();self::assertTrue(MutationRequest::valid($r));self::assertSame('mail_set_message_tag',MutationRequest::tool($r));
        foreach([['imap_label'=>'\\Seen'],['imap_label'=>'bad*'],['imap_label'=>'$junk'],['imap_label'=>'$important'],['tag_id'=>0],['value'=>'true'],['uid'=>'0'],['arbitrary_command'=>'UID STORE']]as$change)self::assertFalse(MutationRequest::valid(array_replace($r,$change)));
        $p=['outcome'=>'verified','message_id'=>17,'mailbox_id'=>5,'generation'=>'42','uid'=>'9','tag_id'=>7,'value'=>true];self::assertTrue(TagMutationRequest::remote($r,$p));self::assertFalse(TagMutationRequest::remote($r,array_replace($p,['tag_id'=>8])));self::assertFalse(TagMutationRequest::remote($r,$p+['flags'=>['secret']]));
        $c=['state'=>'updated','message_id'=>17,'tag_id'=>7,'value'=>true];self::assertTrue(TagMutationRequest::cache($r,$c));self::assertFalse(TagMutationRequest::cache($r,array_replace($c,['value'=>false])));
    }
}
