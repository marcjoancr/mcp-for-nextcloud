<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
use OCP\Files\Folder;
use OCP\Share\IShare;
/** Qualified Forms transfer: source initiator policy and destination owner policy both apply. */
final class TransferShareManager extends \OC\Share20\Manager {
 private Folder $transferSource;
 private \Closure $transferAuthority;
 public static function scoped(\OC\Share20\Manager $manager,Folder $source,\Closure $authority):self {
  if($manager::class!==\OC\Share20\Manager::class||!SharingIdentity::verify()){throw new \RuntimeException('Unqualified transfer sharing graph');}
  $copy=(new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
  foreach((new \ReflectionClass(\OC\Share20\Manager::class))->getProperties()as$p){if(!$p->isStatic()&&$p->isInitialized($manager)){$p->setValue($copy,$p->getValue($manager));}}
  $dispatcher=(new \ReflectionProperty(\OC\Share20\Manager::class,'dispatcher'))->getValue($copy);(new \ReflectionProperty(\OC\Share20\Manager::class,'dispatcher'))->setValue($copy,TransferShareDispatcher::scoped($dispatcher,$authority));
  $copy->transferSource=$source;$copy->transferAuthority=$authority;return $copy;
 }
 public function validateTransfer(IShare $share):void {$validation=clone $share;$validation->setPermissions(1);$this->generalChecks($validation);}
 public function validateActivation(IShare $share):void {
  $this->generalChecks($share);if($share->getShareType()===0){parent::userCreateChecks($share);}elseif($share->getShareType()===1){parent::groupCreateChecks($share);}else{throw new \RuntimeException('Unqualified transfer share type');}
  parent::validateExpirationDateInternal($share);parent::pathCreateChecks($share->getNode());($this->transferAuthority)($share,true);
 }
 protected function generalChecks(IShare $share):void {
  ($this->transferAuthority)($share,true);
  // Actual IShare and initiator are unchanged. These two validation objects emit only
  // native permission/restriction checks, never persistence or share-created events.
  $source=clone $share;$source->setNode($this->transferSource);$source->setShareOwner($share->getSharedBy());
  parent::generalChecks($source);
  ($this->transferAuthority)($share,true);
  $target=clone $share;$target->setSharedBy($share->getShareOwner());
  parent::generalChecks($target);
  ($this->transferAuthority)($share,true);
 }
}
