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
    $created=0;
    $abort='abort full comment event budget';
    try {
        $fence->transaction(function()use($manager,$form,&$created,$abort) {
            for ($i=0;$i<100;$i++) {
                $comment=$manager->create('users','guard-owner','forms',(string)$form['id']);
                $comment->setMessage('aborted '.$i)->setVerb('comment');
                $manager->save($comment);
                $created++;
            }
            throw new RuntimeException($abort);
        });
    } catch(RuntimeException $error) {if ($error->getMessage()!==$abort) {throw $error;}}
    $checks=['full_budget_captured'=>$created===100,'rollback_no_consumers'=>$events===[],
        'rollback_comments_absent'=>(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*comments WHERE object_type = ? AND object_id = ?',['forms',(string)$form['id']])->fetchOne()===0,
        'rollback_effects_absent'=>(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_mcp_effects WHERE kind = ? AND form_id = ?',['comment',$form['id']])->fetchOne()===0];
    $comment=$manager->create('users','guard-owner','forms',(string)$form['id']);
    $comment->setMessage('valid after full rollback')->setVerb('comment');
    $manager->save($comment);
    $checks['same_process_save_succeeds']=$manager->get($comment->getId())->getMessage()==='valid after full rollback';
    $checks['only_valid_event_delivered']=array_column($events,0)===['legacy','typed'] && array_column($events,2)===['valid after full rollback','valid after full rollback'];
    $checks['consumers_after_commit']=array_column($events,3)===[false,false];
    $checks['only_valid_effect_persisted']=array_values($db->executeQuery('SELECT phase FROM *PREFIX*forms_mcp_effects WHERE kind = ? AND form_id = ?',['comment',$form['id']])->fetchFirstColumn())===['delivered'];
    Server::get(\OCA\Forms\Mcp\Comments\CommentsOutbox::class)->drain();
    $checks['drain_does_not_replay']=count($events)===2;
    echo json_encode(['ok'=>!in_array(false,$checks,true),'checks'=>$checks],JSON_THROW_ON_ERROR)."\n";
} catch(Throwable $e) {echo json_encode(['ok'=>false,'error'=>$e->getMessage(),'trace'=>$e->getTraceAsString()]);exit(1);}
