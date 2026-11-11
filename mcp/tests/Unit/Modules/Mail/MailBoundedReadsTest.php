<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Mail;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Modules\Mail\Integration\MailBoundedReads;
use OCA\Mcp\Modules\Mail\Integration\MailNativeBoundary;
use OCA\Mcp\Modules\Mail\Integration\MailServices;
use OCA\Mcp\Modules\Mail\Tools\ReadMessageMetadata;
use OCA\Mcp\Modules\Mail\Tools\ReadOutbox;
use OCP\App\IAppManager;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

final class MailBoundedReadsTest extends TestCase {
	public function testDirectOutboxExecutionRejectsValuesOutsideThePublishedIntegerContract(): void {
		$reads = $this->reads();
		foreach ([['account_id' => null], ['account_id' => '7'], ['limit' => null], ['limit' => 51], ['before_id' => '10'], ['unexpected' => true]] as $arguments) {
			try {
				$reads->outbox('alice', $arguments);
				self::fail('Invalid direct outbox arguments were accepted.');
			} catch (ToolFailure) {
				self::assertTrue(true);
			}
		}
	}

	public function testDirectMetadataExecutionRejectsDuplicateAndNonIntegerIds(): void {
		$reads = $this->reads();
		foreach ([[], ['message_ids' => []], ['message_ids' => [1, 1]], ['message_ids' => [1, '2']], ['message_ids' => [0]], ['message_ids' => range(1, 51)]] as $arguments) {
			try {
				$reads->messageMetadata('alice', $arguments);
				self::fail('Invalid direct metadata arguments were accepted.');
			} catch (ToolFailure) {
				self::assertTrue(true);
			}
		}
	}

	public function testDisabledMailRefusesBeforeReadingNativeBoundaryOrResolvingAMapper(): void {
		$services = $this->createMock(MailServices::class);
		$services->expects(self::once())->method('isEnabledFor')->with('alice')->willReturn(false);
		$services->expects(self::never())->method('accountService');
		$apps = $this->createMock(IAppManager::class);
		$config = $this->createMock(IConfig::class);
		$config->expects(self::never())->method('getSystemValueString');
		$reads = new MailBoundedReads($services, new MailNativeBoundary($apps), $config, access: new \OCA\Mcp\Modules\Mail\Access\MailAccessGuard(new \OCA\Mcp\Tests\Support\Dav\ScopeStub()));

		try {
			$reads->outbox('alice', []);
			self::fail('Disabled Mail was admitted to bounded reads.');
		} catch (ToolFailure) {
			self::assertTrue(true);
		}
	}

	public function testSupportedVersionPairStillNeedsTheExactNativeMetadataSource(): void {
		$services = $this->createMock(MailServices::class);
		$services->expects(self::once())->method('isEnabledFor')->with('alice')->willReturn(true);
		$services->expects(self::never())->method('boundedMetadata');
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->with('mail')->willReturn('5.12.5.10');
		$apps->expects(self::atMost(1))->method('getAppPath')->with('mail')->willReturn('/missing-native-mail-source');
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->with('version', '')->willReturn('35.0.2');
		$reads = new MailBoundedReads($services, new MailNativeBoundary($apps), $config, access: new \OCA\Mcp\Modules\Mail\Access\MailAccessGuard(new \OCA\Mcp\Tests\Support\Dav\ScopeStub()));

		try {
			$reads->outbox('alice', []);
			self::fail('A matching version without the exact native metadata source was admitted.');
		} catch (ToolFailure) {
			self::assertTrue(true);
		}
	}

