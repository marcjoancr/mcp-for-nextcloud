<?php

/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare (strict_types=1);
define('OC_CONSOLE', true);
require '/var/www/html/lib/base.php';
use OCP\Server;
use OCA\Forms\Mcp\Guard;
use OCA\Forms\Mcp\Service\FormsRevisionFence;
try {
    Server::get(\OCP\App\IAppManager::class)->loadApp('forms');
    $request = json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
    $uid = 'guard-owner';
    Server::get(\OCP\IUserSession::class)->setUser(Server::get(\OCP\IUserManager::class)->get($uid));
    $container = (new \OCA\Forms\AppInfo\Application())->getContainer();
    $api = $container->get(\OCA\Forms\Controller\ApiController::class);
    $guard = $container->get(Guard::class);
    $db = Server::get(\OCP\IDBConnection::class);
    require __DIR__ . '/origin-fixture.php';
    $prepare = function (string $action, array $input) use ($uid, $guard): array {
        $input['_mcp_origin'] = formsNativeOrigin($uid, $action, $input);
        return [$input, $guard->snapshot($uid, $action, $input)];
    };
    $check = function (string $action, array $input) use ($guard, $uid, $prepare): void {
        [$input, $snapshot] = $prepare($action, $input);
        $guard->preflightAnswers($uid, $action, $input, $snapshot['revision']);
    };
    $write = function (string $action, array $input) use ($guard, $uid, $prepare): array {
        [$input, $snapshot] = $prepare($action, $input);
        return $guard->apply($uid, $action, $input, $snapshot['revision'], bin2hex(random_bytes(16)), $snapshot['file_manifest']);
    };
    $state = function (int $id) use ($db): array {
        $tables = ['forms_v2_submissions', 'forms_v2_answers', 'forms_v2_uploaded_files', 'forms_mcp_uploads', 'forms_mcp_operations', 'forms_mcp_effects', 'jobs'];
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = (int) $db->executeQuery('SELECT COUNT(*) FROM *PREFIX*' . $table)->fetchOne();
        }
        $counts['revision'] = Server::get(FormsRevisionFence::class)->revision();
        $counts['form'] = $db->executeQuery('SELECT * FROM *PREFIX*forms_v2_forms WHERE id = ?', [$id])->fetch();
        $counts['uploads'] = $db->executeQuery('SELECT handle_hash,consumed FROM *PREFIX*forms_mcp_uploads WHERE form_id = ? ORDER BY handle_hash', [$id])->fetchAll();
        $root = Server::get(\OCP\Files\IRootFolder::class);
        $path = '/appdata_' . Server::get(\OCP\IConfig::class)->getSystemValueString('instanceid') . '/forms_mcp_recovery';
        $counts['recovery'] = $root->nodeExists($path) ? count($root->get($path)->getDirectoryListing()) : 0;
        return $counts;
    };
    $stage = function (int $id, int $question) use ($guard, $uid, $prepare): string {
        [$input, $snapshot] = $prepare('forms_upload_response_files', ['id' => $id, 'question_id' => $question]);
        $bytes = 'preflight-file';
        $stream = fopen('php://memory', 'w+b');
        fwrite($stream, $bytes);
        rewind($stream);
        try {
            return $guard->apply($uid, 'forms_upload_response_files', $input, $snapshot['revision'], bin2hex(random_bytes(16)), $snapshot['file_manifest'], fn() => $guard->stageFiles($uid, $id, $question, '', [['name' => 'proof.txt', 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'stream' => $stream]]))['data']['files'][0]['handle'];
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    };
    $id = $api->newForm()->getData()['id'];
    $api->updateForm($id, ['submitMultiple' => true, 'allowEditSubmissions' => true, 'confirmationEmailEnabled' => false]);
    $question = function (string $type, ?string $subtype = null, array $settings = []) use ($api, $id): int {
        $legacy = $type === 'datetime';
        $q = $api->newQuestion($id, $legacy ? 'short' : $type, $subtype, $type . ' ' . $subtype)->getData()['id'];
        if ($legacy) {
            $mapper = Server::get(\OCA\Forms\Db\QuestionMapper::class);
            $entity = $mapper->findById($q);
            $entity->setType('datetime');
            $mapper->update($entity);
        }
        if ($settings !== []) {
            $api->updateQuestion($id, $q, ['extraSettings' => $settings]);
        }
        return $q;
    };
    if ($request['case'] === 'matrix') {
        $answers = [];
        $ids = [];
        foreach (['short', 'long', 'color', 'date', 'time', 'datetime', 'dropdown', 'multiple_unique', 'multiple', 'ranking', 'linearscale', 'grid_radio', 'grid_checkbox', 'grid_number', 'file'] as $type) {
            if (str_starts_with($type, 'grid_')) {
                $sub = substr($type, 5);
                $q = $question('grid', $sub);
                $rows = $api->newOption($id, $q, ['R1', 'R2'], 'row')->getData();
                $cols = $api->newOption($id, $q, ['C1', 'C2'], 'column')->getData();
                $answers[$q] = match ($sub) {
                    'radio' => [$rows[0]['id'] => (string) $cols[1]['id']],
                    'checkbox' => [$rows[0]['id'] => [(string) $cols[0]['id'], (string) $cols[1]['id']], $rows[1]['id'] => []],
                    'number' => [$rows[0]['id'] => [$cols[0]['id'] => '0', $cols[1]['id'] => '3.50'], $rows[1]['id'] => [$cols[0]['id'] => '', $cols[1]['id'] => '1e3']],
                };
                if ($sub === 'number') {
                    $api->updateQuestion($id, $q, ['isRequired' => true]);
                    $zero = [$rows[0]['id'] => [$cols[0]['id'] => '0']];
                }
            } else {
                $settings = match ($type) {
                    'date' => ['dateRange' => true, 'dateMin' => '2026-11-01', 'dateMax' => '2026-11-30'],
                    'time' => ['timeRange' => true, 'timeMin' => '09:00', 'timeMax' => '17:00'],
                    'linearscale' => ['optionsLowest' => 0, 'optionsHighest' => 10],
                    default => [],
                };
                $q = $question($type, null, $settings);
                if (in_array($type, ['dropdown', 'multiple_unique', 'multiple', 'ranking'], true)) {
                    $options = $api->newOption($id, $q, ['A', 'B', 'C'])->getData();
                    $values = array_map(static fn($o) => (string) $o['id'], $options);
                    $answers[$q] = match ($type) {
                        'multiple' => [$values[0], $values[2]],
                        'ranking' => array_reverse($values),
                        default => [$values[1]],
                    };
                } else {
                    $answers[$q] = match ($type) {
                        'short' => ['á zero 0'],
                        'long' => ["First line\nSecond line"],
                        'color' => ['#Aa00fF'],
                        'date' => ['2026-11-13', '2026-11-14'],
                        'time' => ['09:30', '16:45'],
                        'datetime' => ['2026-11-13 09:30'],
                        'linearscale' => ['0'],
                        'file' => [['mcpHandle' => $stage($id, $q)]],
                    };
                }
            }
            $ids[$type] = $q;
        }
        $before = $state($id);
        $check('forms_submit_response', ['id' => $id, 'answers' => $answers]);
        $zeros = $answers;
        $zeros[$ids['grid_number']] = $zero;
        $check('forms_submit_response', ['id' => $id, 'answers' => $zeros]);
        $unchanged = $state($id) === $before;
        $saved = $write('forms_submit_response', ['id' => $id, 'answers' => $answers]);
        $rows = $db->executeQuery('SELECT question_id,text,file_id FROM *PREFIX*forms_v2_answers WHERE submission_id = ? ORDER BY id', [$saved['references']['submission_id']])->fetchAll();
        $seen = array_unique(array_column($rows, 'question_id'));
        $result = ['families' => 13, 'questions' => count($ids), 'validated_question_count' => count($seen), 'preview_unchanged' => $unchanged, 'date_values' => array_column(array_filter($rows, fn($r) => (int) $r['question_id'] === $ids['date']), 'text'), 'time_values' => array_column(array_filter($rows, fn($r) => (int) $r['question_id'] === $ids['time']), 'text')];
    } elseif ($request['case'] === 'invalid') {
        $short = $question('short');
        $date = $question('date');
        $rank = $question('ranking');
        $options = $api->newOption($id, $rank, ['A', 'B'])->getData();
        $other = $question('multiple_unique', null, ['allowOtherAnswer' => true]);
        $api->newOption($id, $other, ['Choice']);
        $api->updateQuestion($id, $other, ['isRequired' => true]);
        $base = [$short => ['valid'], $date => ['2026-11-13'], $rank => array_map(fn($o) => (string) $o['id'], $options), $other => ['system-other-answer:Custom']];
        $check('forms_submit_response', ['id' => $id, 'answers' => $base]);
        $before = $state($id);
        $rejected = [];
        foreach (['date' => [$date => ['2026-02-30']], 'ranking' => [$rank => [(string) $options[0]['id']]], 'empty_scalar' => [$short => []], 'required_other' => [$other => ['system-other-answer:']], 'foreign_other' => [$other => ['999999999']]] as $name => $change) {
            $failed = false;
            try {
                $check('forms_submit_response', ['id' => $id, 'answers' => array_replace($base, $change)]);
            } catch (InvalidArgumentException) {
                $failed = true;
            }
            $rejected[$name] = $failed;
        }
        $result = ['rejected' => $rejected, 'unchanged' => $state($id) === $before];
    } elseif ($request['case'] === 'files') {
        $q = $question('file');
        $api->updateQuestion($id, $q, ['isRequired' => true]);
        $handle = $stage($id, $q);
        $input = ['id' => $id, 'answers' => [$q => [['mcpHandle' => $handle]]]];
        $before = $state($id);
        for ($i = 0; $i < 3; $i++) {
            $check('forms_submit_response', $input);
        }
        $unchanged = $state($id) === $before;
        $saved = $write('forms_submit_response', $input);
        $consumed = (int) $db->executeQuery('SELECT consumed FROM *PREFIX*forms_mcp_uploads WHERE handle_hash = ?', [hash('sha256', $handle)])->fetchOne();
        $usedRejected = false;
        $used = $input + ['submission_id' => $saved['references']['submission_id']];
        $used['_mcp_origin'] = formsNativeOrigin($uid, 'forms_update_own_response', $used);
        try {
            $guard->preflightAnswers($uid, 'forms_update_own_response', $used, Server::get(FormsRevisionFence::class)->revision());
        } catch (RuntimeException) {
            $usedRejected = true;
        }
        $new = $stage($id, $q);
        $replacement = ['id' => $id, 'submission_id' => $saved['references']['submission_id'], 'answers' => [$q => [['mcpHandle' => $new]]]];
        $before = $state($id);
        $check('forms_update_own_response', $replacement);
        $freshUnchanged = $state($id) === $before;
        $result = ['previews_unchanged' => $unchanged, 'consumed_only_on_apply' => $consumed === 1, 'used_rejected' => $usedRejected, 'fresh_update_unchanged' => $freshUnchanged];
    } elseif ($request['case'] === 'limits') {
        $q = $question('short');
        $api->newSubmission($id, [$q => ['original']]);
        $submission = Server::get(\OCA\Forms\Db\SubmissionMapper::class)->findByForm($id)[0]->getId();
        $api->updateForm($id, ['maxSubmissions' => 1]);
        $before = $state($id);
        $capped = false;
        try {
            $check('forms_submit_response', ['id' => $id, 'answers' => [$q => ['new']]]);
        } catch (\OCP\AppFramework\OCS\OCSForbiddenException) {
            $capped = true;
        }
        $check('forms_update_own_response', ['id' => $id, 'submission_id' => $submission, 'answers' => [$q => ['replacement']]]);
        $foreign = false;
        $other = $api->newForm()->getData()['id'];
        $oq = $api->newQuestion($other, 'short', null, 'Other')->getData()['id'];
        $api->newSubmission($other, [$oq => ['other']]);
        $sid = Server::get(\OCA\Forms\Db\SubmissionMapper::class)->findByForm($other)[0]->getId();
        $before = $state($id);
        try {
            $check('forms_update_response', ['id' => $id, 'submission_id' => $sid, 'answers' => [$q => ['wrong']]]);
        } catch (\OCP\AppFramework\OCS\OCSForbiddenException) {
            $foreign = true;
        }
        $result = ['cap_refused' => $capped, 'foreign_response_refused' => $foreign, 'unchanged' => $state($id) === $before];
    } elseif ($request['case'] === 'clone') {
        $reader = 'clone-reader-' . bin2hex(random_bytes(5));
        $user = Server::get(\OCP\IUserManager::class)->createUser($reader, bin2hex(random_bytes(24)));
        $container->get(\OCA\Forms\Controller\ShareApiController::class)->newShare($id, 0, $reader, ['submit']);
        $formsBefore = (int) $db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_v2_forms')->fetchOne();
        $before = $state($id);
        Server::get(\OCP\IUserSession::class)->setUser($user);
        $input = ['id' => $id];
        $input['_mcp_origin'] = formsNativeOrigin($reader, 'forms_clone', $input);
        $readerRefused = false;
        try {
            $guard->snapshot($reader, 'forms_clone', $input);
        } catch (\OCA\Forms\Exception\NoSuchFormException) {
            $readerRefused = true;
        }
        Server::get(\OCP\IUserSession::class)->setUser(Server::get(\OCP\IUserManager::class)->get($uid));
        $config = Server::get(\OCP\IAppConfig::class);
        $old = $config->getValueBool('forms', 'restrictCreation', false);
        $groups = $config->getValueArray('forms', 'creationAllowedGroups', []);
        $ownerRefused = false;
        try {
            $config->setValueBool('forms', 'restrictCreation', true);
            $config->setValueArray('forms', 'creationAllowedGroups', []);
            $input = ['id' => $id];
            $input['_mcp_origin'] = formsNativeOrigin($uid, 'forms_clone', $input);
            try {
                $guard->snapshot($uid, 'forms_clone', $input);
            } catch (RuntimeException) {
                $ownerRefused = true;
            }
        } finally {
            $config->setValueBool('forms', 'restrictCreation', $old);
            $config->setValueArray('forms', 'creationAllowedGroups', $groups);
        }
        $result = ['reader_refused' => $readerRefused, 'creation_refused' => $ownerRefused, 'forms_unchanged' => $formsBefore === (int) $db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_v2_forms')->fetchOne(), 'state_unchanged' => $before === $state($id)];
    } elseif ($request['case'] === 'file_invalid') {
        $q = $question('file');
        $handle = $stage($id, $q);
        $input = ['id' => $id, 'answers' => [$q => [['mcpHandle' => $handle]]]];
        $input['_mcp_origin'] = formsNativeOrigin($uid, 'forms_submit_response', $input);
        $row = $db->executeQuery('SELECT * FROM *PREFIX*forms_mcp_uploads WHERE handle_hash = ?', [hash('sha256', $handle)])->fetch();
        $upload = Server::get(\OCA\Forms\Db\UploadedFileMapper::class)->getByUploadedFileId((string) $row['upload_id']);
        $file = Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($uid)->getById($upload->getFileId())[0];
        $file->putContent('tampered');
        $before = $state($id);
        $tamper = false;
        try {
            $guard->preflightAnswers($uid, 'forms_submit_response', $input, Server::get(FormsRevisionFence::class)->revision());
        } catch (RuntimeException) {
            $tamper = true;
        }
        $unchanged = $before === $state($id);
        $db->executeStatement('UPDATE *PREFIX*forms_mcp_uploads SET expires = 1 WHERE handle_hash = ?', [hash('sha256', $handle)]);
        $before = $state($id);
        $expired = false;
        try {
            $guard->preflightAnswers($uid, 'forms_submit_response', $input, Server::get(FormsRevisionFence::class)->revision());
        } catch (RuntimeException) {
            $expired = true;
        }
        $result = ['tamper_refused' => $tamper, 'expiry_refused' => $expired, 'unchanged' => $unchanged && $before === $state($id)];
    } elseif ($request['case'] === 'unique') {
        $q = $question('short');
        $api->updateForm($id, ['submitMultiple' => false]);
        $other = 'unique-responder-' . bin2hex(random_bytes(5));
        $user = Server::get(\OCP\IUserManager::class)->createUser($other, bin2hex(random_bytes(24)));
        $container->get(\OCA\Forms\Controller\ShareApiController::class)->newShare($id, 0, $other, ['submit']);
        Server::get(\OCP\IUserSession::class)->setUser($user);
        try {
            (new \OCA\Forms\Mcp\Service\ScopedServices($other))->get(\OCA\Forms\Controller\ApiController::class)->newSubmission($id, [$q => ['first']]);
            $input = ['id' => $id, 'answers' => [$q => ['duplicate']]];
            $input['_mcp_origin'] = formsNativeOrigin($other, 'forms_submit_response', $input);
            $before = $state($id);
            $refused = false;
            try {
                $guard->preflightAnswers($other, 'forms_submit_response', $input, Server::get(FormsRevisionFence::class)->revision());
            } catch (\OCP\AppFramework\OCS\OCSForbiddenException) {
                $refused = true;
            }
            $result = ['refused' => $refused, 'unchanged' => $before === $state($id)];
        } finally {
            Server::get(\OCP\IUserSession::class)->setUser(Server::get(\OCP\IUserManager::class)->get($uid));
        }
    } elseif ($request['case'] === 'own_anonymous') {
        $q = $question('short');
        $api->updateForm($id, ['isAnonymous' => true]);
        $api->newSubmission($id, [$q => ['anonymous']]);
        $sid = Server::get(\OCA\Forms\Db\SubmissionMapper::class)->findByForm($id)[0]->getId();
        $input = ['id' => $id, 'submission_id' => $sid, 'answers' => [$q => ['updated']]];
        $before = $state($id);
        $check('forms_update_response', $input);
        $own = false;
        try {
            $check('forms_update_own_response', $input);
        } catch (RuntimeException) {
            $own = true;
        }
        $result = ['own_refused' => $own, 'unchanged' => $before === $state($id)];
    } elseif ($request['case'] === 'stale') {
        $q = $question('short');
        [$input, $snapshot] = $prepare('forms_submit_response', ['id' => $id, 'answers' => [$q => ['value']]]);
        $api->updateQuestion($id, $q, ['isRequired' => true]);
        $before = $state($id);
        $failed = false;
        try {
            $guard->preflightAnswers($uid, 'forms_submit_response', $input, $snapshot['revision']);
        } catch (\OCA\Forms\Mcp\BeforeEffect) {
            $failed = true;
        }
        $result = ['refused' => $failed, 'unchanged' => $state($id) === $before];
    } else {
        throw new RuntimeException('Unknown answer fixture');
    }
    echo json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'class' => $e::class]);
}
