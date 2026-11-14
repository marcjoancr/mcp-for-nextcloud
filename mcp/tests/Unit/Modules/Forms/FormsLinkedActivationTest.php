<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use OCA\Mcp\Modules\Forms\Exports\FormsLinkedActivation;
use OCA\Mcp\Modules\Forms\Exports\FormsLinkedExport;
use OCA\Mcp\Modules\Forms\Integration\FormsGuardIdentity;
use PHPUnit\Framework\TestCase;
final class FormsLinkedActivationTest extends TestCase {
    private function job():array {
        $metadata=['form'=>['id'=>4,'ownerId'=>'alice'],'questions'=>[],'timeZone'=>'UTC'];
        $args=['id'=>4,'format'=>'csv','path'=>'results.csv'];
        $destination=['path'=>'results.csv','overwrite'=>false,'audience'=>['digest'=>'private']];
        return ['actor_uid'=>'alice','payload'=>['origin'=>['uid'=>'alice','connection'=>12,'tool'=>'forms_link_results_file'],'arguments'=>$args,
            'source_revision'=>'17','destination'=>$destination,'metadata'=>$metadata,'current_step'=>['key'=>'original-delivery-key'],
            'linked_approval'=>['arguments'=>$args,'destination'=>$destination,'native_runtime'=>FormsGuardIdentity::INSTALLED_MANIFEST_SHA256,
                'source_revision'=>'17','schema_digest'=>FormsLinkedExport::schemaDigest($metadata)]]];
    }
    public function testActivationUsesOriginalApprovalAndOneNativeAtomicCallback():void {
        $job=$this->job();$writes=0;$applies=0;
        $guard=new class($applies) {
            public function __construct(private int &$calls) {}
            public function apply($uid,$action,$input,$revision,$key,$manifest,$write):array {
                $this->calls++;
                TestCase::assertSame('alice',$uid);TestCase::assertSame('forms_link_results_file',$action);
                TestCase::assertSame(12,$input['_mcp_origin']['connection']);TestCase::assertSame('current-fence',$revision);
                TestCase::assertSame('original-delivery-key',$key);TestCase::assertSame('17',$input['_mcp_link_approval']['source_revision']);
                return ['phases'=>['database'=>'committed','files'=>'saved_verified'],'data'=>['file'=>$write(),'association_id'=>str_repeat('a',64)]];
            }
        };
        $link=new FormsLinkedActivation();
        $result=$link->activate('alice',$job,$guard,static fn()=>['revision'=>'current-fence','export_revision'=>'17'],function()use(&$writes):array{$writes++;return ['file_id'=>8];});
        self::assertSame(1,$writes);self::assertSame(1,$applies);self::assertSame(8,$result['file_id']);
        self::assertSame(str_repeat('a',64),$result['association_id']);
    }
    /** @dataProvider invalidations */
    public function testIncompleteOrSubstitutedApprovalCannotReachNativeEffect(string $change):void {
        $job=$this->job();
        match($change) {
            'origin'=>$job['payload']['origin']['uid']='bob',
            'arguments'=>$job['payload']['arguments']['path']='other.csv',
            'destination'=>$job['payload']['destination']['path']='other.csv',
            'schema'=>$job['payload']['metadata']['questions']=[['id'=>7]],
            'runtime'=>$job['payload']['linked_approval']['native_runtime']='other',
            'source'=>$job['payload']['source_revision']='18',
            'missing'=>$job['payload']['linked_approval']=[],
        };
        $guard=new class {public function apply():array {TestCase::fail('Invalid approval reached native effect.');}};
        $this->expectException(\RuntimeException::class);
        (new FormsLinkedActivation())->activate('alice',$job,$guard,static fn()=>['revision'=>'current-fence','export_revision'=>'17'],static fn()=>[]);
    }
    public static function invalidations():iterable {foreach(['origin','arguments','destination','schema','runtime','source','missing'] as $value)yield $value=>[$value];}
    public function testUnprovenNativeCommitCannotBecomeDelivered():void {
        $guard=new class {public function apply():array {return ['phases'=>['database'=>'unknown','files'=>'saved_verified'],'data'=>['file'=>['file_id'=>8]]];}};
        $this->expectException(\RuntimeException::class);
        (new FormsLinkedActivation())->activate('alice',$this->job(),$guard,static fn()=>['revision'=>'current-fence','export_revision'=>'17'],static fn()=>[]);
    }
}
