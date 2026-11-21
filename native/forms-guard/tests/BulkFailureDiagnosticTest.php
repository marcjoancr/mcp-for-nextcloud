<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use OCA\Forms\Mcp\Service\BulkOperations;
require_once __DIR__.'/../src/Service/AuthoringScale.php';
require_once getenv('FORMS_BULK_OPERATIONS_SOURCE') ?: __DIR__.'/../src/Service/BulkOperations.php';

final class BulkFailureDiagnosticTest extends TestCase {
    private function error(string $secret,int $depth=0,?Throwable $previous=null):Throwable {
        return $depth>0?$this->error($secret,$depth-1,$previous):new RuntimeException($secret,0,$previous);
    }
    private function diagnostic(Throwable $error):array {
        $method=new ReflectionMethod(BulkOperations::class,'failureDiagnostic');
        return $method->invoke(null,$error);
    }
    public function testMessagesArgumentsAndAbsolutePathsAreNeverRecorded():void {
        $secret='secret-token-/private/customer';$diagnostic=$this->diagnostic($this->error($secret,20));
        $encoded=json_encode($diagnostic,JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($secret,$encoded);self::assertStringNotContainsString('/private/',$encoded);
        self::assertStringNotContainsString(__DIR__,$encoded);self::assertArrayNotHasKey('message',$diagnostic);
        self::assertSame('RuntimeException',$diagnostic['error_class']);self::assertSame(12,count($diagnostic['trace']));
        foreach($diagnostic['trace']as$frame){self::assertArrayNotHasKey('args',$frame);self::assertSame([],array_diff(array_keys($frame),['class','function','public_file','line']));}
    }
    public function testPreviousExceptionsAreBoundedAndContainOnlyLocations():void {
        $error=null;for($i=0;$i<20;$i++)$error=$this->error('secret-token',0,$error);
        $diagnostic=$this->diagnostic($error);self::assertCount(3,$diagnostic['previous']);
        foreach($diagnostic['previous']as$prior)self::assertSame(['error_class','public_file','line'],array_keys($prior));
        self::assertStringNotContainsString('secret-token',json_encode($diagnostic,JSON_THROW_ON_ERROR));
    }
    public function testPublicDtoDoesNotExposeThePrivateDiagnostic():void {
        $method=new ReflectionMethod(BulkOperations::class,'dto');$service=(new ReflectionClass(BulkOperations::class))->newInstanceWithoutConstructor();
        $job=['id'=>str_repeat('a',64),'key'=>str_repeat('b',32),'action'=>'forms_transfer','state'=>'uncertain','phase'=>'stage_shares','version'=>2,'done'=>0,'captured'=>3,'counts'=>['files'=>2,'managed_shares'=>1],'form'=>['id'=>1],'sealed'=>true,'restored_form_id'=>null,'input'=>[],'backup_format'=>1,'private_failure'=>['secret'=>'never-public']];
        $dto=$method->invoke($service,$job);self::assertSame('uncertain',$dto['state']);self::assertFalse($dto['next']);self::assertArrayNotHasKey('private_failure',$dto);self::assertStringNotContainsString('never-public',json_encode($dto,JSON_THROW_ON_ERROR));
    }
}
