<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
/** Private one-call input to the pinned native duplicate algorithms. Never a registered provider. */
final class FinalShareReadProvider extends \OC\Share20\DefaultShareProvider {
    private bool $used=false;
    public function __construct(private readonly \OCP\Files\Node $node,private array $shares,private readonly \Closure $frame) {}
    public function getSharesByPath(\OCP\Files\Node $path) {
        if ($this->used || $path!==$this->node) {throw new \RuntimeException('Final sharing duplicate projection escaped');}
        $this->used=true;($this->frame)();return $this->shares;
    }
    public function close():void {$this->used=true;$this->shares=[];}
}
