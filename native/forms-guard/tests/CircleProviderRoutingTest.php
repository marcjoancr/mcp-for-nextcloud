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
        self::assertStringContainsString('CircleRuntime::provider($uid,$beforeWrite,$circleIntentKey)',$manager);
        self::assertStringContainsString('Closure|CircleBoundaryProvider|null $circles',$factory);
    }
    public function testCircleProviderUsesManagedBoundaryForNativeMutations():void {
        $provider=file_get_contents(dirname(__DIR__).'/src/Service/CircleBoundaryProvider.php');$writes=file_get_contents(dirname(__DIR__).'/src/Service/CircleNativeShareWrapperService.php');$runtime=file_get_contents(dirname(__DIR__).'/src/Service/CircleRuntime.php');
        foreach(['$native->create($share)','$native->update($share)','$native->delete($share)'] as $native){self::assertStringContainsString($native,$provider);}
        self::assertSame(4,substr_count($writes,'writeRequest->within('));
        foreach(['$this->writeRequest->save($share)','$this->writeRequest->update($shareWrapper)','$this->writeRequest->updateChildPermissions($shareWrapper)','$this->writeRequest->delete(self::positiveId($shareWrapper->getId()))'] as $sink){self::assertStringContainsString($sink,$writes);}
        self::assertStringContainsString("return 'ocCircleShare'",$provider);self::assertStringContainsString('CircleReadRuntime::withProviderLease',$runtime);self::assertStringContainsString('new \\OCA\\Circles\\ShareByCircleProvider',$runtime);self::assertStringContainsString('WriteShareWrapperRequest',$runtime);self::assertStringContainsString('ScopedFederatedUserService',$runtime);
    }
}
