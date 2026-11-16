<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class CircleProviderRoutingTest extends TestCase {
    public function testScopedFactoryRoutesOnlyQualifiedProvider7AndIdentifier():void {
        $factory=file_get_contents(dirname(__DIR__).'/src/Service/SharingProviderFactory.php');
        $manager=file_get_contents(dirname(__DIR__).'/src/Service/ScopedShareManager.php');
        self::assertStringContainsString('$id === \'ocCircleShare\' && $this->circles !== null',$factory);
        self::assertStringContainsString('$shareType === 7 && $this->circles !== null',$factory);
        self::assertStringContainsString('CircleRuntime::provider($uid,$beforeWrite,$circleIntentKey,$circleOneShotProof,$circleWriteCapability)',$manager);
        self::assertStringContainsString('Closure|CircleBoundaryProvider|null $circles',$factory);
    }
    public function testCircleProviderUsesManagedBoundaryForNativeMutations():void {
        $provider=file_get_contents(dirname(__DIR__).'/src/Service/CircleBoundaryProvider.php');$writes=file_get_contents(dirname(__DIR__).'/src/Service/CircleNativeShareWrapperService.php');$runtime=file_get_contents(dirname(__DIR__).'/src/Service/CircleRuntime.php');
        foreach(['$native->create($share)','$native->update($share)','$native->delete($share)'] as $native){self::assertStringContainsString($native,$provider);}
        self::assertSame(4,substr_count($writes,'writeRequest->within('));
        foreach(['$this->writeRequest->save($share)','$this->writeRequest->update($shareWrapper)','$this->writeRequest->updateChildPermissions($shareWrapper)','$this->writeRequest->delete(self::positiveId($shareWrapper->getId()))'] as $sink){self::assertStringContainsString($sink,$writes);}
        self::assertStringContainsString("return 'ocCircleShare'",$provider);self::assertStringContainsString('CircleReadRuntime::withProviderLease',$runtime);self::assertStringContainsString('new \\OCA\\Circles\\ShareByCircleProvider',$runtime);self::assertStringContainsString('WriteShareWrapperRequest',$runtime);self::assertStringContainsString('ScopedFederatedUserService',$runtime);
    }
    public function testManagedTypeSevenCallsiteUsesBoundedNativeParentProjection():void {
        $phase=file_get_contents(dirname(__DIR__).'/src/Service/ManagedSharePhase.php');
        $runtime=file_get_contents(dirname(__DIR__).'/src/Service/CircleRuntime.php');
        $circleBranch=strpos($phase,"if(\$envelope['type']===7)");
        self::assertNotFalse($circleBranch);
        $genericBranch=strpos($phase,"}else{",$circleBranch);
        self::assertNotFalse($genericBranch);
        $circleSource=substr($phase,$circleBranch,$genericBranch-$circleBranch);
        self::assertStringContainsString('CircleRuntime::managedParents($form->getOwnerId(),$folder,1000)',$circleSource);
        self::assertStringContainsString('$manager->getShareById($fullId,onlyValid:false)',$circleSource);
        self::assertStringContainsString('$existing->getParent()!==0',$circleSource);
        self::assertStringContainsString('$existing->getTarget()!==$parent[\'file_target\']',$circleSource);
        self::assertStringNotContainsString('getSharesBy(',$circleSource);
        self::assertStringContainsString('$manager->getSharesBy($form->getOwnerId(), $envelope[\'type\'], $folder, false, -1, 0, false)',$phase);
        self::assertStringContainsString('$lease->shareWrappers()->managedParents($uid,$nodeId,$limit)',$runtime);
        self::assertStringContainsString('$folder->getOwner()?->getUID()!==$uid',$runtime);
    }
}
