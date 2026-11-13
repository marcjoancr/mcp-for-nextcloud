<?php

/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare (strict_types=1);
define('OC_CONSOLE', true);
require '/var/www/html/lib/base.php';
use OCP\Server;
use OCA\Forms\Db\{FormMapper, Submission, SubmissionMapper, Answer, AnswerMapper, Share, ShareMapper, QuestionMapper};
use OCA\Forms\Mcp\Guard;
use OCA\Forms\Mcp\Service\{ScopedServices, FormsRevisionFence};
try {
    Server::get(\OCP\App\IAppManager::class)->loadApp('forms');
    $request = json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
    $db = Server::get(\OCP\IDBConnection::class);
    $uid = 'guard-owner';
    $actor = function (string $user) use (&$uid): void {
        $uid = $user;
        Server::get(\OCP\IUserSession::class)->setUser(Server::get(\OCP\IUserManager::class)->get($user));
    };
    $actor($uid);
    $container = (new \OCA\Forms\AppInfo\Application())->getContainer();
    $api = $container->get(\OCA\Forms\Controller\ApiController::class);
    $guard = $container->get(Guard::class);
    require __DIR__ . '/origin-fixture.php';
    $id = $api->newForm()->getData()['id'];
    $api->updateForm($id, ['submitMultiple' => true, 'allowEditSubmissions' => true, 'confirmationEmailEnabled' => false]);
    $q = $api->newQuestion($id, 'short', null, 'Visible question')->getData()['id'];
    $row = function (string $user, int $count = 1, ?int $file = null) use ($id, $q): int {
        $s = new Submission();
        $s->setFormId($id);
        $s->setUserId($user);
        $s->setTimestamp(1700000000);
        $s = Server::get(SubmissionMapper::class)->insert($s);
        for ($n = 1; $n <= $count; $n++) {
            $a = new Answer();
            $a->setSubmissionId($s->getId());
            $a->setQuestionId($q);
            $a->setText('value-' . $n);
            $a->setFileId($file);
            Server::get(AnswerMapper::class)->insert($a);
        }
        return $s->getId();
    };
    $share = function (int $type, string $recipient) use ($id): Share {
        $s = new Share();
        $s->setFormId($id);
        $s->setShareType($type);
        $s->setShareWith($recipient);
        $s->setPermissions(['submit']);
        return Server::get(ShareMapper::class)->insert($s);
    };
    $initialized = [];
    $origin = function (string $action, array $input) use (&$uid, &$initialized): array {
        if (!isset($initialized[$uid])) {
            Server::get(\OCP\IConfig::class)->deleteUserValue($uid, 'mcp', 'forms_native_test_ready');
            $initialized[$uid] = true;
        }
        return $input + ['_mcp_origin' => formsNativeOrigin($uid, $action, $input)];
    };
    $read = function (string $action, array $input) use ($guard, &$uid, $origin): array {
        return $guard->read($uid, $action, $origin($action, $input));
    };
    $preview = function (string $action, array $input) use ($guard, &$uid, $origin): array {
        return $guard->snapshot($uid, $action, $origin($action, $input));
    };
    $refused = function (callable $work): bool {
        try {
            $work();
            return false;
        } catch (Throwable) {
            return true;
        }
    };
    $state = function () use ($db): array {
        $r = [];
        foreach (['forms_mcp_operations', 'forms_mcp_uploads', 'forms_mcp_effects', 'forms_v2_submissions', 'forms_v2_answers', 'jobs'] as $t) {
            $r[$t] = $db->executeQuery('SELECT COUNT(*) FROM *PREFIX*' . $t)->fetchOne();
        }
        $r['revision'] = Server::get(FormsRevisionFence::class)->revision();
        return $r;
    };
    $case = $request['case'];
    $r = [];
    if ($case === 'prior') {
        $sid = $row('guard-other');
        $foreign = $row('guard-owner');
        $direct = $share(0, 'guard-other');
        Server::get(ShareMapper::class)->delete($direct);
        $actor('guard-other');
        $native = (new ScopedServices($uid))->get(\OCA\Forms\Controller\ApiController::class)->getSubmissions($id)->getData();
        $r['native_prior'] = in_array($sid, array_column($native['submissions'], 'id'), true);
        $p = $read('forms_read_own_responses', ['id' => $id]);
        $r['own_only'] = array_column($p['submissions'], 'id') === [$sid];
        $r['foreign_refused'] = $refused(fn() => $read('forms_read_own_response', ['id' => $id, 'submission_id' => $foreign]));
        foreach (['expired', 'archived'] as $mode) {
            $f = Server::get(FormMapper::class)->findById($id);
            if ($mode === 'expired') {
                $f->setExpires(time() - 10);
            } else {
                $f->setState(2);
            }
            Server::get(FormMapper::class)->update($f);
            $native = (new ScopedServices($uid))->get(\OCA\Forms\Controller\ApiController::class)->getSubmissions($id)->getData();
            $r[$mode] = array_column($read('forms_read_own_responses', ['id' => $id])['submissions'], 'id') === array_column($native['submissions'], 'id') && array_column($native['submissions'], 'id') === [$sid];
        }
    } elseif ($case === 'ownership') {
        $mine = $row('guard-owner');
        $foreign = $row('guard-other');
        $anon = $row('anon-user-fixture');
        $p = $read('forms_read_own_responses', ['id' => $id]);
        $r['owner_filtered'] = array_column($p['submissions'], 'id') === [$mine];
        $r['owner_foreign_refused'] = $refused(fn() => $read('forms_read_own_response', ['id' => $id, 'submission_id' => $foreign]));
        $r['anonymous_refused'] = $refused(fn() => $read('forms_read_own_response', ['id' => $id, 'submission_id' => $anon]));
        $share(0, 'guard-other');
        $actor('guard-other');
        $f = Server::get(SubmissionMapper::class)->findById($foreign);
        Server::get(SubmissionMapper::class)->delete($f);
        $r['native_no_row'] = $refused(fn() => (new ScopedServices($uid))->forms()->getFormIfAllowed($id, 'results'));
        $r['no_row_refused'] = $refused(fn() => $read('forms_read_own_responses', ['id' => $id]));
    } elseif ($case === 'answers') {
        $sid = $row('guard-other', 61);
        $actor('guard-other');
        $a = $read('forms_read_own_response', ['id' => $id, 'submission_id' => $sid]);
        $b = $read('forms_read_own_response', ['id' => $id, 'submission_id' => $sid, 'offset' => 50]);
        $r = ['first' => count($a['answers']), 'second' => count($b['answers']), 'first_page' => $a['_mcp_page'], 'last_page' => $b['_mcp_page'], 'unique' => count(array_unique(array_column(array_merge($a['answers'], $b['answers']), 'id'))), 'ordered' => array_column($a['answers'], 'text') === array_map(fn($n) => 'value-' . $n, range(1, 50))];
        $p = $read('forms_read_own_responses', ['id' => $id]);
        $r['plural_prefix'] = count($p['submissions'][0]['answers']) === 50 && $p['submissions'][0]['_mcp_page']['hasMore'];
    } elseif ($case === 'stable') {
        $ids = [];
        for ($n = 0; $n < 5; $n++) {
            $ids[] = $row('guard-owner');
        }
        $all = [];
        $pages = [];
        foreach ([0, 2, 4] as $offset) {
            $p = $read('forms_read_own_responses', ['id' => $id, 'offset' => $offset, 'limit' => 2]);
            $all = array_merge($all, array_column($p['submissions'], 'id'));
            $pages[] = $p['_mcp_page'];
        }
        $r = ['exact_last_page' => !$read('forms_read_own_responses', ['id' => $id, 'limit' => 5])['_mcp_page']['hasMore'], 'stable' => $all === array_reverse($ids), 'pages' => $pages, 'query' => count($read('forms_read_own_responses', ['id' => $id, 'query' => 'value-1'])['submissions']) === 5];
    } elseif ($case === 'files') {
        $sid = $row('guard-other', 101, 123);
        $foreign = $row('guard-owner', 1, 456);
        $actor('guard-other');
        $answers = Server::get(AnswerMapper::class)->findBySubmission($sid);
        $last = $answers[array_key_last($answers)];
        $last->setFileId(999);
        Server::get(AnswerMapper::class)->update($last);
        $exact = $read('forms_list_response_files', ['id' => $id, 'submission_id' => $sid, '_mcp_file_id' => 999]);
        $a = $read('forms_list_response_files', ['id' => $id, 'submission_id' => $sid, 'limit' => 100]);
        $b = $read('forms_list_response_files', ['id' => $id, 'submission_id' => $sid, 'limit' => 100, 'offset' => 100]);
        $ordinary = $read('forms_list_response_files', ['id' => $id, 'submission_id' => $sid, 'file_id' => 999, 'limit' => 100]);
        $r = ['ordinary_file_id_ignored' => count($ordinary['files']) === 100, 'private_exact' => count($exact['files']) === 1 && $exact['files'][0]['file_id'] === 999, 'first' => count($a['files']), 'last' => count($b['files']), 'first_page' => $a['_mcp_page'], 'last_page' => $b['_mcp_page'], 'own' => array_unique(array_column($a['files'], 'submission_id')) === [$sid], 'foreign_refused' => $refused(fn() => $read('forms_list_response_files', ['id' => $id, 'submission_id' => $foreign]))];
    } elseif ($case === 'public') {
        $choice = $api->newQuestion($id, 'multiple', null, 'Choose')->getData()['id'];
        $api->updateQuestion($id, $choice, ['extraSettings' => ['optionsLimitMin' => 1]]);
        $opts = $api->newOption($id, $choice, ['One', 'Two', 'Three'])->getData();
        $file = $api->newQuestion($id, 'file', null, 'Upload')->getData()['id'];
        $api->updateQuestion($id, $file, ['extraSettings' => ['allowedFileTypes' => ['image'], 'allowedFileExtensions' => ['pdf']]]);
        $deleted = Server::get(QuestionMapper::class)->findById($q);
        $deleted->setOrder(0);
        Server::get(QuestionMapper::class)->update($deleted);
        $otherForm = $api->newForm()->getData()['id'];
        $otherQuestion = $api->newQuestion($otherForm, 'short', null, 'Foreign')->getData()['id'];
        $public = $share(3, 'own-public-' . bin2hex(random_bytes(8)));
        $hash = $public->getShareWith();
        $actor('guard-other');
        $before = $state();
        $snap = $preview('forms_read_submission_form', ['share_hash' => $hash, 'limit' => 1]);
        $a = $read('forms_read_submission_form', ['share_hash' => $hash, 'limit' => 1]);
        $b = $read('forms_read_submission_form', ['share_hash' => $hash, 'offset' => 1, 'limit' => 1]);
        $c = $read('forms_read_submission_form', ['share_hash' => $hash, 'question_id' => $choice, 'limit' => 2]);
        $d = $read('forms_read_submission_form', ['share_hash' => $hash, 'question_id' => $choice, 'offset' => 2, 'limit' => 2]);
        $r = ['questions' => array_column(array_merge($a['questions'], $b['questions']), 'id') === [$choice, $file], 'required' => $a['questions'][0]['isRequired'], 'accept' => $b['questions'][0]['accept'] === ['image/*', '.pdf'], 'options' => array_column(array_merge($c['question']['options'], $d['question']['options']), 'id') === array_column($opts, 'id'), 'more' => $c['_mcp_page']['nextOffset'] === 2 && !$d['_mcp_page']['hasMore'], 'safe' => array_intersect(['ownerId', 'access', 'shares', 'hash', 'filePath', 'confirmationEmailBody'], array_keys($a['form'])) === [], 'unchanged' => $state() === $before, 'revision' => $snap['revision'] === $a['revision']];
        $r['foreign_question_preview_refused'] = $refused(fn() => $preview('forms_read_submission_form', ['share_hash' => $hash, 'question_id' => $otherQuestion]));
        $r['foreign_question_refused'] = $refused(fn() => $read('forms_read_submission_form', ['share_hash' => $hash, 'question_id' => $otherQuestion]));
        $r['deleted_refused'] = $refused(fn() => $read('forms_read_submission_form', ['share_hash' => $hash, 'question_id' => $q]));
        $r['results_refused'] = $refused(fn() => $read('forms_read_response', ['id' => $id, 'submission_id' => 1, 'share_hash' => $hash]));
        $r['edit_refused'] = $refused(fn() => $preview('forms_update', ['id' => $id, 'share_hash' => $hash, 'fields' => ['title' => 'Denied']]));
        $r['comments_refused'] = $refused(fn() => $read('forms_list_comments', ['id' => $id, 'share_hash' => $hash]));
        $r['id_refused'] = $refused(fn() => $read('forms_read_submission_form', ['share_hash' => $hash, 'id' => $id + 1]));
        $f = Server::get(FormMapper::class)->findById($id);
        $f->setExpires(time() - 1);
        Server::get(FormMapper::class)->update($f);
        $r['expired_refused'] = $refused(fn() => $read('forms_read_submission_form', ['share_hash' => $hash, 'offset' => 1]));
        $f->setExpires(0);
        Server::get(FormMapper::class)->update($f);
        Server::get(ShareMapper::class)->delete($public);
        $r['revoked_refused'] = $refused(fn() => $read('forms_read_submission_form', ['share_hash' => $hash]));
    } elseif ($case === 'public_large') {
        for ($n = 1; $n <= 62; $n++) {
            $selected = $api->newQuestion($id, 'dropdown', null, 'Paged ' . $n)->getData()['id'];
        }
        $api->newOption($id, $selected, array_map(fn($n) => 'option-' . $n, range(1, 63)));
        $hash = $share(3, 'own-large-' . bin2hex(random_bytes(8)))->getShareWith();
        $actor('guard-other');
        $native = (new ScopedServices($uid))->forms()->getPublicForm((new ScopedServices($uid))->forms()->loadFormForSubmission($id, $hash));
        $first = $read('forms_read_submission_form', ['share_hash' => $hash]);
        $last = $read('forms_read_submission_form', ['share_hash' => $hash, 'offset' => 50]);
        $a = $read('forms_read_submission_form', ['share_hash' => $hash, 'question_id' => $selected]);
        $b = $read('forms_read_submission_form', ['share_hash' => $hash, 'question_id' => $selected, 'offset' => 50]);
        $r['question_counts'] = count($first['questions']) === 50 && count($last['questions']) === 13;
        $r['question_native_ids'] = array_column(array_merge($first['questions'], $last['questions']), 'id') === array_column($native['questions'], 'id');
        $r['question_continuation'] = $first['_mcp_page']['nextOffset'] === 50 && !$last['_mcp_page']['hasMore'];
        $r['option_counts'] = count($a['question']['options']) === 50 && count($b['question']['options']) === 13;
        $nativeQuestion = array_values(array_filter($native['questions'], fn($question) => $question['id'] === $selected))[0];
        $r['option_native_ids'] = array_column(array_merge($a['question']['options'], $b['question']['options']), 'id') === array_column($nativeQuestion['options'], 'id');
        $r['option_continuation'] = $a['_mcp_page']['nextOffset'] === 50 && !$b['_mcp_page']['hasMore'];
    } elseif ($case === 'workflow') {
        $choice = $api->newQuestion($id, 'multiple', null, 'Choice')->getData()['id'];
        $api->newOption($id, $choice, ['A', 'B']);
        $ranking = $api->newQuestion($id, 'ranking', null, 'Ranking')->getData()['id'];
        $api->newOption($id, $ranking, ['First', 'Second']);
        $grid = $api->newQuestion($id, 'grid', 'radio', 'Grid')->getData()['id'];
        $api->newOption($id, $grid, ['Row'], 'row');
        $api->newOption($id, $grid, ['Column'], 'column');
        $hash = $share(3, 'own-workflow-' . bin2hex(random_bytes(8)))->getShareWith();
        $actor('guard-other');
        $schema = $read('forms_read_submission_form', ['share_hash' => $hash]);
        $answers = [];
        foreach ($schema['questions'] as $question) {
            $options = $read('forms_read_submission_form', ['share_hash' => $hash, 'question_id' => $question['id']])['question']['options'];
            $answers[$question['id']] = match ($question['type']) {
                'short' => ['Discovered form'],
                'multiple' => [(string) $options[0]['id']],
                'ranking' => array_reverse(array_map('strval', array_column($options, 'id'))),
                'grid' => [array_values(array_filter($options, fn($o) => $o['optionType'] === 'row'))[0]['id'] => (string) array_values(array_filter($options, fn($o) => $o['optionType'] === 'column'))[0]['id']],
            };
        }
        $input = $origin('forms_submit_response', ['id' => $schema['form']['id'], 'share_hash' => $hash, 'answers' => $answers]);
        $snapshot = $guard->snapshot($uid, 'forms_submit_response', $input);
        $guard->preflightAnswers($uid, 'forms_submit_response', $input, $snapshot['revision']);
        $saved = $guard->apply($uid, 'forms_submit_response', $input, $snapshot['revision'], bin2hex(random_bytes(16)), $snapshot['file_manifest']);
        $stored = Server::get(AnswerMapper::class)->findBySubmission($saved['references']['submission_id']);
        $storedByQuestion = [];
        foreach ($stored as $answer) {
            $storedByQuestion[$answer->getQuestionId()] = $answer->getText();
        }
        $r['choice_persisted'] = $storedByQuestion[$choice] === 'A';
        $r['ranking_persisted'] = json_decode($storedByQuestion[$ranking], true, 64, JSON_THROW_ON_ERROR) === $answers[$ranking];
        $r['grid_persisted'] = json_decode($storedByQuestion[$grid], true, 64, JSON_THROW_ON_ERROR) === $answers[$grid];
        $r['discovered_types_submit'] = count($stored) === 4 && count(array_unique(array_map(fn($a) => $a->getQuestionId(), $stored))) === 4;
    } elseif ($case === 'bounded') {
        $sid = $row('guard-owner', 61);
        $choice = $api->newQuestion($id, 'dropdown', null, 'Large options')->getData()['id'];
        $largeOptions = $api->newOption($id, $choice, array_map(fn($n) => 'o' . $n, range(1, 105)))->getData();
        $hash = $share(3, 'own-bounds-' . bin2hex(random_bytes(8)))->getShareWith();
        $concrete = Server::get(\OC\DB\Connection::class);
        $logger = new \Doctrine\DBAL\Logging\DebugStack();
        $old = $concrete->getConfiguration()->getSQLLogger();
        $concrete->getConfiguration()->setSQLLogger($logger);
        try {
            foreach ([['forms_read_own_response', ['id' => $id, 'submission_id' => $sid, 'limit' => 1]], ['forms_read_own_responses', ['id' => $id, 'limit' => 1]], ['forms_list_response_files', ['id' => $id, 'submission_id' => $sid, 'limit' => 1]], ['forms_list_response_files', ['id' => $id, 'submission_id' => $sid, 'limit' => 1, '_mcp_file_id' => 999]], ['forms_read_submission_form', ['share_hash' => $hash, 'limit' => 1]], ['forms_read_submission_form', ['share_hash' => $hash, 'question_id' => $choice, 'limit' => 1]]] as [$action, $input]) {
                $signed = $origin($action, $input);
                $logger->queries = [];
                $guard->snapshot($uid, $action, $signed);
                $snapQueries = array_column($logger->queries, 'sql');
                $r[$action . (isset($input['question_id']) ? '_options' : (isset($input['_mcp_file_id']) ? '_exact' : '')) . '_snapshot'] = !array_filter($snapQueries, fn($sql) => preg_match('/FROM\s+"?oc_forms_v2_(questions|options|answers|submissions)"?/i', $sql) && !str_contains($sql, 'COUNT(') && !preg_match('/WHERE\s+"?id"?\s*=/i', $sql));
                $logger->queries = [];
                $guard->read($uid, $action, $signed);
                $queries = array_column($logger->queries, 'sql');
                $scans = array_filter($queries, fn($sql) => preg_match('/FROM\s+"?oc_forms_v2_(questions|options|answers|submissions)"?/i', $sql) && !str_contains($sql, 'COUNT(') && !preg_match('/WHERE\s+"?id"?\s*=/i', $sql));
                $r[$action . (isset($input['question_id']) ? '_options' : (isset($input['_mcp_file_id']) ? '_exact' : '')) . '_bounded'] = $scans !== [] && !array_filter($scans, fn($sql) => !preg_match(isset($input['_mcp_file_id']) ? '/LIMIT\s+1\b/i' : ($action === 'forms_read_own_responses' && str_contains($sql, 'forms_v2_answers') ? '/LIMIT\s+51\b/i' : '/LIMIT\s+2\b/i'), $sql));
            }
        } finally {
            $concrete->getConfiguration()->setSQLLogger($old);
        }
        $first = $read('forms_read_submission_form', ['share_hash' => $hash, 'question_id' => $choice, 'limit' => 100]);
        $last = $read('forms_read_submission_form', ['share_hash' => $hash, 'question_id' => $choice, 'offset' => 100, 'limit' => 100]);
        $r['large_option_ids'] = array_column(array_merge($first['question']['options'], $last['question']['options']), 'id') === array_column($largeOptions, 'id');
        $r['large_option_pages'] = count($first['question']['options']) === 100 && count($last['question']['options']) === 5 && $first['_mcp_page']['nextOffset'] === 100 && !$last['_mcp_page']['hasMore'];
        $r['over_limit'] = $refused(fn() => $read('forms_read_own_response', ['id' => $id, 'submission_id' => $sid, 'limit' => 101]));
        $r['negative_offset'] = $refused(fn() => $read('forms_read_submission_form', ['share_hash' => $hash, 'offset' => -1]));
    } elseif ($case === 'scope') {
        $mine = $row('guard-owner');
        $otherForm = $api->newForm()->getData()['id'];
        $foreign = $row('guard-owner');
        $entity = Server::get(SubmissionMapper::class)->findById($foreign);
        $entity->setFormId($otherForm);
        Server::get(SubmissionMapper::class)->update($entity);
        foreach (['forms_read_own_response', 'forms_list_response_files'] as $action) {
            $input = ['id' => $id, 'submission_id' => $foreign];
            $r[$action . '_read'] = $refused(fn() => $read($action, $input));
            $r[$action . '_preview'] = $refused(fn() => $preview($action, $input));
        }
    } elseif ($case === 'bytes') {
        $sid = $row('guard-owner');
        $answer = Server::get(AnswerMapper::class)->findBySubmission($sid)[0];
        $answer->setText(str_repeat('x', 1048577));
        Server::get(AnswerMapper::class)->update($answer);
        $r['own_byte_refusal'] = $refused(fn() => $read('forms_read_own_response', ['id' => $id, 'submission_id' => $sid]));
        $r['own_snapshot_bounded'] = !$refused(fn() => $preview('forms_read_own_response', ['id' => $id, 'submission_id' => $sid]));
        $question = Server::get(QuestionMapper::class)->findById($q);
        $question->setDescription(str_repeat('q', 1048577));
        Server::get(QuestionMapper::class)->update($question);
        $hash = $share(3, 'own-bytes-' . bin2hex(random_bytes(8)))->getShareWith();
        $r['public_byte_refusal'] = $refused(fn() => $read('forms_read_submission_form', ['share_hash' => $hash, 'limit' => 1]));
        $r['public_snapshot_bounded'] = !$refused(fn() => $preview('forms_read_submission_form', ['share_hash' => $hash, 'limit' => 1]));
    } elseif ($case === 'fresh') {
        $sid = $row('guard-owner');
        $hash = $share(3, 'own-fresh-' . bin2hex(random_bytes(8)))->getShareWith();
        foreach ([['forms_read_own_response', ['id' => $id, 'submission_id' => $sid], 'read_own_responses'], ['forms_read_submission_form', ['share_hash' => $hash], 'read']] as [$action, $input, $grant]) {
            $signed = $origin($action, $input);
            $guard->read($uid, $action, $signed);
            $policy = Server::get(\OCA\Mcp\Access\Grants\GrantPolicy::class);
            $policy->setGrant($uid, 'forms', $grant, false);
            try {
                $before = $state();
                $r[$action . '_read'] = $refused(fn() => $guard->read($uid, $action, $signed));
                $r[$action . '_snapshot'] = $refused(fn() => $guard->snapshot($uid, $action, $signed));
                $r[$action . '_no_effect'] = $before === $state();
            } finally {
                $policy->setGrant($uid, 'forms', $grant, true);
            }
        }
    } elseif ($case === 'edit') {
        $sid = $row('guard-other');
        $actor('guard-other');
        $input = ['id' => $id, 'submission_id' => $sid];
        $r['delete_allowed'] = !$refused(fn() => $preview('forms_delete_own_response', $input));
        $r['update_direct_required'] = $refused(fn() => $guard->preflightAnswers($uid, 'forms_update_own_response', $origin('forms_update_own_response', $input + ['answers' => [$q => ['x']]]), Server::get(FormsRevisionFence::class)->revision()));
        $f = Server::get(FormMapper::class)->findById($id);
        $f->setAllowEditSubmissions(false);
        Server::get(FormMapper::class)->update($f);
        $r['disabled_refused'] = $refused(fn() => $preview('forms_delete_own_response', $input));
        $f->setAllowEditSubmissions(true);
        $f->setState(2);
        Server::get(FormMapper::class)->update($f);
        $r['archived_refused'] = $refused(fn() => $preview('forms_delete_own_response', $input));
    } else {
        throw new RuntimeException('Unknown own/public fixture');
    }
    echo json_encode(['ok' => true, 'result' => $r], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'class' => $e::class], JSON_THROW_ON_ERROR);
}
