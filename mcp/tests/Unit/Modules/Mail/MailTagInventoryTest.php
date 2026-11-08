<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Modules\Mail\Tags\MailTags;
use OCA\Mcp\Modules\Mail\Integration\MailServices;
use PHPUnit\Framework\TestCase;
final class MailTagInventoryTest extends TestCase {
    public function testTagInventoryFiltersForeignOwnershipAndHasNoMutationOrProtocolPath():void {
        $native=new class extends MailServices {
            public function __construct(){}
            public function tagMapper(string $uid):object{return new class {public function getAllTagsForUser(string $uid):array{$rows=[];foreach([[2,'alice','Work'],[3,'bob','PRIVATE']] as [$id,$owner,$name])$rows[]=new class($id,$owner,$name){public function __construct(private int $id,private string $owner,private string $name){}public function getId():int{return $this->id;}public function getUserId():string{return $this->owner;}public function getDisplayName():string{return $this->name;}public function getColor():string{return '#0082c9';}};return $rows;}};}
        };
        $r=(new MailTags($native))->read(['limit'=>1],'alice');self::assertCount(1,$r['tags']);self::assertSame(2,$r['tags'][0]['tag_id']);self::assertFalse($r['hasMore']);self::assertStringNotContainsString('PRIVATE',json_encode($r));
    }
}
