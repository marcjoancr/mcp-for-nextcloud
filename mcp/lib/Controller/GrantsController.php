<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Controller;

use InvalidArgumentException;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\OAuth\ClientMetadataFetcher;
use OCA\Mcp\OAuth\ClientResolver;
use OCA\Mcp\OAuth\NativeClient;
use OCA\Mcp\OAuth\OAuthStore;
use OCA\Mcp\Service\GrantMatrix;
use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Service\LogsAccess;
use OCA\Mcp\Service\LogsGroupsConflict;
use OCA\Mcp\Service\Policy\MissingRevision;
use OCA\Mcp\Service\Policy\PolicyConflict;
use OCA\Mcp\Service\Policy\PolicyEventPayload;
use OCA\Mcp\Service\Policy\PolicyUnavailable;
use OCA\Mcp\Service\Policy\PolicyWriter;
use OCA\Mcp\Service\Policy\ReceiptExpired;
use OCA\Mcp\Service\Policy\ReceiptNotFound;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * JSON API of the admin permission matrix. Every endpoint is admin-only and CSRF-protected by
 * Nextcloud's defaults (no NoAdminRequired/NoCSRFRequired); the page sends the requesttoken header.
 * Invalid input answers 400 {"error":"Invalid request"} without saying which part was wrong.
 *
 * Every policy write carries expectedRevision, the global policy revision (a decimal string) the page was built
 * from, and goes through the PolicyWriter (contracts §2.3):
 * - without it: 428 {"error":"PreconditionRequired"}, nothing written;
 * - stale: 409 {"error":"Conflict", "revision", "state"}, nothing written; revision and state are read after the
 *   rollback, so the page can show what is saved now;
 * - storage failure: 503 {"error":"Unavailable", "uncertain"}; uncertain=true means the write may have committed, so
 *   the page reloads instead of repeating it. No route falls back to defaults on either error.
 * Booleans keep their 1.0.8 meaning: true is Allow and false is Deny, never Inherit.
 */
