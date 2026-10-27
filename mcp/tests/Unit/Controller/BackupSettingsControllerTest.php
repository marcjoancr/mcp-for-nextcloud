<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Controller;

use OCA\Mcp\Controller\BackupSettingsController;
use OCA\Mcp\Access\BackupPolicy\BackupLocation;
use OCA\Mcp\Access\BackupPolicy\BackupCopies;
use OCA\Mcp\Access\BackupPolicy\BackupSettingsWriter;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCA\Mcp\Db\PolicyStateMapper;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IUserManager;
use OCP\App\IAppManager;
use OCP\Files\IFilenameValidator;
use PHPUnit\Framework\TestCase;

/** Validates HTTP adapter admission before any revisioned storage command runs. */
final class BackupSettingsControllerTest extends TestCase {
    public static function responseInterleavings(): iterable {
        foreach (['show', 'update'] as $method) {
            yield $method . ' stable' => [$method, false];
            yield $method . ' concurrent commit' => [$method, true];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('responseInterleavings')]
    public function testResponseCannotPairARevisionWithAnotherCommit(string $method, bool $race): void {
        $revision = '1';
        $document = $this->createMock(\OCA\Mcp\Access\BackupPolicy\BackupSettingsDocument::class);
        $document->method('read')->willReturnCallback(static function () use (&$revision, $race): string {
            if ($race) $revision = '2';
            return json_encode(['version' => 1, 'folder' => $race ? 'B' : 'A',
                'reserved' => ['MCP backups', 'A', 'B'], 'copies' => true], JSON_THROW_ON_ERROR);
        });
        $location = new BackupLocation($document);
        $apps = $this->createMock(IAppManager::class);
        $apps->method('isEnabledForAnyone')->willReturn(true);
        $apps->method('isEnabledForUser')->willReturn(true);
        $users = $this->createMock(IUserManager::class);
        $user = $this->createMock(\OCP\IUser::class);
        $user->method('getUID')->willReturn('alice');
        $users->method('get')->with('alice')->willReturn($user);
        $copies = new BackupCopies($location, $apps, $users);
        $transaction = $this->createMock(PolicyTransaction::class);
        $transaction->method('runAdmin')->willReturn(['revision' => '1']);
        $writer = new BackupSettingsWriter($location, $copies, $transaction,
            $this->createMock(IAppConfig::class), $this->createMock(IFilenameValidator::class));
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(static fn ($key) => [
            'expectedRevision' => '0', 'folder' => 'A', 'copies' => true,
        ][$key] ?? null);
        $state = $this->createMock(PolicyStateMapper::class);
        $state->method('state')->willReturnCallback(static function () use (&$revision): array { return ['revision' => $revision]; });
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);
        $controller = new BackupSettingsController('mcp', $request, $location, $copies, $writer, $state, $session);
        $response = $controller->$method();
        self::assertSame($race ? 503 : 200, $response->getStatus());
        if ($race) {
            self::assertArrayNotHasKey('folder', $response->getData());
            if ($method === 'update') self::assertTrue($response->getData()['uncertain']);
        } else {
            self::assertSame('1', $response->getData()['revision']);
            self::assertSame('A', $response->getData()['folder']);
        }
    }

	/** @return void framework authentication, administrator and CSRF defaults remain enabled */
	public function testFrameworkSecurityExemptionsAreAbsent(): void {
		self::assertSame([], (new \ReflectionClass(BackupSettingsController::class))->getAttributes());
		foreach (['show', 'update'] as $method) {
			self::assertSame([], (new \ReflectionMethod(BackupSettingsController::class, $method))->getAttributes());
		}
	}

	/** @return void malformed or missing revisions and untyped settings cannot reach the transaction writer */
	public function testInvalidRequestsNeverReachPolicyTransaction(): void {
		foreach ([[[], 428], [['expectedRevision' => '00', 'folder' => 'A', 'copies' => true], 400], [['expectedRevision' => '1', 'folder' => [], 'copies' => true], 400], [['expectedRevision' => '1', 'folder' => 'A', 'copies' => 'false'], 400]] as [$body, $status]) {
			$request = $this->createMock(IRequest::class);
			$request->method('getParam')->willReturnCallback(static fn ($key) => $body[$key] ?? null);
			$config = $this->createMock(IAppConfig::class);
			$location = new BackupLocation(\OCA\Mcp\Tests\Support\Access\BackupPolicy\BackupDocumentFixture::fromConfig($this, $config));
			$copies = new BackupCopies($location, $this->createMock(IAppManager::class), $this->createMock(IUserManager::class));
			$writer = $this->createMock(PolicyTransaction::class);
			$writer->expects(self::never())->method('runAdmin');
			$settings = new BackupSettingsWriter($location, $copies, $writer, $config, $this->createMock(IFilenameValidator::class));
			$controller = new BackupSettingsController('mcp', $request, $location, $copies, $settings, $this->createMock(PolicyStateMapper::class), $this->createMock(IUserSession::class));
			self::assertSame($status, $controller->update()->getStatus());
		}
	}
}
