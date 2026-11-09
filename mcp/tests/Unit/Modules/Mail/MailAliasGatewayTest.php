<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Modules\Mail\Integration\{MailAliasGateway,MailAliasBoundary};
use OCA\Mcp\Tests\Support\Mail\{MailSettingsFixture,SettingsNativeEntity};
use OCA\Mcp\Tests\Unit\SqliteDatabase;
use OCA\Mcp\Db\OperationsMapper;
use OCA\Mcp\Migration\Version002700Date20261107000000;
use OCA\Mcp\Mcp\Operations\BeforeEffectFailure;
use PHPUnit\Framework\TestCase;
final class MailAliasGatewayTest extends TestCase {
    use MailSettingsFixture;
    private function aliasCase():array {
        [$accounts,$entity,,$alias,$states,$native]=$this->fixture();
        $alias->setAlias('old@example.test');$alias->setName('Old');$alias->setSmimeCertificateId(77);$alias->setSignatureMode(1);
        $mapper=new class($alias) {
            public array $rows;public array $messages=[];public int $writes=0;
            public function __construct(object $a){$this->rows=[3=>$a];}
            public function find(int $id,string $uid):object{if($uid!=='alice'||!isset($this->rows[$id]))throw new \RuntimeException('Missing');return $this->rows[$id];}
            public function findAll(int $account,string $uid):array{return $uid==='alice'&&$account===4?array_values($this->rows):[];}
            public function update(object $a):object{$this->writes++;return $a;}
            public function insert(object $a):object{$a->setId(9);$this->rows[9]=$a;$this->writes++;return $a;}
            public function delete(object $a):object{unset($this->rows[$a->getId()]);$this->writes++;return $a;}
            public function getAllForUser(string $uid,int $type):array{return $this->messages;}
        };
        $native->method('newAliasMapper')->willReturn($mapper);$native->method('newAlias')->willReturnCallback(static fn():object=>new SettingsNativeEntity([]));
        $native->method('localMessageMapper')->willReturn($mapper);$native->method('localDraftType')->willReturn(1);$native->method('localOutgoingType')->willReturn(0);
        $sql=new SqliteDatabase($this,[new Version002700Date20261107000000()]);$ops=new OperationsMapper($sql->connection());
        $gateway=new MailAliasGateway($accounts,$native,$this->createMock(MailAliasBoundary::class),$ops,$states,$sql->connection());
        return [$gateway,$mapper,$alias,$entity,$ops];
    }
    public function testUpdatePreservesSignatureCertificateAndAccountWithoutProjectingThem():void {
        [$g,$m,$a]=$this->aliasCase();$args=['account_id'=>4,'alias_id'=>3,'changes'=>['name'=>'New']];
        $prepared=$g->prepare('alice',$args,'update');$g->apply('alice',$args,'update');
        self::assertSame('New',$a->getName());self::assertSame('old@example.test',$a->getAlias());
        self::assertSame('Alias signature',$a->getSignature());self::assertSame(77,$a->getSmimeCertificateId());self::assertSame(1,$a->getSignatureMode());self::assertSame(4,$a->getAccountId());
        self::assertStringNotContainsString('Alias signature',json_encode($prepared));self::assertStringNotContainsString('smime',json_encode($prepared));self::assertSame(1,$m->writes);
    }
    public function testProvisionedForeignUnknownFieldsAndReferencedDraftsAreRefusedBeforeDelete():void {
        [$g,$m,$a,$account,$ops]=$this->aliasCase();$args=['account_id'=>4,'alias_id'=>3];
        foreach(['foreign','provisioned','unknown','draft','uncertain_delete'] as $case){
            $account->setProvisioningId(null);$m->messages=[];$who='alice';$input=$args;$action='delete';
            if($case==='foreign')$who='bob';
            if($case==='provisioned')$account->setProvisioningId(2);
            if($case==='unknown'){$input['changes']=['smime_certificate_id'=>null];$action='update';}
            if($case==='draft')$m->messages=[new SettingsNativeEntity(['aliasId'=>3,'accountId'=>4])];
            if($case==='uncertain_delete')$ops->reserve(['scope_key'=>'legacy','actor_uid'=>'alice','operation_id'=>'uncertain-delete','tool_name'=>'mail_delete_draft','effect_digest'=>'effect','target_key'=>'held','state'=>'uncertain','resource_refs'=>[],'created_at'=>1,'updated_at'=>1]);
            $refused=false;try{$g->prepare($who,$input,$action);}catch(\Throwable){$refused=true;}
            self::assertTrue($refused,$case);self::assertSame(0,$m->writes);
        }
    }
    public function testCreationNeverAdoptsAnExistingAddressAsItsOwnSuccessfulEffect():void {
        [$g,$m]=$this->aliasCase();
        $args=['account_id'=>4,'name'=>'New alias','email'=>'new@example.test'];$g->prepare('alice',$args,'add');
        $id=$g->apply('alice',$args,'add');self::assertSame(9,$id);self::assertSame('new@example.test',$m->rows[9]->getAlias());
        $this->expectException(BeforeEffectFailure::class);$g->prepare('alice',$args,'add');
    }
    public function testCreationAtTheInventoryLimitCannotMakeTheAliasInventoryUnreadable():void {
        [$g,$m]=$this->aliasCase();
        for($i=1;$i<=1000;$i++)$m->rows[$i]=new SettingsNativeEntity(['id'=>$i,'accountId'=>4,'name'=>'Alias','alias'=>'item-'.$i.'@example.test']);
        $refused=false;try{$g->prepare('alice',['account_id'=>4,'name'=>'Overflow','email'=>'overflow@example.test'],'add');}catch(BeforeEffectFailure){$refused=true;}
        self::assertTrue($refused,'Creation must refuse before crossing the 1000-alias bound');
        self::assertSame(1,$g->prepare('alice',['account_id'=>4,'alias_id'=>1],'delete')['alias_id']);
    }

}
