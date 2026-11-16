<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
// Public interfaces are complete pinned sources. No Core/server/app bootstrap is loaded.
spl_autoload_register(static function(string $class):void {
    if (str_starts_with($class,'OCP\\')) {$file=dirname(__DIR__).'/references/lib__public__'.str_replace('\\','__',substr($class,4)).'.php';if(is_file($file))require_once $file;}
});
// Constant-only fixtures, not substitutes used by the proposal/runtime.
eval('namespace OC\\Core\\AppInfo; final class Application {const APP_ID="core";}');
eval('namespace OCA\\Files\\AppInfo; final class Application {const APP_ID="files";}');
eval('namespace OCA\\Files_Sharing\\AppInfo; final class Application {const APP_ID="files_sharing";}');
eval('namespace OCP; final class Server {public static function get(string $id):never {throw new \\LogicException("Global lookup trap");}}');
foreach (['core__Listener__RestrictInteractionListener.php','apps__files__lib__Listener__RestrictInteractionListener.php','apps__files_sharing__lib__Listener__RestrictInteractionListener.php'] as $file) {require_once dirname(__DIR__).'/references/'.$file;}
require_once dirname(__DIR__).'/src/FinalSharePolicy.php';
final class NativePolicyTest extends TestCase {
    private function l10n():\OCP\L10N\IFactory {
        $l=$this->createMock(\OCP\IL10N::class);$l->method('t')->willReturnArgument(0);
        $f=$this->createMock(\OCP\L10N\IFactory::class);$f->method('get')->willReturn($l);return $f;
    }
    private function subject():\OCP\IUser {$u=$this->createMock(\OCP\IUser::class);$u->method('getUID')->willReturn('synthetic-subject');return $u;}
    private function manager():\OCP\Share\IManager {$m=$this->createMock(\OCP\Share\IManager::class);$m->method('shareApiEnabled')->willReturn(true);$m->method('allowGroupSharing')->willReturn(true);return $m;}
    public function testNativeCoreRejectsDifferentGroupsUsingCompleteNativeHandler():void {
        $m=$this->manager();$m->method('shareWithGroupMembersOnly')->willReturn(true);$m->method('shareWithGroupMembersOnlyExcludeGroupsList')->willReturn([]);
        $g=$this->createMock(\OCP\IGroupManager::class);$g->method('getUserGroupIds')->willReturnOnConsecutiveCalls(['left'],['right']);
        $handler=new \OC\Core\Listener\RestrictInteractionListener($m,$g,$this->l10n());
        $query=new \OCP\Interaction\RestrictInteractionEvent('synthetic-subject',$this->subject(),[],new \OCP\Interaction\Actions\ShareAction(1),[new \OCP\Interaction\Receivers\UserReceiver('synthetic-recipient',$this->subject())]);
        $this->expectException(\OCP\Interaction\InteractionRestrictedException::class);$this->expectExceptionMessage('Sharing is only allowed with group members.');$handler->handle($query);
    }
    public function testNativeCorePositiveDoesNotResolveRecipientAppsOrGlobalSession():void {
        $m=$this->manager();$g=$this->createMock(\OCP\IGroupManager::class);
        $handler=new \OC\Core\Listener\RestrictInteractionListener($m,$g,$this->l10n());
        $query=new \OCP\Interaction\RestrictInteractionEvent('synthetic-subject',$this->subject(),[],new \OCP\Interaction\Actions\ShareAction(1),[new \OCP\Interaction\Receivers\UserReceiver('synthetic-recipient',$this->subject())]);
        $handler->handle($query);self::assertSame('synthetic-subject',$query->getUser()->getUID());
    }
    public function testPinnedNativeCircleReceiverIsAcceptedWithoutUserOrGroupSubstitution():void {
        $receiver=new \OCP\Interaction\Receivers\CircleReceiver('Abcdef0123456789012345678901234');
        $m=$this->manager();$groups=$this->createMock(\OCP\IGroupManager::class);$l10n=$this->l10n();
        $core=new \OC\Core\Listener\RestrictInteractionListener($m,$groups,$l10n);
        $root=$this->createMock(\OCP\Files\IRootFolder::class);
        $files=new \OCA\Files\Listener\RestrictInteractionListener($l10n);
        $sharing=new \OCA\Files_Sharing\Listener\RestrictInteractionListener($root,$m,$l10n);
        $policy=\OCA\Forms\Mcp\Service\FinalSharePolicy::collect($this->subject(),$receiver,2,$core,[$files,$sharing],static function():void{});
        self::assertInstanceOf(\OCP\Interaction\Receivers\CircleReceiver::class,$receiver);
        self::assertSame('Abcdef0123456789012345678901234',$receiver->getID());
        $policy->close();
    }
    public function testNativeResourceHandlersUseInjectedUnionWithoutGlobalLookup():void {
        $node=$this->createMock(\OCP\Files\Folder::class);$node->method('getId')->willReturn(2);
        $home=$this->createMock(\OCP\Files\Folder::class);$home->method('getId')->willReturn(1);
        $root=$this->createMock(\OCP\Files\IRootFolder::class);$root->method('getUserFolder')->with('synthetic-subject')->willReturn($home);
        $query=new \OCP\Interaction\RestrictInteractionEvent('synthetic-subject',$this->subject(),[new \OCP\Interaction\Resources\NodeResource(2,'synthetic-subject',$node,17)],new \OCP\Interaction\Actions\ShareAction(1),[new \OCP\Interaction\Receivers\UserReceiver('recipient',$this->subject())]);
        (new \OCA\Files\Listener\RestrictInteractionListener($this->l10n()))->handle($query);
        (new \OCA\Files_Sharing\Listener\RestrictInteractionListener($root,$this->manager(),$this->l10n()))->handle($query);
        self::assertSame(17,$query->resources[0]->getNodePermissions());
    }
    public function testMissingSharePermissionIsNotHiddenByReadPermission():void {
        $node=$this->createMock(\OCP\Files\Folder::class);$node->method('getName')->willReturn('synthetic');
        $root=$this->createMock(\OCP\Files\IRootFolder::class);$root->expects(self::never())->method('getUserFolder');
        $query=new \OCP\Interaction\RestrictInteractionEvent('synthetic-subject',$this->subject(),[new \OCP\Interaction\Resources\NodeResource(2,'synthetic-subject',$node,1)],new \OCP\Interaction\Actions\ShareAction(1),[]);
        $this->expectException(\OCP\Interaction\InteractionRestrictedException::class);$this->expectExceptionMessage('Node is not shareable.');
        (new \OCA\Files_Sharing\Listener\RestrictInteractionListener($root,$this->manager(),$this->l10n()))->handle($query);
    }
}
