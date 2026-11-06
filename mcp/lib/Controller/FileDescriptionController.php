<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Controller;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCA\Mcp\Modules\Files\Descriptions\DescriptionService;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

final class FileDescriptionController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private IUserSession $userSession,
        private DescriptionService $descriptions,
        private GrantPolicy $policy,
        private ToolRegistry $tools,
        private FilesAccessGuard $visibility,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    public function show(string $path): DataResponse {
        $user = $this->userSession->getUser();
        if ($user === null) return new DataResponse(['error' => Translator::t('Authentication required.')], Http::STATUS_UNAUTHORIZED);
        try {
            return $this->policy->within($user->getUID(), function () use ($user, $path): DataResponse {
                // The sidebar is a native Files surface, not an MCP connection.
                if (!$this->tools->allowsToolAdministratively($user->getUID(), 'files_get_image_description')) return new DataResponse(['error' => Translator::t('This action is not available.')], Http::STATUS_FORBIDDEN);
                $this->visibility->assertScopeReadable();
                $record = NodeAccess::run(fn (): array => $this->descriptions->read($user->getUID(), $path, $this->visibility));
                $record['can_edit'] = $this->tools->allowsToolAdministratively($user->getUID(), 'files_set_image_description')
                    && ($record['access']['permissions']['update'] ?? false) === true;
                return new DataResponse($record);
            });
        } catch (ToolFailure|\OCA\Mcp\Access\Policy\PolicyUnavailable) {
            return new DataResponse(['error' => Translator::t('This file is unavailable.')], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException) {
            return new DataResponse(['error' => Translator::t('The file path is invalid.')], Http::STATUS_BAD_REQUEST);
        }
    }

    #[NoAdminRequired]
    public function update(string $path, string $description, string $state): DataResponse {
        $user = $this->userSession->getUser();
        if ($user === null) return new DataResponse(['error' => Translator::t('Authentication required.')], Http::STATUS_UNAUTHORIZED);
        try {
            return $this->policy->within($user->getUID(), function () use ($user, $path, $description, $state): DataResponse {
                if (!$this->tools->allowsToolAdministratively($user->getUID(), 'files_set_image_description')) return new DataResponse(['error' => Translator::t('This action is not available.')], Http::STATUS_FORBIDDEN);
                $this->visibility->assertScopeReadable();
                return new DataResponse(NodeAccess::run(fn (): array => $this->descriptions->write($user->getUID(), $path, $description, $state, $this->visibility, true)));
            });
        } catch (ToolFailure $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
        } catch (\OCA\Mcp\Access\Policy\PolicyUnavailable) {
            return new DataResponse(['error' => Translator::t('This file is unavailable.')], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException) {
            return new DataResponse(['error' => Translator::t('The description or file path is invalid.')], Http::STATUS_BAD_REQUEST);
        }
    }
}
