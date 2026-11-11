<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use PHPUnit\Framework\TestCase;
final class MailDeliveryArtifactTest extends TestCase {
    private function renderer():object {
        $path=dirname(__DIR__,5).'/native/mail-guard/src/Service/FrozenMessageRenderer.php';
        self::assertFileExists($path,'Approved delivery needs a frozen renderer, not the mutable stock send path.');
        require_once $path;return new \OCA\Mail\Service\FrozenMessageRenderer();
    }
    private function content():array {return ['draftId'=>1,'accountId'=>2,'draftRevision'=>str_repeat('a',64),'accountRevision'=>1,'aliasId'=>null,'aliasRevision'=>null,'sender'=>['name'=>'Sender','email'=>'sender@example.test'],'to'=>[['name'=>'To','email'=>'to@example.test']],'cc'=>[],'bcc'=>[['name'=>'Hidden','email'=>'hidden@example.test']],'subject'=>'Hello','bodyPlain'=>".line\nbody",'bodyHtml'=>null,'replyTo'=>null,'aiGenerated'=>false,'attachments'=>[]];}
    public function testFrozenBytesPreserveBccPrivacyAndContent():void {
        $r=$this->renderer();$c=$this->content();$f=$r->freeze($c);
        self::assertStringNotContainsString('Bcc:', $f['smtp']);self::assertStringContainsString('Bcc:', $f['fcc']);
        self::assertSame(['to@example.test','hidden@example.test'],$f['recipients']);self::assertSame('sender@example.test',$f['from']);
        self::assertStringContainsString(base64_encode(".line\nbody"),$f['smtp']);self::assertSame(0,preg_match('/(?<!\r)\n/',$f['smtp']));
        self::assertSame($r->revision($c),$r->revision($c));$c['sender']['email']='changed@example.test';self::assertNotSame($r->revision($c),$r->revision($this->content()));
    }
    public function testAttachmentBytesAndIdentityAreApproved():void {
        $r=$this->renderer();$c=$this->content();$c['attachments']=[['id'=>4,'name'=>'a.txt','mime'=>'text/plain','disposition'=>'attachment','contentId'=>null,'bytes'=>3,'sha256'=>hash('sha256','one'),'content'=>'one']];
        $a=$r->revision($c);$c['attachments'][0]['content']='two';$c['attachments'][0]['sha256']=hash('sha256','two');self::assertNotSame($a,$r->revision($c));
        $c['attachments'][0]['content']='missing';$this->expectException(\RuntimeException::class);$r->freeze($c);
    }
    public function testGroupAndHeaderInjectionAreRefused():void {
        $r=$this->renderer();$c=$this->content();$c['to'][0]['email']="victim@example.test\r\nBcc: other@example.test";
        $this->expectException(\RuntimeException::class);$r->freeze($c);
    }
    public function testUtf8HeadersStayWithinEncodedWordLimitsAndBodyBytesSurvive():void {
        $r=$this->renderer();$c=$this->content();$c['sender']['name']=str_repeat('🌍',35);$c['subject']=str_repeat('é',60);$c['bodyPlain']="café\n.élan";
        $f=$r->freeze($c);self::assertSame(0,preg_match('/[^\x00-\x7f]/',$f['smtp']));self::assertStringContainsString(base64_encode($c['bodyPlain']),$f['smtp']);
        preg_match_all('/=\?UTF-8\?B\?.*?\?=/',$f['smtp'],$words);foreach($words[0] as $word)self::assertLessThanOrEqual(75,strlen($word),'RFC2047 encoded words must stay bounded in bytes.');
    }

}
