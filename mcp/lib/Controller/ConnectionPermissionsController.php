<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Controller;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\Policy\Transactions\PolicyConflict;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCA\Mcp\OAuth\ConnectionToolsConflict;
use OCA\Mcp\OAuth\ConnectionToolsStore;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IL10N;
use OCA\Mcp\L10n\Translator;
use OCP\IUserSession;

/** Only the signed-in owner can inspect or change a connection, with Nextcloud's normal CSRF protection. */
class ConnectionPermissionsController extends Controller {
    public function __construct(string $appName, IRequest $request, private IUserSession $session,
        private ConnectionToolsStore $selections, private ToolRegistry $tools, private GrantPolicy $policy,
        private PolicyTransaction $transaction, private PolicyStateMapper $state, private IL10N $l10n) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    public function index(int $id): JSONResponse {
        $user = $this->session->getUser();
        if (!$user?->isEnabled()) {
            return new JSONResponse(['error' => 'Forbidden'], 403);
        }
        try {
            Translator::use($this->l10n);
            $uid = $user->getUID();
            $revision = $this->state->state()['revision'];
            $selection = $this->selections->get($uid, $id);
            if ($selection === null) {
                return new JSONResponse(['error' => 'Not found'], 404);
            }
            $permitted = $this->policy->within($uid, fn () => $this->approvedTools($uid));
            $available = array_column($permitted, null, 'name');
            $selected = array_fill_keys($selection['tools'], true);
            $rows = [];
            foreach ($this->tools->administrableDefinitions($user) as $tool) {
                $name = $tool['name'];
                $approvedVariants = $available[$name]['approvedVariants'] ?? [];
                $variants = array_values(array_intersect($selection['variants'][$name] ?? [], $approvedVariants));
                $rows[] = array_intersect_key($tool, array_flip(['module', 'name', 'title', 'description'])) + [
                    'available' => isset($available[$name]),
                    'selected' => isset($available[$name], $selected[$name]) && (!isset($tool['grantAnyOf']) || $variants !== []),
                    'approvedVariants' => $approvedVariants,
                    'variants' => $variants,
                ];
            }
            if ($this->state->state()['revision'] !== $revision) {
                throw new PolicyConflict();
            }
            $viewRevision = $revision . ':' . $selection['revision'] . ':' . self::catalogHash(array_column($rows, 'name'));
            return new JSONResponse(['revision' => $viewRevision, 'tools' => $rows]);
        } catch (PolicyConflict) {
            return new JSONResponse(['error' => 'Reload permissions'], 409);
        } catch (PolicyUnavailable) {
            return new JSONResponse(['error' => 'Unavailable'], 503);
        } finally {
            Translator::reset();
        }
    }

    #[NoAdminRequired]
    public function update(int $id): JSONResponse {
        $user = $this->session->getUser();
        if (!$user?->isEnabled()) {
            return new JSONResponse(['error' => 'Forbidden'], 403);
        }
        $names = $this->request->getParam('tools');
        $variants = $this->request->getParam('variants', []);
        $revision = $this->request->getParam('revision');
        if (!is_string($revision) || !preg_match('/^([0-9]{1,20}):([0-9]{1,20})(?::([0-9a-f]{64}))?$/D', $revision, $parts)
            || !is_array($names) || !array_is_list($names) || count($names) > 1000
            || count(array_filter($names, 'is_string')) !== count($names) || !is_array($variants) || count($variants) > 1000) {
            return new JSONResponse(['error' => 'Invalid selection'], 400);
        }
        $uid = $user->getUID();
        try {
            $current = $this->selections->get($uid, $id);
            if ($current === null) {
                return new JSONResponse(['error' => 'Not found'], 404);
            }
            $this->transaction->runAdmin($parts[1], [$uid], function () use ($user, $uid, $names, $variants, $current, $parts): array {
                $catalogNames = array_column($this->tools->administrableDefinitions($user), 'name');
                // A form from before an app change (or before catalog hashes) must reload before replacing its selection.
                if (($parts[3] ?? '') !== self::catalogHash($catalogNames)) {
                    throw new PolicyConflict();
                }
                $available = $this->policy->within($uid, fn () => array_column($this->approvedTools($uid), null, 'name'));
                if (array_diff($names, array_keys($available)) !== [] || array_diff(array_keys($variants), $names) !== []) {
                    throw new \InvalidArgumentException('Tool unavailable');
                }
                $chosen = [];
                foreach ($names as $name) {
                    $approved = $available[$name]['approvedVariants'] ?? [];
                    if ($approved === [] && !isset($variants[$name])) {
                        continue;
                    }
                    $operations = $variants[$name] ?? array_values(array_intersect($current['variants'][$name] ?? [], $approved));
                    if (!is_array($operations) || !array_is_list($operations) || count($operations) > count($approved)
                        || $operations === [] || count(array_filter($operations, 'is_string')) !== count($operations)
                        || array_diff($operations, $approved) !== []) {
                        throw new \InvalidArgumentException('Invalid tool variant');
                    }
                    $chosen[$name] = $operations;
                }
                $hidden = array_diff($current['tools'], $catalogNames);
                return ['tools' => array_merge($names, $hidden), 'variants' => $chosen];
            }, function (array $selection) use ($uid, $id, $parts): bool {
                $this->selections->save($uid, $id, $selection['tools'], $parts[2], $selection['variants']);
                // The selection has its own CAS revision. No administrative rule changed.
                return false;
            });
            return $this->index($id);
        } catch (\OutOfBoundsException) {
            return new JSONResponse(['error' => 'Not found'], 404);
        } catch (ConnectionToolsConflict|PolicyConflict) {
            return new JSONResponse(['error' => 'Reload permissions'], 409);
        } catch (\InvalidArgumentException) {
            return new JSONResponse(['error' => 'Invalid selection'], 400);
        } catch (PolicyUnavailable) {
            return new JSONResponse(['error' => 'Unavailable'], 503);
        }
    }

    private static function catalogHash(array $names): string {
        sort($names, SORT_STRING);
        return hash('sha256', implode("\n", $names));
    }

    private function approvedTools(string $uid): array {
        $tools = $this->tools->permittedDefinitions($uid);
        foreach ($tools as &$tool) {
            if (isset($tool['grantAnyOf'])) {
                $tool['approvedVariants'] = $this->tools->permittedVariants($uid, $tool['name']);
            }
        }
        unset($tool);
        return $tools;
    }
}
