<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\OAuth;

use OCA\Mcp\BackgroundJob\PolicyCleanupJob;
use OCA\Mcp\Db\AuthUsersMapper;
use OCA\Mcp\Db\IdentityKey;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\OAuth\AccessTokenAuthenticator;
use OCA\Mcp\OAuth\AuthorizationRequest;
use OCA\Mcp\OAuth\ClientMetadata;
use OCA\Mcp\OAuth\OAuthException;
use OCA\Mcp\OAuth\OAuthStore;
use OCA\Mcp\OAuth\TokenHasher;
use OCA\Mcp\OAuth\TokenOwnerGate;
use OCA\Mcp\OAuth\TokenService;
use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Service\Policy\DecimalCounter;
use OCA\Mcp\Service\Policy\PolicyEventProcessor;
use OCA\Mcp\Service\Policy\PolicyWriter;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Config\IUserConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Server;
use RuntimeException;

/**
 * Ticket 04 on the real server: the cut by generation and the global epoch through the production services, the
 * consent as one transaction with the official config, the account listener, and the cleanup job registered by the
 * app. Every test works on its own account, created and deleted here. The global epoch is moved only in the fresh
 * cells, and put back afterwards, so credentials other tests issue at the starting epoch stay valid.
 */
final class GenerationCutTest extends IntegrationTestCase {
    private const CLIENT = 'https://claude.ai/oauth/mcp-client-metadata';
    private const REDIRECT = 'https://claude.ai/api/mcp/auth_callback';
    private const RESOURCE = 'https://cloud.example.org/apps/mcp/';
    private const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    private const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    private string $uid = '';
    private ?IUser $user = null;
    private string $epoch = '0';
    private ?string $service = null;

    protected function setUp(): void {
        parent::setUp();
        $this->uid = 'mcp-it-gen-' . bin2hex(random_bytes(6));
        $this->user = Server::get(IUserManager::class)->createUser($this->uid, bin2hex(random_bytes(24))) ?: null;
        $this->assertInstanceOf(IUser::class, $this->user);
        $this->epoch = Server::get(PolicyStateMapper::class)->state()['auth_epoch'];
        Server::get(\OCP\IAppConfig::class)->clearCache(false);
        $this->service = Server::get(\OCP\IAppConfig::class)->hasKey('mcp', GrantPolicy::SERVICE_KEY)
            ? Server::get(IConfig::class)->getAppValue('mcp', GrantPolicy::SERVICE_KEY) : null;
        $policy = Server::get(GrantPolicy::class);
        $policy->setGlobalEnabled(true);
        $policy->setEligible($this->uid, true);
    }