	public function testToolsDelegateBoundedOwnedReadsAndRemoveUnexpectedPrivateNativeFields(): void {
		$native = new class {
			public array $calls = [];

			public function outbox(string $uid, ?int $accountId, int $limit, ?int $beforeId): array {
				$this->calls['outbox'] = [$uid, $accountId, $limit, $beforeId];
				return ['items' => [[
					'id' => 42, 'account_id' => 7, 'subject' => 'queued', 'subject_truncated' => false,
					'failed' => false, 'send_at' => null, 'updated_at' => 123,
					'body' => 'private body', 'recipients' => ['secret@example.test'], 'attachment_bytes' => 'private bytes',
				]], 'next_before_id' => 39];
			}

			public function cachedMessages(string $uid, array $ids): array {
				$this->calls['messages'] = [$uid, $ids];
				return [
					['id' => 2, 'account_id' => 7, 'mailbox_id' => 5, 'subject' => 'older', 'subject_truncated' => false,
						'sent_at' => 100, 'seen' => true, 'flagged' => false, 'body' => 'private body', 'preview' => 'private preview',
						'recipients' => ['private'], 'attachment_bytes' => 'private bytes'],
					['id' => 19, 'account_id' => 7, 'mailbox_id' => 5, 'subject' => 'newer', 'subject_truncated' => true,
						'sent_at' => 200, 'seen' => false, 'flagged' => true, 'body' => 'private body', 'preview' => 'private preview',
						'recipients' => ['private'], 'attachment_bytes' => 'private bytes'],
					['id' => 99, 'account_id' => 7, 'mailbox_id' => 5, 'subject' => 'not requested', 'subject_truncated' => false,
						'sent_at' => 300, 'seen' => false, 'flagged' => false],
				];
			}
		};
		$services = $this->createMock(MailServices::class);
		$services->method('isEnabledFor')->with('alice')->willReturn(true);
		$services->method('boundedMetadata')->with('alice')->willReturn($native);
		foreach (['accountService', 'mailManager', 'clientFactory', 'smtpFactory'] as $method) {
			$services->expects(self::never())->method($method);
		}
		$boundary = new class($this->createMock(IAppManager::class)) extends MailNativeBoundary {
			public array $coreVersions = [];
			public int $sourceChecks = 0;

			public function runtimeMatches(string $core): bool {
				$this->coreVersions[] = $core;
				return true;
			}

			public function boundedMetadataSourceVerified(): bool {
				$this->sourceChecks++;
				return true;
			}
		};
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->with('version', '')->willReturn('35.0.2');
		$reads = new MailBoundedReads($services, $boundary, $config, access: new \OCA\Mcp\Modules\Mail\Access\MailAccessGuard(new \OCA\Mcp\Tests\Support\Dav\ScopeStub()));

		$outbox = new ReadOutbox($reads)->execute(['account_id' => 7, 'limit' => 3, 'before_id' => 99], new ToolContext('alice'));
		$outboxResult = json_decode($outbox['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
		self::assertSame(['alice', 7, 3, 99], $native->calls['outbox']);
		self::assertSame(39, $outboxResult['next_before_id']);
		self::assertSame(['id', 'account_id', 'subject', 'subject_truncated', 'failed', 'send_at', 'updated_at'], array_keys($outboxResult['items'][0]));
		self::assertStringNotContainsString('private body', $outbox['content'][0]['text']);
		self::assertStringNotContainsString('secret@example.test', $outbox['content'][0]['text']);
		self::assertStringNotContainsString('private bytes', $outbox['content'][0]['text']);

		$metadata = new ReadMessageMetadata($reads)->execute(['message_ids' => [19, 2]], new ToolContext('alice'));
		$metadataResult = json_decode($metadata['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
		self::assertSame(['alice', [19, 2]], $native->calls['messages']);
		self::assertSame([2, 19], array_column($metadataResult['items'], 'id'));
		self::assertSame(['id', 'account_id', 'mailbox_id', 'subject', 'subject_truncated', 'sent_at', 'seen', 'flagged'], array_keys($metadataResult['items'][0]));
		self::assertStringNotContainsString('private body', $metadata['content'][0]['text']);
		self::assertStringNotContainsString('private preview', $metadata['content'][0]['text']);
		self::assertStringNotContainsString('private bytes', $metadata['content'][0]['text']);
		self::assertSame(['35.0.2', '35.0.2'], $boundary->coreVersions);
		self::assertSame(2, $boundary->sourceChecks);
	}

    public function testLegacyOutboxCannotSilentlyIgnoreTheNewScopeArgument():void {
        $native=new class {public int $calls=0;public function outbox(string $uid,?int $account,int $limit,?int $before):array{$this->calls++;return ['items'=>[],'next_before_id'=>null];}};
        $services=$this->createMock(MailServices::class);$services->method('isEnabledFor')->willReturn(true);$services->method('boundedMetadata')->willReturn($native);
        $boundary=$this->createMock(MailNativeBoundary::class);$boundary->method('runtimeMatches')->willReturn(true);$boundary->method('boundedMetadataSourceVerified')->willReturn(true);
        $scope=(new \OCA\Mcp\Tests\Support\Dav\ScopeStub())->restrict('mail',[]);
        $reads=new MailBoundedReads($services,$boundary,$this->createMock(IConfig::class),new \OCA\Mcp\Modules\Mail\Access\MailAccessGuard($scope));
        $denied=false;try{$reads->outbox('alice',[]);}catch(ToolFailure){$denied=true;}
        self::assertTrue($denied);self::assertSame(0,$native->calls);
        $scope->restricted=[];self::assertSame([],$reads->outbox('alice',[])['items']);self::assertSame(1,$native->calls);
    }

	private function reads(): MailBoundedReads {
		$services = $this->createMock(MailServices::class);
		$apps = $this->createMock(IAppManager::class);
		return new MailBoundedReads($services, new MailNativeBoundary($apps), $this->createMock(IConfig::class), access: new \OCA\Mcp\Modules\Mail\Access\MailAccessGuard(new \OCA\Mcp\Tests\Support\Dav\ScopeStub()));
	}
}
