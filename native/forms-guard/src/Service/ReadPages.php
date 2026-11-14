<?php

/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare (strict_types=1);
namespace OCA\Forms\Mcp\Service;

use OCA\Forms\Db\{Answer, Form, FormMapper, Option, Question, QuestionMapper, ShareMapper, Submission, SubmissionMapper};
use OCP\{IDBConnection, Server};
use OCP\DB\QueryBuilder\IQueryBuilder;
/** Bounded native own-result and public submission-schema reads. No write service is invoked. */
final class ReadPages
{
    public const ACTIONS = ['forms_read_own_responses', 'forms_read_own_response', 'forms_list_response_files', 'forms_read_submission_form'];
    public static function bounds(array $input): array
    {
        $offset = $input['offset'] ?? 0;
        $limit = $input['limit'] ?? 50;
        if (!is_int($offset) || $offset < 0 || $offset > PHP_INT_MAX - 101 || !is_int($limit) || $limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Invalid Forms page bounds');
        }
        return [$offset, $limit];
    }
    public static function publicForm(string $uid, array $input): Form
    {
        $hash = $input['share_hash'] ?? null;
        if (!is_string($hash) || $hash === '' || isset($input['id'])) {
            throw new \InvalidArgumentException('An exact public share hash is required');
        }
        $share = Server::get(ShareMapper::class)->findPublicShareByHash($hash);
        // Resolve the exact live public row first: native direct-access fallback must never authorize a stale hash.
        $form = (new ScopedServices($uid, false, true))->forms()->loadFormForSubmission($share->getFormId(), $hash);
        if (isset($input['question_id'])) {
            $question = Server::get(QuestionMapper::class)->findById((int) $input['question_id']);
            if ($question->getFormId() !== $form->getId() || $question->getOrder() === 0) {
                throw new \RuntimeException('Public question is unavailable');
            }
        }
        return $form;
    }
    public static function submission(int $formId, int $submissionId, ?string $ownUid = null): Submission
    {
        $row = Server::get(SubmissionMapper::class)->findById($submissionId);
        if ($row->getFormId() !== $formId || $ownUid !== null && $row->getUserId() !== $ownUid) {
            throw new \RuntimeException('Response is unavailable');
        }
        return $row;
    }
    public static function metadata(Form $form): array
    {
        return array_intersect_key($form->read(), array_flip(['id', 'title', 'description', 'isAnonymous', 'submitMultiple', 'allowEditSubmissions', 'maxSubmissions', 'showExpiration', 'expires', 'submissionMessage', 'state']));
    }
    public static function read(string $uid, string $action, array $input): array
    {
        [$offset, $limit] = self::bounds($input);
        $id = (int) ($input['id'] ?? 0);
        return match ($action) {
            'forms_read_own_response' => self::response(self::submission($id, (int) $input['submission_id'], $uid), $offset, $limit),
            'forms_read_own_responses' => self::own($uid, $id, $input, $offset, $limit),
            'forms_list_response_files' => self::files($id, (int) $input['submission_id'], $offset, $limit, $input['_mcp_file_id'] ?? null),
            'forms_read_submission_form' => self::publicSchema($uid, $input, $offset, $limit),
            default => throw new \InvalidArgumentException('Unsupported Forms page'),
        };
    }
    public static function bounded(array $result): array
    {
        if (strlen(json_encode($result, JSON_THROW_ON_ERROR)) > 1048576) {
            throw new \RuntimeException('Forms page exceeds projection budget; request a smaller page');
        }
        return $result;
    }
    private static function page(array $rows, int $offset, int $limit): array
    {
        $more = count($rows) > $limit;
        return ['offset' => $offset, 'hasMore' => $more, 'nextOffset' => $more ? $offset + $limit : null];
    }
    private static function rows(string $table, string $column, int $id, int $offset, int $limit, bool $files = false, ?int $exactFile = null): array
    {
        $qb = Server::get(IDBConnection::class)->getQueryBuilder();
        $qb->select('*')->from($table)->where($qb->expr()->eq($column, $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->orderBy('id', 'ASC')->setFirstResult($offset)->setMaxResults($limit + 1);
        if ($files) {
            $qb->andWhere($qb->expr()->isNotNull('file_id'))->andWhere($qb->expr()->gt('file_id', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
        }
        if ($exactFile !== null) {
            $qb->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($exactFile, IQueryBuilder::PARAM_INT)))->setFirstResult(0)->setMaxResults(1);
        }
        $result = $qb->executeQuery();
        try {
            return $result->fetchAll();
        } finally {
            $result->closeCursor();
        }
    }
    private static function response(Submission $submission, int $offset, int $limit): array
    {
        $rows = self::rows('forms_v2_answers', 'submission_id', $submission->getId(), $offset, $limit);
        return self::bounded(HistoricalFiles::markResponse($submission->read() + ['isAnonymous' => (bool) Server::get(FormMapper::class)->findById($submission->getFormId())->getIsAnonymous(), 'answers' => array_map(static fn(array $r): array => Answer::fromRow($r)->read(), array_slice($rows, 0, $limit)), '_mcp_page' => self::page($rows, $offset, $limit)],$submission->getFormId()));
    }
    private static function own(string $uid, int $id, array $input, int $offset, int $limit): array
    {
        $qb = Server::get(IDBConnection::class)->getQueryBuilder();
        $qb->selectDistinct('s.*')->from('forms_v2_submissions', 's')->where($qb->expr()->eq('s.form_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->andWhere($qb->expr()->eq('s.user_id', $qb->createNamedParameter($uid)))->orderBy('s.timestamp', 'DESC')->addOrderBy('s.id', 'DESC')->setFirstResult($offset)->setMaxResults($limit + 1);
        $query = $input['query'] ?? null;
        if ($query !== null && (!is_string($query) || strlen($query) > 4096)) {
            throw new \InvalidArgumentException('Invalid Forms query');
        }
        if ($query !== null && $query !== '') {
            $qb->join('s', 'forms_v2_answers', 'a', $qb->expr()->eq('s.id', 'a.submission_id'))->andWhere($qb->expr()->orX($qb->expr()->iLike('s.user_id', $qb->createNamedParameter('%' . $query . '%')), $qb->expr()->iLike('a.text', $qb->createNamedParameter('%' . $query . '%'))));
        }
        $result = $qb->executeQuery();
        try {
            $rows = $result->fetchAll();
        } finally {
            $result->closeCursor();
        }
        $out = ['isAnonymous' => (bool) Server::get(FormMapper::class)->findById($id)->getIsAnonymous(), 'submissions' => [], '_mcp_page' => self::page($rows, $offset, $limit)];
        foreach (array_slice($rows, 0, $limit) as $row) {
            $out['submissions'][] = self::response(Submission::fromRow($row), 0, 50);
            self::bounded($out);
        }
        return $out;
    }
    private static function files(int $id, int $submissionId, int $offset, int $limit, mixed $exactFile = null): array
    {
        self::submission($id, $submissionId);
        // Private exact selector for the existing save adapter; it never scans earlier public pages.
        if ($exactFile !== null && (!is_int($exactFile) || $exactFile < 1)) {
            throw new \InvalidArgumentException('Invalid Forms file reference');
        }
        $rows = self::rows('forms_v2_answers', 'submission_id', $submissionId, $offset, $limit, true, $exactFile);
        return self::bounded(['files' => array_map(static fn(array $r): array => ['form_id' => $id, 'submission_id' => $submissionId, 'question_id' => (int) $r['question_id'], 'file_id' => (int) $r['file_id']], array_slice($rows, 0, $limit)), '_mcp_page' => self::page($rows, $offset, $limit)]);
    }
    private static function question(array|Question $row): array
    {
        $q = ($row instanceof Question ? $row : Question::fromRow($row))->read();
        $q['accept'] = [];
        if ($q['type'] === 'file') {
            foreach ($q['extraSettings']['allowedFileTypes'] ?? [] as $type) {
                $q['accept'][] = str_contains($type, '/') ? $type : $type . '/*';
            }
            foreach ($q['extraSettings']['allowedFileExtensions'] ?? [] as $extension) {
                $q['accept'][] = '.' . $extension;
            }
        }
        if ($q['type'] === 'multiple' && ($q['extraSettings']['optionsLimitMin'] ?? 0) > 0) {
            $q['isRequired'] = true;
        }
        return $q;
    }
    private static function publicSchema(string $uid, array $input, int $offset, int $limit): array
    {
        $form = self::publicForm($uid, $input);
        $qb = Server::get(IDBConnection::class)->getQueryBuilder();
        $out = ['form' => self::metadata($form)];
        if (isset($input['question_id'])) {
            $question = Server::get(QuestionMapper::class)->findById((int) $input['question_id']);
            if ($question->getFormId() !== $form->getId() || $question->getOrder() === 0) {
                throw new \RuntimeException('Public question is unavailable');
            }
            $qb->select('*')->from('forms_v2_options')->where($qb->expr()->eq('question_id', $qb->createNamedParameter($question->getId(), IQueryBuilder::PARAM_INT)));
            $out['question'] = self::question($question);
        } else {
            $qb->select('*')->from('forms_v2_questions')->where($qb->expr()->eq('form_id', $qb->createNamedParameter($form->getId(), IQueryBuilder::PARAM_INT)))->andWhere($qb->expr()->neq('order', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
        }
        $qb->orderBy('order', 'ASC')->addOrderBy('id', 'ASC')->setFirstResult($offset)->setMaxResults($limit + 1);
        $result = $qb->executeQuery();
        try {
            $rows = $result->fetchAll();
        } finally {
            $result->closeCursor();
        }
        if (isset($out['question'])) {
            $out['question']['options'] = array_map(static fn(array $r): array => Option::fromRow($r)->read(), array_slice($rows, 0, $limit));
        } else {
            $out['questions'] = array_map(self::question(...), array_slice($rows, 0, $limit));
        }
        return self::bounded($out + ['_mcp_page' => self::page($rows, $offset, $limit)]);
    }
}
