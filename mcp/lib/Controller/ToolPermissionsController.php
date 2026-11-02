<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Controller;

use InvalidArgumentException;
use OCA\Mcp\Access\Grants\Commands\ToolGrantCommands;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\Policy\Resolution\GrantUnknown;
use OCA\Mcp\Access\Policy\Transactions\PolicyConflict;
use OCA\Mcp\Access\Policy\Receipts\ReceiptExpired;
use OCA\Mcp\Access\Policy\Receipts\ReceiptNotFound;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IL10N;
use OCA\Mcp\L10n\Translator;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IUserManager;

/** Administrative routes retain Nextcloud's admin and CSRF middleware. */
class ToolPermissionsController extends Controller {
    private const PAGE_SIZE = 30;
    public function __construct(string $appName, IRequest $request, private IUserSession $session,
        private IUserManager $users, private IGroupManager $groups, private ToolRegistry $tools,
        private GrantPolicy $policy, private ToolGrantCommands $commands, private PolicyStateMapper $state,
        private IL10N $l10n) {
        parent::__construct($appName, $request);
    }

    public function subjects(string $kind, string $search = '', int $page = 1): JSONResponse {
        return $this->admin(function () use ($kind, $search, $page): array {
            if ($page < 1 || $page > 10000 || mb_strlen($search) > 255) {
                throw new InvalidArgumentException();
            }
            $offset = ($page - 1) * self::PAGE_SIZE;
            $rows = match ($kind) {
                'user' => array_map(static fn ($u) => ['id' => $u->getUID(), 'displayName' => $u->getDisplayName()],
                    $this->users->searchDisplayName($search, self::PAGE_SIZE + 1, $offset)),
                'group' => array_map(static fn ($g) => ['id' => $g->getGID(), 'displayName' => $g->getDisplayName()],
                    $this->groups->search($search, self::PAGE_SIZE + 1, $offset)),
                default => throw new InvalidArgumentException(),
            };
            return ['subjects' => array_slice(array_values($rows), 0, self::PAGE_SIZE), 'page' => $page, 'hasMore' => count($rows) > self::PAGE_SIZE];
        });
    }

    public function index(string $kind, string $subject): JSONResponse {
        return $this->admin(fn () => $this->detail($kind, $subject));
    }

    /** Show all group assignments, including the ones that did not win the effective decision. */
    public function origin(string $subject, string $tool): JSONResponse {
        return $this->admin(function () use ($subject, $tool): array {
            $user = $this->users->get($subject);
            if ($user === null) {
                throw new \OutOfBoundsException();
            }
            $definitions = array_column($this->tools->administrableDefinitions($user), null, 'name');
            $definitions['_service'] = ['module' => '_system', 'operation' => 'eligible'];
            if (!isset($definitions[$tool])) {
                throw new InvalidArgumentException();
            }
            $revision = $this->state->state()['revision'];
            $definition = ['name' => $tool] + $definitions[$tool];
            $cell = $this->catalogCell($definition);
            $individual = $this->row($this->policy->within($subject, fn () => $this->userState($subject, $definition)));
            $groups = [];
            foreach ($this->groups->getUserGroups($user) as $group) {
                $groups[] = ['id' => $group->getGID(), 'displayName' => $group->getDisplayName()]
                    + $this->row($this->policy->groupToolStates($group->getGID(), [$cell])[$tool]);
            }
            if ($revision !== $this->state->state()['revision']) {
                throw new PolicyConflict();
            }
            return compact('revision', 'individual', 'groups');
        });
    }

    public function update(string $kind, string $subject): JSONResponse {
        return $this->admin(function (string $actor) use ($kind, $subject): array {
            $revision = $this->request->getParam('revision');
            $changes = $this->request->getParam('changes');
            $preview = $this->request->getParam('preview');
            $previewId = $this->request->getParam('previewId');
            if (!is_string($revision) || !is_bool($preview) || !is_array($changes) || $changes === []
                || count($changes) > 1000 || (!$preview && !is_string($previewId))) {
                throw new InvalidArgumentException();
            }
            $before = $this->detail($kind, $subject);
            if ($before['revision'] !== $revision) {
                throw new PolicyConflict();
            }
            $user = $kind === 'user' ? $this->users->get($subject) : null;
            $catalog = array_column($this->tools->administrableDefinitions($user), null, 'name');
            $catalog['_service'] = ['module' => '_system', 'operation' => 'eligible'];
            $visible = array_fill_keys(array_column($before['tools'], 'name'), true);
            $visible['_service'] = true;
            $cells = [];
            foreach ($changes as $name => $decision) {
                if (!isset($visible[$name], $catalog[$name]) || !in_array($decision, ['allow', 'deny', 'inherit'], true)) {
                    throw new InvalidArgumentException();
                }
                $cells[] = $this->catalogCell(['name' => $name] + $catalog[$name])
                    + ['decision' => $decision === 'inherit' ? 'neutral' : $decision];
            }
            if ($preview) {
                $result = $kind === 'user'
                    ? $this->commands->previewUser($revision, $actor, $subject, $cells)
                    : $this->commands->previewGroup($revision, $actor, $subject, $cells);
                $result['impact']['summary'] = Translator::t('Users affected: %1$s. Users losing service access: %2$s.',
                    [$result['impact']['affectedUsers'], $result['impact']['eligibilityLoss']]);
                foreach ($result['impact']['tools'] as $name => &$projected) {
                    $projected['origin'] = $this->row($projected + ['assigned' => null])['origin'];
                    if ($name === '_service' && (!$this->policy->globalEnabled() || ($kind === 'user' && !$this->users->get($subject)?->isEnabled()))) {
                        $projected['effective'] = false;
                        $projected['origin'] = $before['service']['origin'];
                        $projected['groupIds'] = [];
                    }
                }
                unset($projected);
                return $result + $before;
            }
            if ($kind === 'user') {
                $this->commands->setUser($revision, $actor, $subject, $cells, $previewId);
            } else {
                $this->commands->setGroup($revision, $actor, $subject, $cells, $previewId);
            }
            return $this->detail($kind, $subject);
        });
    }

