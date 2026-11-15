<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Forms\Execution\FormsBulkImpactCursor;
use OCA\Mcp\Modules\Forms\Execution\FormsBulkWorkflow;
use OCA\Mcp\OAuth\ConnectionContext;
use OCA\Mcp\Tests\Support\OperationActors;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use PHPUnit\Framework\TestCase;
final class FormsBulkShareProviderProfileTest extends TestCase {
    private FormsBulkWorkflow $workflow;
    protected function setUp():void {
        $connection=new ConnectionContext();$connection->set(12);
        $this->workflow=new FormsBulkWorkflow(new FormsBulkImpactCursor(new PlanState((new InMemoryConfig())->mock($this)),new OperationActors(),$connection));
    }
    private function rows(int $count):array {
        $rows=[];for($id=1;$id<=$count;$id++){$provider=$id%2?'ocCircleShare':'ocinternal';
            $rows[]=['native_share_id'=>100+$id,'recipient'=>'recipient-'.$id,'type'=>$provider==='ocCircleShare'?7:1,
                'folder_ids'=>[1000+$id],'managed_share_ids'=>[$provider.':'.$id],'files_share_provider'=>$provider,'action'=>'remove'];}
        return $rows;
    }
    private function guard(array $rows,array $profile=['files_share_id_profile'=>'core-share-pk-v1']):object {
        return new class($rows,$profile) {
            public function __construct(private array $rows,private array $profile){}
            public function bulkSnapshot(string $uid,string $tool,array $input):array {
                return ['revision'=>'b1:42:'.str_repeat('a',64),'form'=>['id'=>4,'ownerId'=>'alice'],
                    'counts'=>['files'=>0,'managed_shares'=>count($this->rows)],'file_manifest'=>['files_share'=>$this->rows!==[]],
                    'destination'=>null,'recovery'=>'complete_sealed_before_effect','folders'=>'retained','linked_outputs'=>'retained']+$this->profile;
            }
            public function bulkPreviewPage(string $uid,string $tool,array $input,string $revision,string $kind,int $cursor,int $limit):array {
                if($kind==='files')return ['items'=>[],'total'=>0,'next_cursor'=>null,'complete'=>true,'revision'=>$revision];
                $after=array_values(array_filter($this->rows,static fn(array $r):bool=>(int)explode(':',$r['managed_share_ids'][0])[1]>$cursor));
                $items=array_slice($after,0,$limit);$complete=count($after)<=$limit;
                return ['items'=>$items,'total'=>count($this->rows),'next_cursor'=>$complete?null:(int)explode(':',$items[array_key_last($items)]['managed_share_ids'][0])[1],
                    'complete'=>$complete,'revision'=>$revision];
            }
        };
    }
    private function capture():\Closure {return static fn(string $tool):array=>['tool'=>$tool,'operations'=>['delete_form'],'references'=>['files_operations'=>['share']]];}
    public function testMixedProvidersKeepOneCompleteKeysetAndDoNotExposePrivateProof():void {
        $rows=$this->rows(101);$guard=$this->guard($rows);$seen=[];
        $snapshot=$this->workflow->preview('alice','forms_delete_form',['id'=>4],$guard,$this->capture(),static function(array $origin,string $tool,array $manifest)use(&$seen):void {
            foreach($manifest['files']??[]as$row)$seen[]=$row['file_id'];
        });
        self::assertSame(range(1001,1101),$seen);
        $cursor=$snapshot['impact']['bulk']['collections']['managed_shares']['cursor'];$actual=[];
        do{$page=$this->workflow->inspect('alice',$cursor,50,$guard,$this->capture(),static function():void{});
            foreach($page['items']as$row)$actual[]=$row['managed_share_ids'][0];
            self::assertArrayNotHasKey('files_share_provider',$page['items'][0]);
            $cursor=$page['nextCursor'];
        }while($cursor!==null);
        self::assertSame(array_column(array_column($rows,'managed_share_ids'),0),$actual);
    }
    public function testLegacyInternalPagesWithoutProfileOrRowProofStillWork():void {
        $row=$this->rows(2)[1];unset($row['files_share_provider']);
        $out=$this->workflow->preview('alice','forms_delete_form',['id'=>4],$this->guard([$row],[]),$this->capture(),static function():void{});
        self::assertSame(1,$out['impact']['bulk']['collections']['managed_shares']['total']);
    }
    /** @dataProvider invalid */
    public function testUnknownOrMismatchedProviderProofCannotAuthorizeAWholeFormChange(array $row,array $profile):void {
        $this->expectException(ToolFailure::class);
        $this->workflow->preview('alice','forms_delete_form',['id'=>4],$this->guard([$row],$profile),$this->capture(),static function():void{});
    }
    public static function invalid():iterable {
        $circle=['native_share_id'=>11,'recipient'=>'circle','type'=>7,'folder_ids'=>[1001],'managed_share_ids'=>['ocCircleShare:1'],'files_share_provider'=>'ocCircleShare','action'=>'remove'];
        $profile=['files_share_id_profile'=>'core-share-pk-v1'];
        yield 'Circle without negotiated profile'=>[$circle,[]];
        yield 'unknown profile'=>[$circle,['files_share_id_profile'=>'future']];
        yield 'null profile'=>[$circle,['files_share_id_profile'=>null]];
        $bad=$circle;unset($bad['files_share_provider']);yield 'missing provider proof'=>[$bad,$profile];
        $bad=$circle;$bad['files_share_provider']='ocinternal';yield 'prefix mismatch'=>[$bad,$profile];
        $bad=$circle;$bad['type']=1;yield 'Circle with group type'=>[$bad,$profile];
        $bad=$circle;$bad['managed_share_ids']=['ocinternal:1'];yield 'Circle proof on default prefix'=>[$bad,$profile];
        $bad=$circle;$bad['files_share_provider']='future';yield 'unknown provider'=>[$bad,$profile];
        $bad=$circle;$bad['managed_share_ids']=['occircleshare:1'];yield 'alias case'=>[$bad,$profile];
        $bad=$circle;$bad['managed_share_ids']=['ocCircleShare:01'];yield 'noncanonical decimal'=>[$bad,$profile];
        $bad=$circle;$bad['managed_share_ids']=['ocCircleShare:9223372036854775808'];yield 'integer overflow'=>[$bad,$profile];
        $bad=$circle;$bad['managed_share_ids']=['ocinternal:1'];$bad['files_share_provider']='ocinternal';yield 'default with Circle type'=>[$bad,$profile];
    }
    public function testTheSameCanonicalIdCannotAppearUnderBothProviders():void {
        $rows=$this->rows(2);$rows[1]['managed_share_ids']=['ocinternal:1'];
        $this->expectException(ToolFailure::class);
        $this->workflow->preview('alice','forms_delete_form',['id'=>4],$this->guard($rows),$this->capture(),static function():void{});
    }
}
