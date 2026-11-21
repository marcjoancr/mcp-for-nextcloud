<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Circles\Model {
    class FederatedUser {}
    class ShareWrapper { public function __construct(private string $id='11') {} public function getId():string{return$this->id;} }
}
namespace OCA\Circles\Db {
    class ShareWrapperRequest {
        public array $trace=[];
        public function save(\OCP\Share\IShare $share,int $parentId=0):int {$this->trace[]='sink';return 11;}
        public function getShareById(int $id,?\OCA\Circles\Model\FederatedUser $user=null):\OCA\Circles\Model\ShareWrapper {$this->trace[]='read';return new \OCA\Circles\Model\ShareWrapper((string)$id);}
        public function getChild(\OCA\Circles\Model\FederatedUser $user,int $id):\OCA\Circles\Model\ShareWrapper {$this->trace[]='read-child';return new \OCA\Circles\Model\ShareWrapper((string)$id);}
        public function getSharesInFolder(?\OCA\Circles\Model\FederatedUser $user,\OCP\Files\Folder $node,bool $reshares,bool $shallow=true):array{return[];}
    }
}
namespace OCA\Circles\Service {
    class FederatedEventService {}
    class ShareWrapperService {
        private \OCA\Circles\Db\ShareWrapperRequest $shareWrapperRequest;
        public array $trace=[];
        public function __construct(\OCA\Circles\Db\ShareWrapperRequest $request){$this->shareWrapperRequest=$request;}
        public function clearCache(string $id):void {$this->trace[]='clear';}
    }
}
namespace OCA\Circles {
    class ShareByCircleProvider {
        private \OCA\Circles\Service\ShareWrapperService $shareWrapperService;
        public function __construct(\OCA\Circles\Service\ShareWrapperService $service){$this->shareWrapperService=$service;}
        public function create(\OCP\Share\IShare $share):\OCP\Share\IShare {return$share;}
        public function getShareById($id,$recipientId=null):\OCP\Share\IShare {return $this->shareWrapperService->getShareById((int)$id);}
    }
}
namespace {
    use OCA\Forms\Mcp\Service\CircleBoundaryProvider;
    use OCA\Circles\Db\ShareWrapperRequest;
    use OCA\Circles\Service\ShareWrapperService;
    use OCA\Circles\ShareByCircleProvider;
    use PHPUnit\Framework\TestCase;
    require_once dirname(__DIR__,3).'/mcp/vendor/autoload.php';
    foreach(['IShare.php','IShareProvider.php','IPartialShareProvider.php','IShareProviderGetUsers.php','IShareProviderSupportsAllSharesInFolder.php'] as $api){require_once dirname(__DIR__,3).'/mcp/vendor/nextcloud/ocp/OCP/Share/'.$api;}
    require_once dirname(__DIR__).'/src/Service/CircleDetached/ShareWriteBoundary.php';
    require_once dirname(__DIR__).'/src/Service/CircleNativeGraph.php';
    require_once dirname(__DIR__).'/src/Service/CircleNativeShareWrapperService.php';
    require_once dirname(__DIR__).'/src/Service/CircleBoundaryProvider.php';
    require_once dirname(__DIR__).'/src/Service/CircleDetached/LocalTransport.php';
    require_once dirname(__DIR__).'/src/Service/CircleDetached/CircleProviderTransport.php';
    final class CircleNativeProviderEntryTest extends TestCase {
        public function testTransportDeclarationLoadsAtItsPsrPathAndWrapsLocalTransport():void {
            self::assertSame(realpath(dirname(__DIR__).'/src/Service/CircleDetached/CircleProviderTransport.php'),realpath((new \ReflectionClass(\OCA\Forms\Mcp\Service\CircleDetached\CircleProviderTransport::class))->getFileName()));
            self::assertTrue(is_subclass_of(\OCA\Forms\Mcp\Service\CircleDetached\CircleProviderTransport::class,\OCA\Circles\Service\FederatedEventService::class));
            self::assertTrue(is_subclass_of(\OCA\Forms\Mcp\Service\CircleDetached\CircleProviderTransport::class,\OCA\Forms\Mcp\Service\CircleDetached\LocalTransport::class)===false);
        }
        public function testPublicCreateRechecksAuthorityBeforeNativeProviderAndMarksCreatedHere():void {
            $request=new ShareWrapperRequest();$cache=new ShareWrapperService($request);$native=new ShareByCircleProvider($cache);$trace=[];
            $share=$this->createMock(\OCP\Share\IShare::class);
            $share->method('getShareType')->willReturn(7);$share->method('getId')->willReturn(null);$share->method('getSharedWith')->willReturn('circle-id');$share->method('getFullId')->willReturn('ocCircleShare:11');$share->method('getShareOwner')->willReturn('owner');
            $wrapper=(new \ReflectionClass(\OCA\Forms\Mcp\Service\CircleNativeShareWrapperService::class))->newInstanceWithoutConstructor();
            $run=static function($actual,?string $recipient,bool $event,\Closure $work)use($native,$wrapper):mixed{return$work($native,$wrapper);};
            $provider=new CircleBoundaryProvider('owner',$run,function($actual,bool $create)use($share,&$trace):void{self::assertSame($share,$actual);self::assertTrue($create);$trace[]='boundary';},static function()use(&$trace):void{$trace[]='fresh';},str_repeat('a',64));
            $created=$provider->create($share);
            self::assertSame($share,$created);self::assertTrue($provider->createdHere('ocCircleShare:11'));
            self::assertSame(['fresh'],$trace);self::assertSame([],$request->trace);
        }
        public function testNativeRootParentRepresentationsStillRequireTransferCapability():void {
            foreach([null,0]as$parentId){
                $share=$this->createMock(\OCP\Share\IShare::class);$share->method('getShareType')->willReturn(7);$share->method('getId')->willReturn('11');$share->method('getSharedWith')->willReturn('circle-id');$share->method('getParent')->willReturn($parentId);
                $provider=new CircleBoundaryProvider('actor',static function():never{throw new \LogicException('Native provider must not run without capability');},static function():void{},static function():void{},null);
                try{$provider->createTransferChild($share,[],'recipient',static function():void{});self::fail('Missing capability accepted');}catch(\RuntimeException $error){self::assertSame('Native Circle transfer capability is unavailable',$error->getMessage());}
            }
        }
        public function testNonRootParentCannotReachTransferCapability():void {
            foreach([-1,1]as$parentId){
                $share=$this->createMock(\OCP\Share\IShare::class);$share->method('getShareType')->willReturn(7);$share->method('getId')->willReturn('11');$share->method('getSharedWith')->willReturn('circle-id');$share->method('getParent')->willReturn($parentId);
                $provider=new CircleBoundaryProvider('actor',static function():never{throw new \LogicException('Native provider must not run');},static function():void{},static function():void{},null);
                try{$provider->createTransferChild($share,[],'recipient',static function():void{});self::fail('Non-root parent accepted');}catch(\RuntimeException $error){self::assertSame('Native Circle transfer parent is unqualified',$error->getMessage());}
            }
        }
        public function testPublicReadByIdUsesNativeReadAndChecksProviderIdentity():void {
            $request=new ShareWrapperRequest();$cache=new ShareWrapperService($request);$native=new ShareByCircleProvider($cache);$share=$this->createMock(\OCP\Share\IShare::class);
            $share->method('getShareType')->willReturn(7);$share->method('getId')->willReturn('11');$share->method('getSharedWith')->willReturn('circle-id');$share->method('getShareOwner')->willReturn('owner');
            $native=new class($cache,$share) extends ShareByCircleProvider { public function __construct($cache,private $share){parent::__construct($cache);} public function getShareById($id,$recipientId=null):\OCP\Share\IShare{return$this->share;} };
            $wrapper=(new \ReflectionClass(\OCA\Forms\Mcp\Service\CircleNativeShareWrapperService::class))->newInstanceWithoutConstructor();
            $run=static function($actual,?string $recipient,bool $event,\Closure $work)use($native,$wrapper):mixed{return$work($native,$wrapper);};
            $provider=new CircleBoundaryProvider('owner',$run,static function():void{},static function():void{},str_repeat('a',64));
            self::assertSame($share,$provider->getShareById('11'));
        }
    }
}
