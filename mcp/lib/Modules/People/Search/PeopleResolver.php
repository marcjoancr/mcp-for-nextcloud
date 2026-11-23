<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\People\Search;
use InvalidArgumentException;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Accounts\IAccountManager;
use OCP\Collaboration\Collaborators\ISearch;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Profile\IProfileManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;
use Throwable;

/** Actor-bound, bounded candidate resolution using Nextcloud's native sharing search. */
final class PeopleResolver {
    public const MAX_QUERIES = 20;
    public const MAX_CANDIDATES = 10;
    private const PROFILE_FIELDS = ['displayname', 'headline', 'organisation', 'role', 'pronouns'];

    public function __construct(private ISearch $search, private IUserManager $users, private IUserSession $session,
        private IProfileManager $profiles, private IAccountManager $accounts, private LoggerInterface $logger) {}

    /** @param array<string,mixed> $arguments @return list<array<string,mixed>> */
    public function resolve(array $arguments, string $actorId): array {
        $queries = $arguments['queries'] ?? null;
        if (!is_array($queries) || !array_is_list($queries) || $queries === [] || count($queries) > self::MAX_QUERIES) {
            throw new InvalidArgumentException('Invalid people query batch');
        }
        $normalized = [];
        foreach ($queries as $query) {
            if (!is_string($query)) { throw new InvalidArgumentException('Invalid people query batch'); }
            $query = trim($query); $length = mb_strlen($query);
            if ($length < PeopleSearch::MIN_TERM || $length > PeopleSearch::MAX_TERM) { throw new InvalidArgumentException('Invalid people query batch'); }
            $normalized[] = $query;
        }
        $actor = $this->session->getUser();
        if (!$actor instanceof IUser || $actor->getUID() !== $actorId) { throw new InvalidArgumentException('People search actor does not match the active session'); }

        $resolved = [];
        foreach ($normalized as $query) {
            try { [$native, $hasMore] = $this->search->search($query, [IShare::TYPE_USER], false, self::MAX_CANDIDATES, 0); }
            catch (Throwable $e) {
                $this->logger->warning('MCP people resolution failed', ['app'=>'mcp', 'exception_class'=>$e::class]);
                throw new ToolFailure(Translator::t('The search for people failed; try again.'));
            }
            $exact = $this->matches($native['exact']['users'] ?? []);
            $partial = $this->matches($native['users'] ?? []);
            $candidates = [];
            $truncated = (bool)$hasMore;
            foreach ([['rows'=>$exact,'kind'=>'exact'], ['rows'=>$partial,'kind'=>'suggestion']] as $tier) {
                foreach ($tier['rows'] as $match) {
                    $uid = (string)($match['value']['shareWith'] ?? '');
                    if ($uid === '' || isset($candidates[$uid])) { continue; }
                    $candidate = $this->candidate($uid, $match, $actor);
                    if ($candidate === null) { continue; }
                    if (count($candidates) >= self::MAX_CANDIDATES) {
                        $truncated = true;
                        break 2;
                    }
                    $candidate['match'] = $tier['kind'];
                    $candidates[$uid] = $candidate;
                }
            }
            $rows = array_values($candidates);
            $isResolved = count($rows) === 1 && $rows[0]['match'] === 'exact' && !$truncated;
            $status = count($rows) === 0 ? 'no_match' : ($isResolved ? 'resolved' : 'ambiguous');
            $resolved[] = ['query'=>$query, 'status'=>$status, 'resolved_uid'=>$status === 'resolved' ? $rows[0]['uid'] : null,
                'truncated'=>$truncated, 'candidates'=>$rows];
        }
        return $resolved;
    }

    /** @param mixed $matches @return list<array<string,mixed>> */
    private function matches(mixed $matches): array {
        return is_array($matches) ? array_values(array_filter($matches, static fn(mixed $row): bool => is_array($row))) : [];
    }

    /** @param array<string,mixed> $match @return array<string,mixed>|null */
    private function candidate(string $uid, array $match, IUser $actor): ?array {
        $user = $this->users->get($uid);
        if (!$user instanceof IUser || !$user->isEnabled()) { return null; }
        $candidate = ['uid'=>$uid, 'displayName'=>(string)($match['label'] ?? $uid)];
        try {
            if (!$this->profiles->isProfileEnabled($user)) { return $candidate; }
            $visible = $this->profiles->getProfileFields($user, $actor);
            $profile = [];
            foreach (self::PROFILE_FIELDS as $field) {
                $value = $visible[$field] ?? null;
                if (is_string($value) && $value !== '') { $profile[$field] = mb_substr($value, 0, 500); }
            }
            if (isset($profile['displayname'])) { $candidate['displayName'] = $profile['displayname']; }
            if ($profile !== []) { $candidate['profile'] = $profile; }
            if ($this->profiles->isProfileFieldVisible(IAccountManager::PROPERTY_EMAIL, $user, $actor)) {
                try {
                    $email = $this->accounts->getAccount($user)->getProperty(IAccountManager::PROPERTY_EMAIL)->getValue();
                    if ($email !== '') { $candidate['email'] = mb_substr($email, 0, 320); }
                } catch (Throwable) { /* Never fall back to native search metadata for an email. */ }
            }
        } catch (Throwable $e) {
            $this->logger->debug('MCP visible profile projection failed', ['app'=>'mcp', 'exception_class'=>$e::class]);
        }
        return $candidate;
    }
}
