<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
use OCP\Interaction\{RestrictInteractionEvent,InteractionReceiver};
use OCP\Interaction\Actions\ShareAction;
use OCP\Interaction\Resources\NodeResource;
/** Request-local native read queries. No dispatcher, hook, audit or provider operation. */
final class FinalSharePolicy {
    private bool $used=false;
    private bool $closed=false;
    private function __construct(
        private readonly \OCP\IUser $subject,
        private readonly InteractionReceiver $receiver,
        private readonly int $nodeId,
        private readonly array $resourceHandlers,
        private readonly \Closure $frame
    ) {}
    /** Caller must supply the freshly qualified graph, inside the joint may-wait phase. */
    public static function collect(\OCP\IUser $subject,InteractionReceiver $receiver,int $nodeId,
        \OC\Core\Listener\RestrictInteractionListener $core,array $resourceHandlers,\Closure $frame):self {
        $frame();
        // Resolve the pure DTO declaration in collect, never via autoload after the final SQL snapshot.
        if(!class_exists(NodeResource::class))throw new \RuntimeException('Final sharing resource DTO is unavailable');
        if ($nodeId<1 || $subject->getUID()==='' || !in_array($receiver::class,[\OCP\Interaction\Receivers\UserReceiver::class,\OCP\Interaction\Receivers\GroupReceiver::class],true)) {throw new \RuntimeException('Final sharing policy tuple is invalid');}
        if (count($resourceHandlers)!==2 || array_diff(array_map(static fn(object $h):string=>$h::class,$resourceHandlers),[\OCA\Files\Listener\RestrictInteractionListener::class,\OCA\Files_Sharing\Listener\RestrictInteractionListener::class])!==[] || $resourceHandlers[0]::class===$resourceHandlers[1]::class) {throw new \RuntimeException('Final sharing resource listeners are unqualified');}
        // Core's exact handler reads only action/user/receivers. No node lookup is needed for this phase.
        $query=new RestrictInteractionEvent($subject->getUID(),$subject,[],new ShareAction(1),[$receiver]);
        $core->handle($query);
        $frame();
        return new self($subject,$receiver,$nodeId,$resourceHandlers,$frame);
    }
    /** Called ONCE from the joint final resolver after complete native view renewal. */
    public function resolve(\OCP\Files\Node $node,int $allNativeViewPermissions):void {
        if ($this->used || $this->closed) {throw new \RuntimeException('Final sharing policy escaped or repeated');}
        // Claim before every callback. An exception cannot make this query replayable.
        $this->used=true;
        try {
            ($this->frame)();FinalNativeAcl::initialized($node);
            if (!in_array($node::class,[\OC\Files\Node\File::class,\OC\Files\Node\Folder::class],true)
                || !(new \ReflectionProperty(\OC\Files\Node\Node::class,'fileInfo'))->getValue($node) instanceof \OC\Files\FileInfo
                || $node->getId()!==$this->nodeId || $allNativeViewPermissions<0 || $allNativeViewPermissions>31) {throw new \RuntimeException('Final sharing resource changed');}
            $query=new RestrictInteractionEvent($this->subject->getUID(),$this->subject,
                [new NodeResource($this->nodeId,$this->subject->getUID(),$node,$allNativeViewPermissions)],new ShareAction(1),[$this->receiver]);
            foreach ($this->resourceHandlers as $handler) {$handler->handle($query);}
            // frame is deliberately a synchronous identity/deadline assertion here, not a policy reader.
            ($this->frame)();
        } finally {$this->closed=true;}
    }
    public function close():void {$this->closed=true;}
}