class GrantsController extends Controller {
    /** Pseudo-operation of the bulk endpoint that toggles eligibility instead of a grant. */
    public const ELIGIBLE = 'eligible';
    /** Triestate of POST /api/grants/commands, mapped to the raw value stored ('1', '0' or no key). */
    public const STATES = ['allow' => '1', 'deny' => '0', 'inherit' => null];
    public function __construct(
        string $appName,
        IRequest $request,
        private GrantMatrix $matrix,
        private GrantPolicy $policy,
        private IUserManager $userManager,
        private IConfig $config,
        private OAuthStore $oauth,
        private LogsAccess $logs,
        private PolicyWriter $writer,
        private PolicyStateMapper $state,
        private IUserSession $session,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * @param string $search term matched against uid, display name and e-mail
     * @param string $group group id, '' for every user
     * @param int $page 1-based page
     * @param string $filter '' for every user, 'eligible' or 'connected' (see GrantMatrix::FILTERS)
     * @return JSONResponse one matrix page (see GrantMatrix::page) plus the policy revision it was built from, or 400
     */
    public function index(string $search = '', string $group = '', int $page = 1, string $filter = ''): JSONResponse {
        return $this->guard(function () use ($search, $group, $page, $filter): array {
            // Read before the page: a write in between makes the next save a conflict, never a stale accept.
            $revision = $this->revision();
            return $this->matrix->page($search, $group, $page, $filter) + ['revision' => $revision];
        });
    }

    /**
     * Body {expectedRevision, eligible: bool} or {expectedRevision, module, operation, granted: bool}, never both.
     *
     * @param string $uid existing Nextcloud user id
     * @return JSONResponse the user's updated row with {revision, receiptId} (receiptId null when nothing changed),
     *     or 400/409/428/503
     */
    public function update(string $uid): JSONResponse {
        return $this->guard(function () use ($uid): array {
            $user = $this->user($uid);
            $expected = $this->expectedRevision();
            $eligible = $this->request->getParam('eligible');
            $hasGrant = $this->request->getParam('module') !== null || $this->request->getParam('operation') !== null || $this->request->getParam('granted') !== null;
            if ($eligible !== null && !$hasGrant) {
                $key = GrantPolicy::ELIGIBLE_KEY;
                $value = self::bool($eligible);
            } elseif ($eligible === null && $hasGrant) {
                [$module, $operation] = self::grant($this->request->getParam('module'), $this->request->getParam('operation'));
                $key = (string)PolicyEventPayload::userKey($module, $operation);
                $value = self::bool($this->request->getParam('granted'));
            } else {
                throw new InvalidArgumentException('Invalid request');
            }
            $result = $this->writer->applyCells($expected, $this->actor(), PolicyEventPayload::ACTION_UPDATE,
                [['uid' => $uid, 'key' => $key, 'value' => $value ? '1' : '0']]);
            return $this->matrix->rows([$user])[0] + ['revision' => $result['revision'], 'receiptId' => $result['receiptId']];
        }, fn (): array => $this->usersState([$uid]));
    }

    /**
     * Body {expectedRevision, uids: list<string> (1..PAGE_SIZE), module: string|null, operation: string,
     * granted: bool}; operation "eligible" with module null toggles eligibility. One transaction: every uid is
     * validated first, and all are written or none is.
     *
     * @return JSONResponse {updated: int, revision, receiptId}, or 400/409/428/503
     */
    public function bulk(): JSONResponse {
        $uids = $this->request->getParam('uids');
        return $this->guard(function () use ($uids): array {
            if (!is_array($uids) || $uids === [] || count($uids) > GrantMatrix::PAGE_SIZE || !array_is_list($uids)) {
                throw new InvalidArgumentException('Invalid request');
            }
            $module = $this->request->getParam('module');
            $operation = $this->request->getParam('operation');
            $granted = self::bool($this->request->getParam('granted'));
            if (!($operation === self::ELIGIBLE && $module === null)) {
                [$module, $operation] = self::grant($module, $operation);
            }
            $key = (string)PolicyEventPayload::userKey($module, $operation);
            $uids = array_values(array_unique(array_map(fn ($uid) => $this->user(is_string($uid) ? $uid : '')->getUID(), $uids)));
            $expected = $this->expectedRevision();
            $result = $this->writer->applyCells($expected, $this->actor(), PolicyEventPayload::ACTION_BULK,
                array_map(static fn (string $uid): array => ['uid' => $uid, 'key' => $key, 'value' => $granted ? '1' : '0'], $uids));
            return ['updated' => count($uids), 'revision' => $result['revision'], 'receiptId' => $result['receiptId']];
        }, fn (): array => $this->usersState(is_array($uids) ? $uids : []));
    }

    /**
     * Body {expectedRevision, commands: list<{uid, module: string|null, operation, state}>}: several users and
     * operations in one transaction (at most PolicyWriter::MAX_UIDS users and MAX_CELLS distinct cells). state is
     * "allow", "deny" or "inherit" (removes the override); operation "eligible" with module null targets eligibility.
     * A boolean "granted" is refused here, so a cell never mixes the two forms, and every command names all four
     * keys: module is null for eligibility, never left out.
     *
     * @return JSONResponse {revision, changed: int, receiptId}, or 400/409/428/503
     */
    public function commands(): JSONResponse {
        $commands = $this->request->getParam('commands');
        return $this->guard(function () use ($commands): array {
            // Counted before duplicates are dropped too: a body is never larger than the limit it may write.
            if (!is_array($commands) || $commands === [] || !array_is_list($commands) || count($commands) > PolicyWriter::MAX_CELLS) {
                throw new InvalidArgumentException('Invalid request');
            }
            $cells = [];
            foreach ($commands as $command) {
                // Exactly these four keys: module is required even for eligibility, as an explicit null.
                if (!is_array($command) || array_diff(array_keys($command), ['uid', 'module', 'operation', 'state']) !== []
                    || !array_key_exists('module', $command)
                    || !is_string($command['uid'] ?? null) || !is_string($command['operation'] ?? null)
                    || !(is_string($command['module'] ?? null) || ($command['module'] ?? null) === null)
                    || !is_string($command['state'] ?? null) || !array_key_exists($command['state'], self::STATES)) {
                    throw new InvalidArgumentException('Invalid request');
                }
                $key = PolicyEventPayload::userKey($command['module'] ?? null, $command['operation']);
                if ($key === null) {
                    throw new InvalidArgumentException('Invalid request');
                }
                $cells[] = ['uid' => $command['uid'], 'key' => $key, 'value' => self::STATES[$command['state']]];
            }
            $expected = $this->expectedRevision();
            $result = $this->writer->applyCells($expected, $this->actor(), PolicyEventPayload::ACTION_COMMANDS, $cells);
            return ['revision' => $result['revision'], 'changed' => $result['changed'], 'receiptId' => $result['receiptId']];
        }, fn (): array => $this->usersState(is_array($commands) ? array_map(static fn ($c) => is_array($c) ? ($c['uid'] ?? '') : '', $commands) : []));
    }

    /**
     * Body {receiptId, expectedRevision}: undoes the cells of one of the caller's receipts, restoring each raw
     * value, absence included. expectedRevision must be the revision that receipt's command committed.
     *
     * @return JSONResponse {revision, changed: int}; 404 for an unknown or another administrator's receipt, 410 when
     *     it expired, 409 when anything changed since, or 400/428/503
     */
    public function undo(): JSONResponse {
        $receiptId = $this->request->getParam('receiptId');
        return $this->guard(function () use ($receiptId): array|JSONResponse {
            if (!is_string($receiptId)) {
                throw new InvalidArgumentException('Invalid request');
            }
            $expected = $this->expectedRevision();
            try {
                return $this->writer->restoreReceipt($receiptId, $expected, $this->actor());
            } catch (ReceiptNotFound) {
                return new JSONResponse(['error' => 'NotFound'], Http::STATUS_NOT_FOUND);
            } catch (ReceiptExpired) {
                return new JSONResponse(['error' => 'Gone'], Http::STATUS_GONE);
            }
        }, fn (): ?array => is_string($receiptId) ? $this->receiptState($receiptId) : null);
    }

    /**
     * Body {expectedRevision, enabled: bool}.
     *
     * @return JSONResponse {enabled: bool, revision}, or 400/409/428/503
     */
    public function service(): JSONResponse {
        return $this->guard(function (): array {
            $enabled = self::bool($this->request->getParam('enabled'));
            $result = $this->writer->adminSetGlobalEnabled($this->expectedRevision(), $this->actor(), $enabled);
            return ['enabled' => $this->policy->globalEnabled(), 'revision' => $result['revision']];
        }, fn (): array => ['enabled' => $this->policy->globalEnabled()]);
    }

    /**
     * Current OAuth client settings for the admin page.
     *
     * @return JSONResponse {hosts, hostsDefault, nativeClientEnabled, nativeClientId, nativeRedirectUris}
     */
    public function oauthClients(): JSONResponse {
        return $this->guard(fn (): array => $this->oauthState());
    }

    /**
     * Body {hosts?: list<string>, nativeClientEnabled?: bool}. Everything is validated before anything is written;
     * an empty host list is rejected because at least one host must stay trusted.
     *
     * @return JSONResponse the same shape as oauthClients(), or 400
     */
    public function updateOauthClients(): JSONResponse {
        return $this->guard(function (): array {
            $hosts = $this->request->getParam('hosts');
            $native = $this->request->getParam('nativeClientEnabled');
            if ($hosts === null && $native === null) {
                throw new InvalidArgumentException('Invalid request');
            }
            if ($hosts !== null) {
                $hosts = self::hosts($hosts);
            }
            if ($native !== null) {
                $native = self::bool($native);
            }
            if ($hosts !== null) {
                $default = explode(',', ClientMetadataFetcher::DEFAULT_HOSTS);
                if (count($hosts) === count($default) && array_diff($hosts, $default) === []) {
                    // The default is not frozen into the config, so a later release can change it.
                    $this->config->deleteAppValue($this->appName, ClientMetadataFetcher::HOSTS_KEY);
                } else {
                    $this->config->setAppValue($this->appName, ClientMetadataFetcher::HOSTS_KEY, implode(',', $hosts));
                }
            }
            if ($native !== null) {
                $this->config->setAppValue($this->appName, NativeClient::CONFIG_KEY, $native ? '1' : '0');
                if (!$native) {
                    // Under the issuance mutex, so no grant of the native client is issued past this point.
                    $this->writer->underMutex(fn () => $this->oauth->deleteForClient(NativeClient::CLIENT_ID));
                }
            }
            return $this->oauthState();
        });
    }

    /**
     * Who may read the server log besides the administrators, for the "Server log" block.
     *
     * @return JSONResponse {groups, version, revision, missing, allGroups, available, logType}
     */
    public function logsAccess(): JSONResponse {
        return $this->guard(fn (): array => $this->logsState());
    }

    /**
     * Body {groups: list<string>, previous: list<string>, version: int, expectedRevision: string}: the groups whose
     * members pass the role gate of the logs module, and the list, list version and policy revision the page showed
     * when the change was made. Both versions are checked in one gate: when either differs, another tab or
     * administrator saved first, the answer is 409 {error: "Conflict", revision, state} and nothing is written, so a
     * stale save never puts a removed group back. An empty list leaves the administrators only.
     *
     * @return JSONResponse the same shape as logsAccess(), 400 for an invalid body, 409, 428 or 503
     */
    public function updateLogsAccess(): JSONResponse {
        $groups = $this->request->getParam('groups');
        $previous = $this->request->getParam('previous');
        $version = $this->request->getParam('version');
        $valid = static fn (mixed $list): bool => is_array($list) && array_is_list($list) && count($list) <= GrantMatrix::MAX_GROUPS;
        if (!$valid($groups) || !$valid($previous) || !is_int($version) || $version < 0) {
            return new JSONResponse(['error' => 'Invalid request'], Http::STATUS_BAD_REQUEST);
        }
        return $this->guard(function () use ($groups, $previous, $version): array|JSONResponse {
            $expected = $this->expectedRevision();
            try {
                $this->logs->setGroups($groups, $previous, $version, $expected, $this->actor());
            } catch (LogsGroupsConflict) {
                throw new PolicyConflict();
            }
            return $this->logsState();
        }, fn (): array => $this->logsState());
    }

    /** @return array{groups:list<string>, version:int, revision:string, missing:list<string>, allGroups:list<array{id:string, displayName:string}>, available:bool, logType:string} */
    private function logsState(): array {
        $revision = $this->revision();
        $state = $this->logs->state();
        return [
            'groups' => $state['groups'],
            'version' => $state['version'],
            'revision' => $revision,
            'missing' => $this->logs->missingGroups($state['groups']),
            'allGroups' => $this->matrix->groups(),
            'available' => $this->logs->available(),
            'logType' => $this->logs->logType(),
        ];
    }

    /** @return array{hosts:list<string>, hostsDefault:bool, nativeClientEnabled:bool, nativeClientId:string, nativeRedirectUris:list<string>} */
    private function oauthState(): array {
        $raw = $this->config->getAppValue($this->appName, ClientMetadataFetcher::HOSTS_KEY, '');
        $hosts = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $h) => $h !== ''));
        $default = $hosts === [];
        return [
            'hosts' => $default ? explode(',', ClientMetadataFetcher::DEFAULT_HOSTS) : $hosts,
            'hostsDefault' => $default,
            'nativeClientEnabled' => ClientResolver::nativeEnabled($this->config),
            'nativeClientId' => NativeClient::CLIENT_ID,
            'nativeRedirectUris' => NativeClient::REDIRECT_URIS,
        ];
    }

    /**
     * @param mixed $value request value, which must be a non-empty list of bare host names
     * @return list<string> validated lowercase hosts without surrounding whitespace or duplicates
     * @throws InvalidArgumentException for an empty list, a non-string or a host with scheme, port, path, wildcard or space
     */
    private static function hosts(mixed $value): array {
        if (!is_array($value) || $value === [] || !array_is_list($value)) {
            throw new InvalidArgumentException('Invalid request');
        }
        foreach ($value as $index => $host) {
            if (!is_string($host)) {
                throw new InvalidArgumentException('Invalid request');
            }
            $host = strtolower(trim($host));
            if (preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)*$/D', $host) !== 1 || strlen($host) > 253) {
                throw new InvalidArgumentException('Invalid request');
            }
            $value[$index] = $host;
        }
        return array_values(array_unique($value));
    }

    /**
     * Runs one route and maps the policy errors; nothing here falls back to defaults.
     *
     * @param callable():(array<string, mixed>|JSONResponse) $action the route body
     * @param (callable():(array<string, mixed>|null))|null $state fresh state for a 409 body, read after the rollback
     */
    private function guard(callable $action, ?callable $state = null): JSONResponse {
        try {
            $result = $action();
            return $result instanceof JSONResponse ? $result : new JSONResponse($result);
        } catch (MissingRevision) {
            return new JSONResponse(['error' => 'PreconditionRequired'], Http::STATUS_PRECONDITION_REQUIRED);
        } catch (PolicyConflict) {
            try {
                return new JSONResponse(['error' => 'Conflict', 'revision' => $this->revision(), 'state' => $state === null ? null : $state()],
                    Http::STATUS_CONFLICT);
            } catch (PolicyUnavailable $e) {
                return self::unavailable($e);
            }
        } catch (PolicyUnavailable $e) {
            return self::unavailable($e);
        } catch (InvalidArgumentException) {
            return new JSONResponse(['error' => 'Invalid request'], Http::STATUS_BAD_REQUEST);
        }
    }

    private static function unavailable(PolicyUnavailable $e): JSONResponse {
        return new JSONResponse(['error' => 'Unavailable', 'uncertain' => $e->uncertain], Http::STATUS_SERVICE_UNAVAILABLE);
    }

    /** @throws PolicyUnavailable when the policy state cannot be read */
    private function revision(): string {
        return $this->state->state()['revision'];
    }

    /**
     * @return string the expectedRevision of the body, canonical
     * @throws MissingRevision without one
     * @throws InvalidArgumentException when it is not a canonical decimal string
     */
    private function expectedRevision(): string {
        $expected = $this->request->getParam('expectedRevision');
        if ($expected === null) {
            throw new MissingRevision();
        }
        if (!is_string($expected)) {
            throw new InvalidArgumentException('Invalid request');
        }
        return \OCA\Mcp\Service\Policy\DecimalCounter::validate($expected);
    }

    /** @return string uid of the signed-in administrator, recorded on receipts and audit */
    private function actor(): string {
        $user = $this->session->getUser();
        if ($user === null) {
            throw new InvalidArgumentException('Invalid request');
        }
        return $user->getUID();
    }

    /**
     * The current rows of a receipt's users for a 409 of its undo: only for the caller's own, valid receipt.
     *
     * @return array{users: list<array<string, mixed>>}|null null when the receipt cannot be read for the caller
     */
    private function receiptState(string $receiptId): ?array {
        $uids = $this->writer->receiptUsers($receiptId, $this->actor());
        return $uids === null ? null : $this->usersState($uids);
    }

    /**
     * @param array<mixed> $uids uids of the request, as sent
     * @return array{users: list<array<string, mixed>>} fresh rows of those that exist
     */
    private function usersState(array $uids): array {
        $users = [];
        foreach (array_unique(array_filter($uids, 'is_string')) as $uid) {
            $user = $uid === '' ? null : $this->userManager->get($uid);
            if ($user !== null) {
                $users[] = $user;
            }
            if (count($users) >= PolicyWriter::MAX_UIDS) {
                break;
            }
        }
        return ['users' => $this->matrix->rows($users)];
    }

    /** @throws InvalidArgumentException for an unknown user */
    private function user(string $uid): IUser {
        $user = $uid === '' ? null : $this->userManager->get($uid);
        if ($user === null) {
            throw new InvalidArgumentException('Invalid request');
        }
        return $user;
    }

    /**
     * @param mixed $module module id from the request body, validated against GrantPolicy::CATALOG
     * @param mixed $operation operation id from the request body, validated against GrantPolicy::CATALOG
     * @return array{0:string, 1:string}
     * @throws InvalidArgumentException when the pair is not in GrantPolicy::CATALOG
     */
    private static function grant(mixed $module, mixed $operation): array {
        if (!is_string($module) || !is_string($operation) || !GrantPolicy::inCatalog($module, $operation)) {
            throw new InvalidArgumentException('Invalid request');
        }
        return [$module, $operation];
    }

    /**
     * @param mixed $value request value, which must be a JSON boolean
     * @return bool validated boolean value
     * @throws InvalidArgumentException unless the value is a JSON boolean
     */
    private static function bool(mixed $value): bool {
        if (!is_bool($value)) {
            throw new InvalidArgumentException('Invalid request');
        }
        return $value;
    }
}
