<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Modules\Forms\Exports\FormsLinkedFileSink;
use OCA\Mcp\Modules\Forms\Integration\FormsNativeLinkedExportAuthority;
use PHPUnit\Framework\TestCase;
final class FormsNativeLinkedExportAuthorityTest extends TestCase {
    private bool $allowed=true;private bool $qualified=true;private array $initialized=[];private bool $rootAllowed=true;private object $guard;private FormsLinkedFileSink $files;private FormsNativeLinkedExportAuthority $authority;
    private function claim():array {return ['association_id'=>str_repeat('a',64),'version'=>4,'lease_token'=>'original-lease','origin'=>['uid'=>'alice','connection'=>12,'tool'=>'forms_link_results_file'],'active_cycle'=>['job_id'=>str_repeat('b',64),'generation'=>2]];}
    protected function setUp():void {
        $this->guard=new class {
            public array $calls=[];
            public function linkedClaim(string $id):?array {$this->calls[]=['claim',$id];return ['origin'=>['uid'=>'alice']];}
            public function linkedRead(array $claim,string $action,array $input):array {$this->calls[]=['read',$claim,$action,$input];return ['export_revision'=>'9'];}
            public function linkedPause(array $claim,string $reason):void {$this->calls[]=['pause',$claim,$reason];}
            public function linkedRestart(array $claim,array $cycle,callable $evidence):bool {$this->calls[]=['restart',$evidence()];return true;}
            public function syncLinkedFile(array $origin,string $id,int $version,string $revision,string $key,array $destination,callable $write):array {
                $this->calls[]=['write',$origin,$id,$version,$revision,$key,$destination];return $write();
            }
        };
        $policy=$this->createMock(GrantPolicy::class);
        foreach(['freshWithin','resourceBoundary'] as $method)$policy->method($method)->willReturnCallback(static fn(string $uid,\Closure $work):mixed=>$work());
        $this->files=$this->createMock(FormsLinkedFileSink::class);
        $this->authority=new FormsNativeLinkedExportAuthority(fn():object=>$this->guard,fn():bool=>$this->qualified,
            function(array $origin):array {if(!$this->allowed)throw new \RuntimeException('revoked');return $origin;},$policy,$this->files,
            static fn(array $claim,array $cycle):array=>['state'=>'cancelled','sealed'=>true],
            function(array $origin):void {if(!$this->rootAllowed)throw new \RuntimeException('foreign root');$this->initialized[]=$origin['uid'];});
    }
    public function testOnlyQualifiedCanonicalClaimInitializesTheOriginalRoot():void {
        self::assertSame(['origin'=>['uid'=>'alice']],$this->authority->claim(str_repeat('a',64)));
        self::assertSame(['alice'],$this->initialized);
        $this->qualified=false;
        $this->expectException(\RuntimeException::class);$this->authority->claim(str_repeat('b',64));
    }
    /** @dataProvider postClaimFailures */
    public function testFailureAfterLeaseAcquisitionDurablyPausesTheExactClaim(string $failure):void {
        if($failure==='authority')$this->allowed=false;else $this->rootAllowed=false;
        try {$this->authority->claim(str_repeat('a',64));self::fail('Post-claim failure admitted');}catch(\RuntimeException) {}
        self::assertSame(['pause',['origin'=>['uid'=>'alice']],'admission_failed'],$this->guard->calls[1]??null);
        self::assertSame([],$this->initialized);
    }
    public static function postClaimFailures():iterable {yield ['authority'];yield ['root'];}
    public function testSourceCallbackRetainsLeaseAndRechecksOriginalAuthorityOnEveryRead():void {
        $claim=$this->claim();$read=$this->authority->source($claim);
        self::assertSame(['export_revision'=>'9'],$read('snapshot',['id'=>4]));
        self::assertSame($claim,$this->guard->calls[0][1]);
        $this->allowed=false;
        try {$read('snapshot',['id'=>4]);self::fail('Revoked original authority admitted');}catch(\RuntimeException $error){self::assertSame('revoked',$error->getMessage());}
        self::assertCount(1,$this->guard->calls);
    }
    public function testDurableStopDoesNotRequireTheRevokedGrantToBecomeValidAgain():void {
        $this->allowed=false;$this->authority->pause($this->claim(),'admission_failed');
        self::assertSame('pause',$this->guard->calls[0][0]);
    }
    public function testSinkBindsExactNativeCycleAndInvokesFilesOnlyInsideNativeFence():void {
        $claim=$this->claim();$key=hash('sha256','forms-linked-write-v1:'.$claim['association_id'].':2');
        $job=['job_id'=>$claim['active_cycle']['job_id'],'actor_uid'=>'alice','state'=>'delivering','payload'=>[
            'origin'=>$claim['origin'],'tool'=>'forms_link_results_file','source_revision'=>'9','destination'=>['path'=>'/results.csv'],
            'current_step'=>['key'=>$key,'approved_version'=>7]]];
        $this->files->expects(self::once())->method('write')->willReturnCallback(function(array $c,string $content,array $j,\Closure $source):array {
            self::assertSame('write',$this->guard->calls[0][0]);self::assertSame('CSV',$content);return ['file_id'=>17];
        });
        self::assertSame(['file_id'=>17],$this->authority->write($claim,'CSV',$job));
        self::assertSame(['lease_token'=>'original-lease','active_cycle'=>$claim['active_cycle'],'destination'=>['path'=>'/results.csv']],$this->guard->calls[0][6]);
        self::assertSame($key,$this->guard->calls[0][5]);
        $job['payload']['current_step']['key']='different';
        $this->expectException(\RuntimeException::class);$this->authority->write($claim,'CSV',$job);
    }
    public function testRetirementEvidenceIsProducedOnlyWithinNativeRestart():void {
        $claim=$this->claim();self::assertTrue($this->authority->restart($claim,$claim['active_cycle']));
        self::assertSame(['restart',['state'=>'cancelled','sealed'=>true]],$this->guard->calls[0]);
    }
}
