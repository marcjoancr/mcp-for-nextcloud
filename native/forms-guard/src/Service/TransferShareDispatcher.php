<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Share\Events\ShareCreatedEvent;
/** Transfer migrates existing consent; it must not manufacture acceptance of a new share. */
final class TransferShareDispatcher {
 private const ACCEPTANCE='OCA\\Files_Sharing\\Listener\\UserShareAcceptanceListener';
 public static function inventory(IEventDispatcher $dispatcher):array {
  foreach(json_decode(file_get_contents(dirname(__DIR__).'/TransferSharingSources.json'),true,16,JSON_THROW_ON_ERROR)as$path=>$hash){$file=\OC::$SERVERROOT.'/'.$path;if(!is_file($file)||is_link($file)||!hash_equals($hash,hash_file('sha256',$file))){throw new \RuntimeException('Unqualified transfer event runtime');}}
  if($dispatcher::class!==\OC\EventDispatcher\EventDispatcher::class||$dispatcher->getSymfonyDispatcher()::class!==\Symfony\Component\EventDispatcher\EventDispatcher::class){throw new \RuntimeException('Unqualified transfer dispatcher');}
  $listeners=$dispatcher->getSymfonyDispatcher()->getListeners(ShareCreatedEvent::class);$matches=[];
  foreach($listeners as$listener){if($listener instanceof \OC\EventDispatcher\ServiceEventListener&&(new \ReflectionProperty($listener,'class'))->getValue($listener)===self::ACCEPTANCE){$matches[]=$listener;}}
  if(count($matches)!==1){throw new \RuntimeException('Native transfer acceptance registration is unavailable or ambiguous');}
  $registered=$matches[0];$service=(new \ReflectionProperty($registered,'service'))->getValue($registered);
  if($service===null){$service=(new \ReflectionProperty($registered,'container'))->getValue($registered)->get(self::ACCEPTANCE);}
  if($service::class!==self::ACCEPTANCE){throw new \RuntimeException('Native transfer acceptance service is aliased');}
  foreach([\OC\EventDispatcher\EventDispatcher::class=>'lib/private/EventDispatcher/EventDispatcher.php',\OC\EventDispatcher\ServiceEventListener::class=>'lib/private/EventDispatcher/ServiceEventListener.php',\Symfony\Component\EventDispatcher\EventDispatcher::class=>'3rdparty/symfony/event-dispatcher/EventDispatcher.php',self::ACCEPTANCE=>'apps/files_sharing/lib/Listener/UserShareAcceptanceListener.php']as$class=>$path){if(realpath((new \ReflectionClass($class))->getFileName())!==realpath(\OC::$SERVERROOT.'/'.$path)){throw new \RuntimeException('Native transfer event class resolves outside qualified source');}}
  // Resolving the stock read-only listener constructor may lazily initialize ordinary
  // core dependencies; take the inventory only after that normal service resolution.
  return [$dispatcher->getSymfonyDispatcher()->getListeners(ShareCreatedEvent::class),$registered];
 }
 public static function scoped(IEventDispatcher $dispatcher,\Closure $authority):IEventDispatcher {
  [$before,$acceptance]=self::inventory($dispatcher);$copy=clone $dispatcher;$native=clone $dispatcher->getSymfonyDispatcher();(new \ReflectionProperty($copy,'dispatcher'))->setValue($copy,$native);
  $priority=$native->getListenerPriority(ShareCreatedEvent::class,$acceptance);$native->removeListener(ShareCreatedEvent::class,$acceptance);
  // Only this operation-local auto-accept handler is replaced. Every other listener,
  // including native restriction and before-create policies, keeps its priority.
  $native->addListener(ShareCreatedEvent::class,static function(ShareCreatedEvent $event)use($authority):void{$share=$event->getShare();if($share->getPermissions()!==0){throw new \RuntimeException('Transfer consent must remain quarantined');}$authority($share,true);},$priority);
  if($copy===$dispatcher||$native===$dispatcher->getSymfonyDispatcher()||$before!==$dispatcher->getSymfonyDispatcher()->getListeners(ShareCreatedEvent::class)){throw new \RuntimeException('Transfer dispatcher isolation failed');}
  return $copy;
 }
}
