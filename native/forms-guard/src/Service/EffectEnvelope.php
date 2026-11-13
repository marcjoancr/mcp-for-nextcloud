<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);

namespace OCA\Forms\Mcp\Service;
use OCP\Server;
/** Immutable encrypted payloads in private appdata; public receipts and job arguments contain references only. */
final class EffectEnvelope {
    public static function put(string $key,array $value): void {
        if (!preg_match('/^[a-f0-9]{64}$/D',$key)) {throw new \InvalidArgumentException('Invalid effect key');}
        $json=json_encode($value,JSON_THROW_ON_ERROR);
        if (strlen($json)>1048576) {throw new \RuntimeException('Native event payload exceeds budget');}
        $app=Server::get(\OCP\Files\AppData\IAppDataFactory::class)->get('forms_mcp_effects');
        try {$app->getFolder($key);throw new \RuntimeException('Effect payload already exists');}catch(\OCP\Files\NotFoundException){}
        $folder=$app->newFolder($key);$encrypted=Server::get(\OCP\Security\ICrypto::class)->encrypt($json);
        $file=$folder->newFile('payload.enc',$encrypted);
        if (!hash_equals(hash('sha256',$encrypted),hash('sha256',$file->getContent()))) {throw new \RuntimeException('Effect payload verification failed');}
    }
    public static function get(string $key): array {
        if (!preg_match('/^[a-f0-9]{64}$/D',$key)) {throw new \InvalidArgumentException('Invalid effect key');}
        $file=Server::get(\OCP\Files\AppData\IAppDataFactory::class)->get('forms_mcp_effects')->getFolder($key)->getFile('payload.enc');
        if ($file->getSize()>2097152) {throw new \RuntimeException('Effect payload exceeds budget');}
        return json_decode(Server::get(\OCP\Security\ICrypto::class)->decrypt($file->getContent()),true,64,JSON_THROW_ON_ERROR);
    }
    public static function digest(int $formId,string $kind,int $entityId,ScopedServices $scope): string {
        $db=Server::get(\OCP\IDBConnection::class);$values=[];
        foreach ([
            ['SELECT * FROM *PREFIX*forms_v2_forms WHERE id = ?',[$formId]],
            ['SELECT * FROM *PREFIX*forms_v2_questions WHERE form_id = ? ORDER BY id LIMIT 1001',[$formId]],
            ['SELECT * FROM *PREFIX*forms_v2_shares WHERE form_id = ? ORDER BY id LIMIT 1001',[$formId]],
            ['SELECT * FROM *PREFIX*forms_v2_options WHERE question_id IN (SELECT id FROM *PREFIX*forms_v2_questions WHERE form_id = ?) ORDER BY id LIMIT 1001',[$formId]],
        ] as [$sql,$args]) {$rows=$db->executeQuery($sql,$args)->fetchAll();if(count($rows)>1000){throw new \RuntimeException('Event dependencies exceed budget');}$values[]=$rows;}
        if ($kind==='submission') {
            $values[]=$db->executeQuery('SELECT * FROM *PREFIX*forms_v2_submissions WHERE id = ? AND form_id = ?',[$entityId,$formId])->fetch();
            $rows=$db->executeQuery('SELECT * FROM *PREFIX*forms_v2_answers WHERE submission_id = ? ORDER BY id LIMIT 1001',[$entityId])->fetchAll();
            if(count($rows)>1000){throw new \RuntimeException('Event answers exceed budget');}$values[]=$rows;
        }
        $values[]=$db->executeQuery('SELECT configkey,configvalue FROM *PREFIX*appconfig WHERE appid = ? ORDER BY configkey',['forms'])->fetchAll();
        foreach (Server::get(\OCA\Forms\Db\ShareMapper::class)->findByForm($formId) as $share) {
            if ($share->getShareType()===7) {throw new \RuntimeException('Deferred Circle event audience is not qualified');}
            if ($share->getShareType()===1) {
                $users=$scope->get(\OCP\IGroupManager::class)->get($share->getShareWith())?->getUsers()??[];
                if(count($users)>1000){throw new \RuntimeException('Native event audience exceeds budget');}
                $ids=array_map(static fn($user)=>$user->getUID(),$users);sort($ids);$values[]=$ids;
            }
        }
        $json=json_encode($values,JSON_THROW_ON_ERROR);if(strlen($json)>1048576){throw new \RuntimeException('Event dependencies exceed budget');}
        return hash_hmac('sha256',$json,Server::get(\OCP\IConfig::class)->getSystemValueString('secret'));
    }
}
