<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use OCA\Mcp\Modules\Forms\Exports\FormsLinkedRetirement;
use OCA\Mcp\Modules\Forms\Exports\FormsJobStore;
use OCA\Mcp\Modules\Forms\Integration\FormsGuardIdentity;
use PHPUnit\Framework\TestCase;
final class FormsLinkedRetirementTest extends TestCase {
    private array $claim;private array $cycle;private array $job;private bool $sealed=true;private FormsLinkedRetirement $evidence;
    protected function setUp():void {
        $this->cycle=['job_id'=>str_repeat('b',64),'generation'=>2];
        $this->claim=['association_id'=>str_repeat('a',64),'origin'=>['uid'=>'alice','connection'=>12,'proof'=>'original-proof'],
            'arguments'=>['id'=>4,'format'=>'csv'],'last_verified'=>['path'=>'/results.csv','etag'=>'verified'],'active_cycle'=>$this->cycle];
        $this->job=['job_id'=>$this->cycle['job_id'],'actor_uid'=>'alice','state'=>'cancelled','version'=>5,
            'payload'=>['tool'=>'forms_link_results_file','native_runtime'=>FormsGuardIdentity::INSTALLED_MANIFEST_SHA256,
                'origin'=>$this->claim['origin'],'cache_protocol'=>2,'arguments'=>['id'=>4,'format'=>'csv','path'=>'/results.csv','overwrite'=>true,'etag'=>'verified'],
                'metadata'=>['questions'=>[['text'=>'private-question']]],
                'last_step'=>['key'=>hash('sha256','forms-linked-retire-v1:'.$this->claim['association_id'].':2'),'approved_version'=>4,
                    'outcome'=>['state'=>'succeeded','references'=>['job_id'=>$this->cycle['job_id']]]]]];
        $store=$this->createMock(FormsJobStore::class);$store->method('get')->with($this->cycle['job_id'])->willReturnCallback(fn():array=>$this->job);
        $this->evidence=new FormsLinkedRetirement($store,fn(string $id,string $uid):bool=>$id===$this->cycle['job_id']&&$uid==='alice'&&$this->sealed);
    }
    public function testCancelledSealedCycleReturnsExactEvidenceWithoutGeneratedQuestionContent():void {
        $proof=$this->evidence->proof($this->claim,$this->cycle);
        self::assertSame('cancelled',$proof['state']);self::assertTrue($proof['sealed']);self::assertFalse($proof['delivery_started']);
        self::assertSame($this->job['payload']['origin'],$proof['payload']['origin']);
        self::assertStringNotContainsString('private-question',json_encode($proof));
    }
    /** @dataProvider invalidations */
    public function testUnknownOrDifferentDeliveryCannotAuthorizeNativeCycleRetirement(string $change):void {
        match($change) {
            'uncertain'=>$this->job['state']='delivery_uncertain',
            'unsealed'=>$this->sealed=false,
            'key'=>$this->job['payload']['last_step']['key']='different',
            'actor'=>$this->job['actor_uid']='bob',
            'origin'=>$this->job['payload']['origin']['connection']=13,
            'partial'=>$this->job['payload']['last_step']['outcome']['state']='partial',
            'arguments'=>$this->job['payload']['arguments']['path']='/other.csv',
        };
        $this->expectException(\RuntimeException::class);$this->evidence->proof($this->claim,$this->cycle);
    }
    public static function invalidations():iterable {foreach(['uncertain','unsealed','key','actor','origin','partial','arguments'] as $value)yield [$value];}
}