    private function detail(string $kind, string $subject): array {
        if ($subject === '' || mb_strlen($subject) > 255 || !in_array($kind, ['user', 'group'], true)) {
            throw new InvalidArgumentException();
        }
        $user = $kind === 'user' ? $this->users->get($subject) : null;
        if (($kind === 'user' && $user === null) || ($kind === 'group' && $this->groups->get($subject) === null)) {
            throw new \OutOfBoundsException();
        }
        $revision = $this->state->state()['revision'];
        $groups = $user === null ? [] : array_map(static fn ($g) => ['id' => $g->getGID(), 'displayName' => $g->getDisplayName()],
            array_values($this->groups->getUserGroups($user)));
        $describe = function () use ($kind, $subject, $user): array {
            $catalog = $this->tools->administrableDefinitions($user);
            $groupStates = [];
            if ($user === null) {
                $cells = array_map($this->catalogCell(...), $catalog);
                $cells[] = ['toolName' => '_service', 'module' => '_system', 'operation' => 'eligible'];
                $groupStates = $this->policy->groupToolStates($subject, $cells);
            }
            $service = $this->row($groupStates['_service'] ?? $this->policy->subjectToolState($kind, $subject, '_service', '_system', 'eligible'));
            if (!$this->policy->globalEnabled() || ($user !== null && !$user->isEnabled())) {
                $service['effective'] = false;
                $service['groupIds'] = [];
                $service['origin'] = !$this->policy->globalEnabled() ? Translator::t('Service disabled globally') : Translator::t('Account disabled');
            }
            $rows = [];
            $available = $user === null ? [] : array_fill_keys(array_column($this->tools->permittedDefinitions($subject), 'name'), true);
            foreach ($catalog as $definition) {
                $raw = $groupStates[$definition['name']] ?? $this->userState($subject, $definition);
                $row = $this->row($raw);
                if ($user !== null && $row['effective'] === true && !isset($available[$definition['name']])) {
                    $row['effective'] = false;
                    $row['groupIds'] = [];
                    $row['origin'] = Translator::t('Application, role or required operation unavailable');
                }
                $rows[] = $row + ['operation' => $definition['operation'], 'requiresOperations' => $definition['grantAnyOf'] ?? [],
                    'requiredModules' => $definition['grantAllOf'] ?? []]
                    + array_intersect_key($definition, array_flip(['name', 'module', 'title', 'description']));
            }
            return ['service' => $service, 'tools' => $rows];
        };
        $result = $user === null ? $describe() : $this->policy->within($subject, $describe);
        if ($this->state->state()['revision'] !== $revision) {
            throw new PolicyConflict();
        }
        return ['kind' => $kind, 'subject' => $subject, 'groups' => $groups, 'revision' => $revision] + $result;
    }

    private function catalogCell(array $definition): array {
        return ['toolName' => $definition['name'], 'module' => $definition['module'], 'operation' => $definition['operation']]
            + array_intersect_key($definition, ['grantAnyOf' => true]);
    }

    private function userState(string $uid, array $definition): array {
        $name = $definition['name'];
        $module = $definition['module'];
        $raw = $this->policy->subjectToolState('user', $uid, $name, $module, $definition['operation']);
        if (isset($definition['grantAnyOf'])) {
            try {
                $variants = $this->tools->permittedVariants($uid, $name);
            } catch (GrantUnknown) {
                $raw['effective'] = null;
                $raw['source'] = 'unknown';
                return $raw;
            }
            if ($variants !== []) {
                return $this->policy->subjectToolState('user', $uid, $name, $module, $variants[0]);
            }
        }
        return $raw;
    }

    private function row(array $state): array {
        $ids = $state['groupIds'];
        $origin = match ($state['source']) {
            'individual_allow', 'individual_deny', 'individual', 'legacy_individual' => Translator::t('Individual assignment'),
            'group', 'group_allow', 'group_deny', 'legacy_group' => Translator::t('Group assignment'),
            'default' => Translator::t('Default'),
            'unknown' => Translator::t('Cannot be checked right now'),
            default => $state['source'],
        };
        return ['state' => match ($state['assigned']) { true, 'allow' => 'allow', false, 'deny' => 'deny', null, 'neutral' => 'inherit' },
            'effective' => $state['effective'], 'source' => $state['source'], 'origin' => $origin . ($ids === [] ? '' : ' · ' . implode(', ', $ids)), 'groupIds' => $ids];
    }

    private function admin(\Closure $callback): JSONResponse {
        $user = $this->session->getUser();
        if ($user === null || !$user->isEnabled() || !$this->groups->isAdmin($user->getUID())) {
            return new JSONResponse(['error' => 'Forbidden'], 403);
        }
        try {
            Translator::use($this->l10n);
            return new JSONResponse($callback($user->getUID()));
        } catch (PolicyConflict|ReceiptExpired|ReceiptNotFound) {
            return new JSONResponse(['error' => 'Reload permissions'], 409);
        } catch (\OutOfBoundsException) {
            return new JSONResponse(['error' => 'Not found'], 404);
        } catch (InvalidArgumentException) {
            return new JSONResponse(['error' => 'Invalid request'], 400);
        } catch (PolicyUnavailable) {
            return new JSONResponse(['error' => 'Unavailable'], 503);
        } finally {
            Translator::reset();
        }
    }
}
