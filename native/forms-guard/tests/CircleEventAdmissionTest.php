<?php
declare(strict_types=1);
namespace OCA\Circles\Service {class FederatedEventService {}}
namespace OCA\Forms\Mcp\Service\CircleDetached {
 final class Lease {public int $checks=0;public bool $revoked=false;public function checkpoint():void{++$this->checks;if($this->revoked)throw new \RuntimeException('revoked');}public function actorUid():string{return 'actor';}}
}
namespace {
 require_once __DIR__.'/../src/Service/CircleDetached/LocalTransport.php';
 use OCA\Forms\Mcp\Service\CircleDetached\{Lease,LocalTransport};
 final class CircleEventAdmissionTest extends \PHPUnit\Framework\TestCase {
  private function fixture():array {
   $t=(new \ReflectionClass(LocalTransport::class))->newInstanceWithoutConstructor();$l=new Lease();$calls=(object)['proof'=>0,'effect'=>0];$envelope=['operation'=>'create','type'=>7,'owner_id'=>'actor','share_owner'=>'target','recipient'=>'circle','file_id'=>1];
   foreach(['lease'=>$l,'intentKey'=>null,'envelope'=>$envelope,'proofBinding'=>['binding'],'assertCurrentEffect'=>static function()use($calls):void{++$calls->effect;},'oneShotProof'=>static function($binding)use($calls,$envelope):array{++$calls->proof;return$envelope;}]as$key=>$value)(new \ReflectionProperty($t,$key))->setValue($t,$value);
   return[$t,$l,$calls];
  }
  private function invoke(LocalTransport $t,string $method,?array $binding=null):void{(new \ReflectionMethod($t,$method))->invoke($t,$binding);}
  public function testReadOnlyAdmissionChecksLeaseAndBindingWithoutRepeatingEffectProof():void {[$t,$l,$c]=$this->fixture();$this->invoke($t,'assertAdmission',['binding']);self::assertSame(1,$l->checks);self::assertSame(0,$c->proof);self::assertSame(0,$c->effect);$this->invoke($t,'assertOneShot',['binding']);self::assertSame(1,$c->proof);self::assertSame(1,$c->effect);}
  public function testAdmissionRejectsChangedBinding():void{[$t]=$this->fixture();$this->expectException(\RuntimeException::class);$this->invoke($t,'assertAdmission',['changed']);}
  public function testAdmissionRejectsRevocation():void{[$t,$l]=$this->fixture();$l->revoked=true;$this->expectException(\RuntimeException::class);$this->invoke($t,'assertAdmission',['binding']);}
  public function testAdmissionRejectsClosedTransport():void{[$t]=$this->fixture();(new \ReflectionProperty($t,'closed'))->setValue($t,true);$this->expectException(\RuntimeException::class);$this->invoke($t,'assertAdmission');}
  public function testEffectProofRemainsFreshAfterAdmission():void{[$t,$l]=$this->fixture();$this->invoke($t,'assertAdmission',['binding']);$l->revoked=true;$this->expectException(\RuntimeException::class);$this->invoke($t,'assertOneShot',['binding']);}
 }
}
