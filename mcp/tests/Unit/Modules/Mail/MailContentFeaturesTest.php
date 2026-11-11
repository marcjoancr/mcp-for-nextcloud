<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use PHPUnit\Framework\TestCase;
use OCA\Mcp\Modules\Mail\Integration\{MailContentReader,MailContentFeatures,MailNativeBoundary,MailServices};
use OCA\Mcp\Modules\Mail\Content\MailBodyIndex;
use OCA\Mcp\Mcp\Confirmation\{PlanState,PlanChanged};
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\OAuth\ConnectionContext;
final class MailContentFeaturesTest extends TestCase {
    public function testFeatureFencesKeepIndependentCurrentToolAndConnection():void {
        $connection=new ConnectionContext();$connection->set(7);$seen=[];$allowed=true;
        $reader=new MailContentReader($this->createMock(MailServices::class),new MailNativeBoundary($this->createMock(\OCP\App\IAppManager::class)),(new InMemoryConfig())->mock($this),$this->createMock(\OCP\IDBConnection::class),$connection,static function($uid,$id,$tool,$account,$box,$message)use(&$seen,&$allowed):bool {$seen[]=$tool;return $allowed;});
        foreach(['mail_search_drafts','mail_import_draft','mail_index_messages','mail_search_indexed_body'] as $tool){$f=$reader->featureFence('alice',$tool,static function(array $refs):void {});$f->authorize('alice',1,2,3);self::assertSame($tool,$seen[array_key_last($seen)]);}
        $allowed=false;$this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);$f->authorize('alice',1,2,3);
    }
    public function testChangedConnectionCannotReuseFeatureFence():void {$connection=new ConnectionContext();$connection->set(7);$reader=new MailContentReader($this->createMock(MailServices::class),new MailNativeBoundary($this->createMock(\OCP\App\IAppManager::class)),(new InMemoryConfig())->mock($this),$this->createMock(\OCP\IDBConnection::class),$connection,static fn()=>true);$f=$reader->featureFence('alice','mail_index_messages',static function(array $refs):void {});$connection->set(8);$this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);$f->authorize('alice',1,2,3);}
    public function testChangedIndexRevisionRequiresNewApprovalBeforeAnyWrite():void {$native=$this->createMock(MailContentFeatures::class);$native->method('indexPreview')->willReturnOnConsecutiveCalls(['items'=>[['content_revision'=>'first']]],['items'=>[['content_revision'=>'changed']]]);$native->expects(self::never())->method('index');$service=new MailBodyIndex($native,new PlanState((new InMemoryConfig())->mock($this)));$args=['message_ids'=>[7]];$plan=$service->preview($args,'alice');$this->expectException(PlanChanged::class);$service->index($args+['plan_state'=>$plan['plan_state']],'alice');}
}
