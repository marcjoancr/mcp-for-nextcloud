<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Forms;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Forms\FormsNativeReader;
use OCA\Mcp\Modules\Forms\FormsOutput;
use OCA\Mcp\Modules\Forms\Integration\FormsOcsReader;
use OCP\App\IAppManager;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class FormsOcsReaderTest extends TestCase {
	public function testBasicReadsUseSameInstanceOcsAndProjectOnlySafeFields(): void {
		$requests = [];
		$client = $this->client($requests, [
			'/ocs/v2.php/cloud/user?format=json' => $this->ocs(['id' => 'alice'], 200),
			'/ocs/v2.php/apps/forms/api/v3/forms?type=owned&format=json' => ['ocs' => ['meta' => ['status' => 'ok', 'statuscode' => 100], 'data' => [
				['id' => 5, 'title' => 'Owned', 'permissions' => ['submit'], 'hash' => 'secret-link-token'],
			]]],
			'/ocs/v2.php/apps/forms/api/v3/forms?type=shared&format=json' => ['ocs' => ['meta' => ['status' => 'ok', 'statuscode' => 100], 'data' => [
				['id' => 6, 'title' => 'Shared', 'permissions' => ['submit'], 'hash' => 'another-secret-token'],
			]]],
		]);
		$reader = $this->reader('Basic YWxpY2U6YXBwLXBhc3N3b3Jk', true, $client);

		$forms = $reader->list('alice');

		self::assertSame([
			['id' => 5, 'title' => 'Owned', 'permissions' => ['submit']],
			['id' => 6, 'title' => 'Shared', 'permissions' => ['submit']],
		], $forms);
		self::assertCount(3, $requests);
		self::assertSame('Basic YWxpY2U6YXBwLXBhc3N3b3Jk', $requests[0]['options']['headers']['Authorization']);
		self::assertSame('true', $requests[0]['options']['headers']['OCS-APIRequest']);
		self::assertTrue($requests[0]['options']['verify']);
		self::assertFalse($requests[0]['options']['allow_redirects']);
		self::assertFalse($requests[0]['options']['http_errors']);
		self::assertTrue($requests[0]['options']['stream']);
		self::assertArrayNotHasKey('cookies', $requests[0]['options']);
		self::assertStringNotContainsString('secret-link-token', json_encode($forms, JSON_THROW_ON_ERROR));
	}

	public function testResultReadRequiresExplicitResultsPermissionAndDropsUnclassifiedSubmissions(): void {
		$requests = [];
		$client = $this->client($requests, [
			'/ocs/v2.php/cloud/user?format=json' => $this->ocs(['id' => 'alice']),
			'/ocs/v2.php/apps/forms/api/v3/forms/7?format=json' => ['__sequence' => [
				$this->ocs(['id' => 7, 'isAnonymous' => false, 'permissions' => ['results']]),
				$this->ocs(['id' => 7, 'isAnonymous' => false, 'permissions' => ['results']]),
			]],
			'/ocs/v2.php/apps/forms/api/v3/forms/7/submissions?limit=2&offset=0&format=json' => $this->ocs([
				'submissions' => [
					['id' => 30, 'formId' => 7, 'timestamp' => 1700000000, 'userId' => 'bob', 'answers' => [['questionId' => 9, 'text' => 'Visible answer']]],
					['id' => 29, 'formId' => 7, 'timestamp' => 1699999999, 'userId' => 'bob', 'answers' => [['questionId' => 99, 'text' => 'unclassified historical file name']]],
			],
				'questions' => [['id' => 9, 'type' => 'short', 'text' => 'Question'], ['id' => 10, 'type' => 'file', 'text' => 'Upload']],
				'filteredSubmissionsCount' => 3,
			]),
		]);
		$reader = $this->reader('Basic YWxpY2U6YXBwLXBhc3N3b3Jk', true, $client);

		$page = $reader->readResponses('alice', 7, 0, 2);

		self::assertIsArray($page);
		self::assertSame([30], array_column($page['submissions'], 'id'));
		self::assertSame([10], $page['fileQuestionIds']);
		$projected = FormsOutput::responses($page, 0, 2);
		self::assertTrue($projected['hasMore']);
		self::assertSame(2, $projected['nextOffset'], 'advance by source rows, including rows safely omitted as unclassified');
		self::assertCount(4, $requests);
		self::assertStringNotContainsString('unclassified historical file name', json_encode($page, JSON_THROW_ON_ERROR));
	}

	public function testResultsReadDoesNotRequestSubmissionsWhenResultsPermissionIsAbsent(): void {
		$requests = [];
		$client = $this->client($requests, [
			'/ocs/v2.php/cloud/user?format=json' => $this->ocs(['id' => 'alice']),
			'/ocs/v2.php/apps/forms/api/v3/forms/7?format=json' => $this->ocs([
				'id' => 7, 'isAnonymous' => false, 'permissions' => ['submit'],
			]),
		]);
		$reader = $this->reader('Basic YWxpY2U6YXBwLXBhc3N3b3Jk', true, $client);

		self::assertNull($reader->readResponses('alice', 7, 0, 20));
		self::assertCount(2, $requests);
	}

	public function testAResultPermissionRevokedDuringOcsReadDiscardsTheFetchedPage(): void {
		$requests = [];
		$client = $this->client($requests, [
			'/ocs/v2.php/cloud/user?format=json' => $this->ocs(['id' => 'alice']),
			'/ocs/v2.php/apps/forms/api/v3/forms/7?format=json' => ['__sequence' => [
				$this->ocs(['id' => 7, 'isAnonymous' => false, 'permissions' => ['results']]),
				$this->ocs(['id' => 7, 'isAnonymous' => false, 'permissions' => ['submit']]),
			]],
			'/ocs/v2.php/apps/forms/api/v3/forms/7/submissions?limit=2&offset=0&format=json' => $this->ocs([
				'submissions' => [['id' => 30, 'formId' => 7, 'timestamp' => 1700000000, 'userId' => 'bob', 'answers' => [['questionId' => 9, 'text' => 'Secret answer']]]],
				'questions' => [['id' => 9, 'type' => 'short', 'text' => 'Question']],
				'filteredSubmissionsCount' => 1,
			]),
		]);
		$reader = $this->reader('Basic YWxpY2U6YXBwLXBhc3N3b3Jk', true, $client);

		try {
			$reader->readResponses('alice', 7, 0, 2);
			self::fail('A page read across permission revocation must be discarded.');
		} catch (ToolFailure) {
			self::assertStringNotContainsString('Secret answer', json_encode($requests, JSON_THROW_ON_ERROR));
		}
	}

	public function testDisabledModeAndMcpBearerSelectNativeReaderBeforeAnyOcsRequest(): void {
		foreach ([['Basic YWxpY2U6YXBwLXBhc3N3b3Jk', false], ['Bearer ncmcp_at_test', true]] as [$authorization, $officialMode]) {
			$requests = [];
			$client = $this->client($requests, []);
			$reader = $this->reader($authorization, $officialMode, $client);

			self::assertSame([['id' => 1, 'title' => 'Native', 'permissions' => ['submit']]], $reader->list('alice'));
			self::assertSame([], $requests);
		}
	}

	public function testOcsIdentityMismatchFailsWithoutFallingBackToNativeReader(): void {
		$requests = [];
		$client = $this->client($requests, [
			'/ocs/v2.php/cloud/user?format=json' => $this->ocs(['id' => 'bob']),
		]);
		$reader = $this->reader('Basic Ym9iOmFwcC1wYXNzd29yZA==', true, $client);

		$this->expectException(ToolFailure::class);
		$reader->list('alice');
	}

	/** @param array<string, mixed> $data */
	private function ocs(array $data, int $statusCode = 100): array {
		return ['ocs' => ['meta' => ['status' => 'ok', 'statuscode' => $statusCode], 'data' => $data]];
	}

	/** @param list<array{url:string,options:array}> $requests @param array<string,array> $responses */
	private function client(array &$requests, array $responses): IClientService {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(function (string $url, array $options) use (&$requests, &$responses): IResponse {
			$requests[] = ['url' => $url, 'options' => $options];
			$path = parse_url($url, PHP_URL_PATH) . (parse_url($url, PHP_URL_QUERY) !== null ? '?' . parse_url($url, PHP_URL_QUERY) : '');
			if (!isset($responses[$path])) {
				throw new \RuntimeException('Unexpected OCS path: ' . $path);
			}
			$body = $responses[$path];
			if (isset($responses[$path]['__sequence'])) {
				$body = array_shift($responses[$path]['__sequence']);
			}
			$response = $this->createMock(IResponse::class);
			$response->method('getStatusCode')->willReturn(200);
			$response->method('getBody')->willReturn(json_encode($body, JSON_THROW_ON_ERROR));
			return $response;
		});
		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($client);
		return $service;
	}

	private function reader(string $authorization, bool $officialMode, IClientService $clients): FormsOcsReader {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->with('app.mcp.official-apps.forms', false)->willReturn($officialMode);
		$config->method('getSystemValueString')->with('overwrite.cli.url', '')->willReturn('https://cloud.example.test');
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('Authorization')->willReturn($authorization);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn(string $path): string => 'https://cloud.example.test/' . ltrim($path, '/'));
		$user = $this->createMock(IUser::class);
		$user->method('isEnabled')->willReturn(true);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->with('forms', $user)->willReturn(true);
		$appManager->method('getAppVersion')->with('forms')->willReturn('5.4.2');
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->with('alice')->willReturn($user);
		$nativeService = new class {
			public function getSharedForms(IUser $user): array { return []; }
			public function getPermissions(OcsFormStub $form): array { return ['submit']; }
		};
		$formMapper = new class {
			public function findAllByOwnerId(string $uid): array { return [new OcsFormStub(1, 'Native')]; }
		};
		$native = new FormsNativeReader($appManager, $userManager, static fn(string $name): object => match ($name) {
			'OCA\\Forms\\Service\\FormsService' => $nativeService,
			'OCA\\Forms\\Db\\FormMapper' => $formMapper,
			default => throw new \RuntimeException('Unexpected native Forms service.'),
		}, static fn(): ?string => 'alice');
		return new FormsOcsReader($config, $request, $urls, $clients, $appManager, $userManager, $session, $native,
			static fn () => throw new \LogicException('Legacy readers must not resolve the OCS controller.'));
	}
}

final class OcsFormStub {
	public function __construct(private int $id, private string $title) {}
	public function getId(): int { return $this->id; }
	public function getTitle(): string { return $this->title; }
}
