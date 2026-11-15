<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace McpFormsServerExtension;
/** Typed native lifecycle observer. Intent slots are denial state, never caller authority. */
final class GroupObserver {
    private array $creating=[];
    public function __construct(private readonly \OC\Server $server,private readonly \OC\EventDispatcher\EventDispatcher $dispatcher) {}
    public function __invoke(\OCP\EventDispatcher\Event $event):void {
        GroupBootstrap::assertActive();
        if ($this->server!==\OC::$server || $this->dispatcher!==GroupCoreService::existing($this->server,\OC\EventDispatcher\EventDispatcher::class)) {throw new \RuntimeException('Group observer request changed');}
        $class=$event::class;
        if (!in_array($class,GroupRawRegistry::events(),true)) {throw new \RuntimeException('Group observer event is unqualified');}
        // Exact database-only native writers; LDAP/foreign group backends require separate qualification.
        $manager=GroupCoreService::existing($this->server,\OC\Group\Manager::class);
        $backends=(new \ReflectionProperty($manager,'backends'))->getValue($manager);
        if (count($backends)!==1 || reset($backends)::class!==\OC\Group\Database::class) {throw new \RuntimeException('Group observer backend is unqualified');}
        $name=null;
        if ($class===\OCP\Group\Events\BeforeGroupCreatedEvent::class) {$name=$event->getName();$gid=GroupIdentity::creation(reset($backends),$name);}
        else {$group=$event->getGroup();if($group::class!==\OC\Group\Group::class)throw new \RuntimeException('Group observer group is unqualified');$gid=$group->getGID();}
        $key=hash('sha256',$gid);$token=bin2hex(random_bytes(32));$intent=null;
        if ($class===\OCP\Group\Events\BeforeGroupCreatedEvent::class) {
            if (isset($this->creating[$key]) || count($this->creating)>=1000) {throw new \RuntimeException('Group observer creation intent is unavailable');}
            // Claim before all work. Failure intentionally leaves a non-replayable tombstone.
            $this->creating[$key]=['name'=>$name,'gid'=>$gid,'generation'=>$token,'epoch'=>null];
        } elseif ($class===\OCP\Group\Events\GroupCreatedEvent::class) {
            $intent=$this->creating[$key]??null;unset($this->creating[$key]);
            if ($intent===null || $intent['gid']!==$gid || !is_string($intent['name']) || GroupIdentity::creation(reset($backends),$intent['name'])!==$gid || !is_string($intent['epoch'])) {throw new \RuntimeException('Group observer creation intent changed');}
            $token=$intent['generation'];
        } else {unset($this->creating[$key]);}
        $db=$this->server->get(\OCP\IDBConnection::class);
        $epoch=GroupLease::run($db,static function()use($db,$gid,$class,$token,$intent):string {
            if ($class!==\OCP\Group\Events\BeforeGroupCreatedEvent::class) {
                $canonical=GroupIdentity::existing($db,$gid);
                if ($class===\OCP\Group\Events\GroupCreatedEvent::class && $canonical!==$gid) {throw new \RuntimeException('Group creation canonical row changed');}
                $gid=$canonical;
            }
            $epoch=GroupWitnessStore::epoch($db);$prior=GroupWitnessStore::read($db,$gid);
            if ($intent!==null && $intent['epoch']!==$epoch) {throw new \RuntimeException('Group observer creation epoch changed');}
            $row=match($class) {
                \OCP\Group\Events\BeforeGroupCreatedEvent::class=>GroupWitnessState::beforeCreate($prior,$gid,$epoch,$token),
                \OCP\Group\Events\BeforeGroupDeletedEvent::class=>GroupWitnessState::beforeDelete($prior,$gid,$epoch,$token),
                \OCP\Group\Events\GroupCreatedEvent::class=>GroupWitnessState::afterCreate($prior,$gid,$epoch,$token),
            };
            GroupWitnessStore::write($db,$prior,$row);return $epoch;
        });
        if ($class===\OCP\Group\Events\BeforeGroupCreatedEvent::class) {$this->creating[$key]['epoch']=$epoch;}
    }
}
