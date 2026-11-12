<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use PHPUnit\Framework\TestCase;
final class MailDraftSnapshotTest extends TestCase {
    private function snapshot(array $data,bool $protected=false):string {
        $path=dirname(__DIR__,5).'/native/mail-guard/src/Drafts/DraftSnapshot.php';self::assertFileExists($path);require_once $path;
        $draft=new class($data,$protected) implements \JsonSerializable {
            public function __construct(private array $data,private bool $protected){}
            public function jsonSerialize():array{return $this->data;}
            public function getMcpDraftOnly():bool{return $this->protected;}
        };
        return \OCA\Mail\Drafts\DraftSnapshot::of($draft);
    }
    public function testSameSecondBodyOrAttachmentChangesInvalidateTheWholeSnapshot():void {
        $base=['id'=>7,'updatedAt'=>100,'bodyPlain'=>'Approved','attachments'=>[['id'=>3,'localMessageId'=>7]],'to'=>[['email'=>'a@example.test']]];
        $proof=$this->snapshot($base,true);self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D',$proof);
        self::assertNotSame($proof,$this->snapshot(array_replace($base,['bodyPlain'=>'Different']),true));
        self::assertNotSame($proof,$this->snapshot(array_replace($base,['attachments'=>[['id'=>4,'localMessageId'=>7]]]),true));
        self::assertNotSame($proof,$this->snapshot($base,false));
    }
    public function testNativeChildReadOrderingDoesNotInventARevisionChange():void {
        $a=['attachments'=>[['id'=>3],['id'=>2]],'to'=>[['email'=>'z@example.test'],['email'=>'a@example.test']],'raw'=>"raw bytes\0\xff"];
        $b=['raw'=>$a['raw'],'to'=>array_reverse($a['to']),'attachments'=>array_reverse($a['attachments'])];
        self::assertSame($this->snapshot($a),$this->snapshot($b));
    }
}
