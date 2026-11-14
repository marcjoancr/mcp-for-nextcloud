<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
define('OC_CONSOLE', true);
require '/var/www/html/lib/base.php';
use OCP\Server;
use OCA\Forms\Mcp\Service\FormsRevisionFence;
try {
    Server::get(\OCP\App\IAppManager::class)->loadApp('forms');
    $manager = Server::get(\OCP\Comments\ICommentsManager::class);
    if (!$manager instanceof \OCA\Forms\Mcp\Comments\GuardedCommentsManager) {
        echo json_encode(['ok'=>false,'error'=>'Actual global comments manager is unguarded']);
        exit(1);
    }
    $session=Server::get(\OCP\IUserSession::class);
    $user=Server::get(\OCP\IUserManager::class)->get('guard-owner');
    $session->setUser($user);
    $api=(new \OCA\Forms\AppInfo\Application())->getContainer()->get(\OCA\Forms\Controller\ApiController::class);
    $form=$api->newForm()->getData();
    $api->updateForm($form['id'], ['allowComments'=>true]);
    $db=Server::get(\OCP\IDBConnection::class);
    $fence=Server::get(FormsRevisionFence::class);
    $events=[];
    $legacy=new class($events,$db) implements \OCP\Comments\ICommentsEventHandler {
        public function __construct(public array &$events,private \OCP\IDBConnection $db) {}
        public function handle(\OCP\Comments\CommentsEvent $event) {
            if ($event->getComment()->getObjectType()==='forms') {$this->events[]=['legacy',$event::class,$event->getComment()->getMessage(),$this->db->inTransaction()];}
        }
    };
    $manager->registerEventHandler(fn()=>$legacy);
    foreach ([\OCP\Comments\Events\CommentAddedEvent::class,\OCP\Comments\Events\BeforeCommentUpdatedEvent::class,\OCP\Comments\Events\CommentUpdatedEvent::class,\OCP\Comments\Events\CommentDeletedEvent::class] as $class) {
        Server::get(\OCP\EventDispatcher\IEventDispatcher::class)->addListener($class,function($event)use(&$events,$db) {
            if ($event->getComment()->getObjectType()==='forms') {$events[]=['typed',$event::class,$event->getComment()->getMessage(),$db->inTransaction()];}
        });
    }
    $comment=$manager->create('users','guard-owner','forms',(string)$form['id']);
    $comment->setMessage('first')->setVerb('comment');
    $inside=[];
    $fence->transaction(function()use($manager,$comment,&$events,&$inside) {
        $manager->save($comment);
        $inside=$events;
        $comment->setMessage('mutable caller changed');
    });
    $checks=['no_precommit_consumers'=>$inside===[], 'frozen_add'=>$events[0][2]==='first' && $events[1][2]==='first','legacy_then_typed'=>array_column($events,0)===['legacy','typed'],'postcommit'=>array_column($events,3)===[false,false]];
    $events=[];
    $comment=$manager->get($comment->getId());
    $comment->setMessage('second');
    $manager->save($comment);
    $checks['frozen_update']=array_column($events,2)===['first','first','second','second'];
    $events=[];
    try {$fence->transaction(function()use($manager,$comment) {$comment->setMessage('rollback');$manager->save($comment);$manager->get($comment->getId());throw new RuntimeException('rollback');});}catch(RuntimeException){}
    $checks['rollback_no_delivery']=$events===[];
    $checks['rollback_cache']=$manager->get($comment->getId())->getMessage()==='second';
    $rolledAdd=$manager->create('users','guard-owner','forms',(string)$form['id']);$rolledAdd->setMessage('aborted insert')->setVerb('comment');
    try {$fence->transaction(function()use($manager,$rolledAdd){$manager->save($rolledAdd);$manager->get($rolledAdd->getId());throw new RuntimeException('abort insert');});}catch(RuntimeException){}
    $missing=false;try {$manager->get($rolledAdd->getId());}catch(\OCP\Comments\NotFoundException){$missing=true;}
    $checks['rollback_insert_cache_absent']=$missing && $events===[];
    try {$fence->transaction(function()use($manager,$comment){$manager->delete($comment->getId());throw new RuntimeException('abort delete');});}catch(RuntimeException){}
    $checks['rollback_delete_cache_restored']=$manager->get($comment->getId())->getMessage()==='second' && $events===[];
    $manager->delete($comment->getId());
    $checks['deleted_frozen']=array_column($events,2)===['second','second'];
    $checks['comment_capability']=(Server::get(\OCA\Forms\Mcp\Guard::class)->capabilities()['comments']??false)===true;
    $db->beginTransaction();
    try {$db->executeStatement('DELETE FROM *PREFIX*migrations WHERE app = ? AND version = ?',['forms','050402Date20261113070000']);$missingSchema=Server::get(\OCA\Forms\Mcp\Guard::class)->capabilities();}
    finally {$db->rollBack();}
    $checks['optional_schema_preserves_other_capabilities']=$missingSchema['qualified'] && $missingSchema['linked_exports'] && !$missingSchema['comments'];

    echo json_encode(['ok'=>!in_array(false,$checks,true),'checks'=>$checks],JSON_THROW_ON_ERROR)."\n";
} catch(Throwable $e) {echo json_encode(['ok'=>false,'error'=>$e->getMessage(),'trace'=>$e->getTraceAsString()]);exit(1);}
