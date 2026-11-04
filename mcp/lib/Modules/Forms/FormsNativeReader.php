<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms;

use OCA\Mcp\Access\AppAvailability\AppEnablement;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserManager;

/** Uses the Forms app's own services so its share, group, team, and permit-all ACLs remain authoritative. */
final class FormsNativeReader implements FormsReader, FormsResponseReader {
    private const FORMS_SERVICE = 'OCA\\Forms\\Service\\FormsService';
    private const FORM_MAPPER = 'OCA\\Forms\\Db\\FormMapper';
    private const SUBMIT_PERMISSION = 'submit';
    private const RESULTS_PERMISSION = 'results';
    private const NO_SUCH_FORM = 'OCA\\Forms\\Exception\\NoSuchFormException';

    /**
     * @param \Closure(string): object $service Resolve an optional Forms service lazily, after app availability is checked.
     * @param \Closure(): ?string $sessionUserId Effective Nextcloud user for this request.
     */
    public function __construct(
        private IAppManager $apps,
        private IUserManager $users,
        private \Closure $service,
        private \Closure $sessionUserId,
    ) {
    }

    public function list(string $userId): array {
        $user = $this->assertUser($userId);
        $formsService = ($this->service)(self::FORMS_SERVICE);
        $formMapper = ($this->service)(self::FORM_MAPPER);
        $forms = array_merge(
            $formMapper->findAllByOwnerId($userId),
            $formsService->getSharedForms($user),
        );

        $unique = [];
        foreach ($forms as $form) {
            $unique[$form->getId()] = $form;
        }

        $summaries = [];
        foreach (array_values($unique) as $form) {
            // Avoid Forms' list projection, which includes the opaque share hash and may count submissions.
            $summaries[] = [
                'id' => $form->getId(),
                'title' => $form->getTitle(),
                'permissions' => $formsService->getPermissions($form),
            ];
        }

        return $summaries;
    }

    public function read(string $userId, int $formId): ?array {
        $this->assertUser($userId);
        $formsService = ($this->service)(self::FORMS_SERVICE);
        try {
            $form = $formsService->getFormIfAllowed($formId, self::SUBMIT_PERMISSION);
        } catch (\Throwable $exception) {
            if (is_a($exception, self::NO_SUCH_FORM)) {
                return null;
            }
            throw $exception;
        }

        return $formsService->getForm($form);
    }

    public function readResponses(string $userId, int $formId, int $offset, int $limit): ?array {
        $this->assertUser($userId);
        $offset = max(0, $offset);
        $limit = min(max(1, $limit), FormsOutput::MAX_SUBMISSIONS);
        $formsService = ($this->service)(self::FORMS_SERVICE);
        try {
            // Forms checks current-user result visibility here, including ownership, shares and prior submissions.
            $form = $formsService->getFormIfAllowed($formId, self::RESULTS_PERMISSION);
        } catch (\Throwable $exception) {
            if (is_a($exception, self::NO_SUCH_FORM)) {
                return null;
            }
            throw $exception;
        }

        // Forms' native results check also admits prior submitters who may see only their own row.
        // This tool reads the shared result set, so require its explicit results permission.
        if (!in_array(self::RESULTS_PERMISSION, $formsService->getPermissions($form), true)) {
            return null;
        }

        $questions = $formsService->getQuestions($formId);
        $fileQuestionIds = [];
        foreach ($questions as $question) {
            if (($question['type'] ?? null) === 'file' && is_int($question['id'] ?? null)) {
                $fileQuestionIds[] = $question['id'];
            }
        }

        $submissionService = ($this->service)('OCA\\Forms\\Service\\SubmissionService');
        return [
            'formId' => $formId,
            'isAnonymous' => (bool)$form->getIsAnonymous(),
            'fileQuestionIds' => $fileQuestionIds,
            'submissions' => $submissionService->getSubmissions($formId, null, null, $limit + 1, $offset),
        ];
    }

    private function assertUser(string $userId): IUser {
        if (($this->sessionUserId)() !== $userId) {
            throw new ToolFailure(ToolFailure::FORBIDDEN);
        }
        $user = $this->users->get($userId);
        if ($user === null || !AppEnablement::forUser($this->apps, 'forms', $user)) {
            throw new ToolFailure(ToolFailure::NOT_FOUND);
        }

        return $user;
    }
}