    protected function tearDown(): void {
        $this->user?->delete();
        $db = $this->official();
        foreach (['mcp_auth_users', 'mcp_auth_recheck'] as $table) {
            $qb = $db->getQueryBuilder();
            $qb->delete($table)->where($qb->expr()->eq('user_key', $qb->createNamedParameter(IdentityKey::user($this->uid))))->executeStatement();
        }
        Server::get(OAuthStore::class)->deleteUpToGeneration($this->uid, DecimalCounter::MAX);
        // Test-only reset of the app's own counter, so later tests (and the baseline credentials) keep their epoch.
        $qb = $db->getQueryBuilder();
        $qb->update('mcp_policy_state')->set('auth_epoch', $qb->createNamedParameter($this->epoch))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter(1)))->executeStatement();
        $config = Server::get(IConfig::class);
        $this->service === null ? $config->deleteAppValue('mcp', GrantPolicy::SERVICE_KEY) : $config->setAppValue('mcp', GrantPolicy::SERVICE_KEY, $this->service);
        Server::get(\OCP\IAppConfig::class)->clearCache(false);
        parent::tearDown();
    }

    private function request(): AuthorizationRequest {
        return new AuthorizationRequest(new ClientMetadata(self::CLIENT, 'Claude', [self::REDIRECT]), self::REDIRECT, 'xyz',
            self::CHALLENGE, self::RESOURCE, 'mcp');
    }

    private function consent(?TokenService $tokens = null): string {
        $code = ($tokens ?? Server::get(TokenService::class))->consent($this->request(), $this->uid);
        $this->assertIsString($code);
        return $code;
    }

    /** @return array<string, string|int> */
    private function exchange(string $code): array {
        return Server::get(TokenService::class)->exchangeCode(['code' => $code, 'code_verifier' => self::VERIFIER,
            'client_id' => self::CLIENT, 'redirect_uri' => self::REDIRECT, 'resource' => self::RESOURCE]);
    }

    private function bearer(array $tokens): ?string {
        return Server::get(AccessTokenAuthenticator::class)->authenticate('Bearer ' . $tokens['access_token'], self::RESOURCE)?->getUID();
    }

    /** @return list<array{string, string}> (auth_global_epoch, auth_gen) of the user's rows in one table, as another session reads them */
    private function stamps(IDBConnection $db, string $table): array {
        $qb = $db->getQueryBuilder();
        $result = $qb->select('auth_global_epoch', 'auth_gen')->from($table)->where($qb->expr()->eq('user_id', $qb->createNamedParameter($this->uid)))
            ->orderBy('auth_gen')->executeQuery();
        $rows = array_map(static fn (array $row): array => [(string)$row['auth_global_epoch'], (string)$row['auth_gen']], $result->fetchAll());
        $result->closeCursor();
        return $rows;
    }

    public function testTheLineageKeepsItsGenerationAndADisconnectCutsEverythingIssuedBefore(): void {
        $second = $this->openIndependentConnection();
        $first = $this->exchange($this->consent());
        $next = Server::get(TokenService::class)->refresh(['refresh_token' => $first['refresh_token'], 'client_id' => self::CLIENT]);
        $other = $this->exchange($this->consent());
        $e = $this->epoch;
        $this->assertSame([[$e, '2'], [$e, '3']], $this->stamps($second, 'mcp_oauth_tokens'));
        $this->assertSame([[$e, '2']], $this->stamps($second, 'mcp_oauth_spent'), 'the spent record keeps the generation of its lineage');
        $this->assertSame([$this->uid, $this->uid], [$this->bearer($next), $this->bearer($other)], 'a consent leaves the other sessions alone');

        Server::get(GrantPolicy::class)->setConnected($this->uid, false);
        $this->assertSame(['issue_seq' => '3', 'revoked_below' => '3'], (new AuthUsersMapper($second))->generations($this->uid));
        $this->assertSame([], $this->stamps($second, 'mcp_oauth_tokens'), 'the cleanup after the commit removed them');
        $this->assertNull($this->bearer($other));

        $fresh = $this->exchange($this->consent());
        Server::get(OAuthStore::class)->deleteUpToGeneration($this->uid, '3');
        $this->assertSame($this->uid, $this->bearer($fresh), 'a repeated cleanup never removes a later generation');
        try {
            Server::get(TokenService::class)->refresh(['refresh_token' => $first['refresh_token'], 'client_id' => self::CLIENT]);
            $this->fail('A refresh token spent before the cut was accepted');
        } catch (OAuthException $e) {
            $this->assertSame('invalid_grant', $e->error);
        }
        $this->assertSame($this->uid, $this->bearer($fresh), 'its replay cannot revoke the session of the later consent');
    }

    /** Connected and the code are one transaction with the official config: a failed code leaves neither. */
    public function testAConsentWhoseCodeFailsLeavesTheUserDisconnectedOnEverySession(): void {
        $second = $this->openIndependentConnection();
        $state = Server::get(PolicyStateMapper::class)->state();
        $failing = new class($this->official()) extends OAuthStore {
            public function insertCode(array $row, int $now): void {
                parent::insertCode($row, $now);
                throw new RuntimeException('Injected after the code');
            }
        };
        $tokens = new TokenService($failing, Server::get(TokenHasher::class), Server::get(ITimeFactory::class), Server::get(TokenOwnerGate::class),
            Server::get(IConfig::class), Server::get(PolicyWriter::class), Server::get(AuthUsersMapper::class), Server::get(PolicyEventProcessor::class));
        try {
            $tokens->consent($this->request(), $this->uid);
            $this->fail('Injected failure swallowed');
        } catch (RuntimeException $e) {
            $this->assertSame('Injected after the code', $e->getMessage());
        }
        $this->assertFalse($this->official()->inTransaction());
        $users = clone Server::get(IUserConfig::class);
        (new \ReflectionProperty($users, 'connection'))->setValue($users, $second);
        $users->clearCache($this->uid, false);
        $this->assertSame([], $users->getValuesByUsers('mcp', GrantPolicy::CONNECTED_KEY, null, [$this->uid]));
        Server::get(IUserConfig::class)->clearCache($this->uid, false);
        $this->assertFalse(Server::get(GrantPolicy::class)->connected($this->uid), 'the worker cache was not left with the rolled back value');
        $this->assertSame(['issue_seq' => '1', 'revoked_below' => '0'], (new AuthUsersMapper($second))->generations($this->uid));
        $this->assertSame([], $this->stamps($second, 'mcp_oauth_codes'));
        $this->assertSame($state, (new PolicyStateMapper($second))->state());
    }

    /**
     * Fresh cells only: the cleanup of a global revocation removes every credential of the old epoch, by design, and
     * in the upgrade cells that would include the baseline credentials LegacyCredentialsTest still has to check.
     */
    public function testSwitchingTheServiceOffMovesTheGlobalEpochAndOnlyLaterCredentialsWork(): void {
        if (getenv('MCP_IT_MODE') === 'upgrade') {
            $this->markTestSkipped('The global cleanup would remove the baseline credentials of the upgrade cell');
        }
        $second = $this->openIndependentConnection();
        $grant = $this->exchange($this->consent());
        $policy = Server::get(GrantPolicy::class);
        $policy->setGlobalEnabled(false);
        $moved = DecimalCounter::increment($this->epoch);
        $this->assertSame($moved, (new PolicyStateMapper($second))->state()['auth_epoch']);
        $this->assertSame([], $this->stamps($second, 'mcp_oauth_tokens'));
        $policy->setGlobalEnabled(true);
        $this->assertNull($this->bearer($grant));
        $policy->setConnected($this->uid, true);
        $fresh = $this->exchange($this->consent());
        $this->assertSame([[$moved, '3']], $this->stamps($second, 'mcp_oauth_tokens'));
        $this->assertSame($this->uid, $this->bearer($fresh));
    }

    /** Disabling the account in Nextcloud cuts through the app's listener, in the same request. */
    public function testDisablingTheAccountCutsItsCredentials(): void {
        $second = $this->openIndependentConnection();
        $grant = $this->exchange($this->consent());
        $this->user->setEnabled(false);
        $this->assertSame(['issue_seq' => '2', 'revoked_below' => '2'], (new AuthUsersMapper($second))->generations($this->uid));
        $this->user->setEnabled(true);
        $this->assertNull($this->bearer($grant));
        $this->assertSame($this->uid, $this->bearer($this->exchange($this->consent())));
    }

    /** Registered from appinfo/info.xml by install and upgrade alike, and safe to run at any time. */
    public function testTheDailyCleanupJobIsRegisteredByTheAppAndRuns(): void {
        $this->assertTrue(Server::get(IJobList::class)->has(PolicyCleanupJob::class, null));
        $job = Server::get(PolicyCleanupJob::class);
        $this->assertSame(PolicyCleanupJob::INTERVAL, $job->getInterval());
        $grant = $this->exchange($this->consent());
        (new \ReflectionMethod($job, 'run'))->invoke($job, null);
        $this->assertSame($this->uid, $this->bearer($grant), 'the job never removes a valid credential');
    }
}
