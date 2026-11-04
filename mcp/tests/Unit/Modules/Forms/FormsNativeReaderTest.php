<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Forms;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Forms\FormsNativeReader;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

final class FormsNativeReaderTest extends TestCase {
    public function testListsOwnedAndSharedFormsThroughNativeServicesAndDeduplicatesIds(): void {
        $user = $this->createMock(IUser::class);
        $formA = new FormStub(1, 'Alpha');
        $formB = new FormStub(2, 'Beta');
        $formMapper = new class([$formA]) {
            public function __construct(private array $forms) {
            }

            public function findAllByOwnerId(string $uid): array {
                return $this->forms;
            }
        };
        $service = new class([$formB, $formA]) {
            public array $permissionChecks = [];

            public function __construct(private array $shared) {
            }

            public function getSharedForms(IUser $user): array {
                return $this->shared;
            }

            public function getPermissions(FormStub $form): array {
                $this->permissionChecks[] = $form->getId();
                return ['submit'];
            }
        };
        $reader = $this->reader($user, [
            'OCA\\Forms\\Db\\FormMapper' => $formMapper,
            'OCA\\Forms\\Service\\FormsService' => $service,
        ]);

        $forms = $reader->list('alice');

        self::assertSame([1, 2], $service->permissionChecks);
        self::assertSame([
            ['id' => 1, 'title' => 'Alpha', 'permissions' => ['submit']],
            ['id' => 2, 'title' => 'Beta', 'permissions' => ['submit']],
        ], $forms);
    }

    public function testReadUsesNativeSubmitPermissionAndReturnsNativeProjection(): void {
        $user = $this->createMock(IUser::class);
        $form = new \stdClass();
        $service = new class($form) {
            public ?array $lookup = null;

            public function __construct(private object $form) {
            }

            public function getFormIfAllowed(int $id, string $permission): object {
                $this->lookup = [$id, $permission];
                return $this->form;
            }
            public function getForm(object $form): array {
                return ['id' => 12, 'questions' => []];
            }
        };
        $reader = $this->reader($user, ['OCA\\Forms\\Service\\FormsService' => $service]);

        self::assertSame(['id' => 12, 'questions' => []], $reader->read('alice', 12));
        self::assertSame([12, 'submit'], $service->lookup);
    }

    public function testReadsResponsesOnlyAfterNativeResultsAuthorizationAndUsesBoundedNativeQuery(): void {
        $user = $this->createMock(IUser::class);
        $form = new class {
            public function getIsAnonymous(): bool { return true; }
        };
        $forms = new class($form) {
            public ?array $lookup = null;
            public function __construct(private object $form) {}
            public function getFormIfAllowed(int $id, string $permission): object {
                $this->lookup = [$id, $permission];
                return $this->form;
            }
            public function getPermissions(object $form): array { return ['results']; }
            public function getQuestions(int $id): array {
                return [['id' => 8, 'type' => 'file'], ['id' => 9, 'type' => 'text']];
            }
        };
        $submissions = new class {
            public ?array $query = null;
            public function getSubmissions(int $formId, ?string $userId, ?string $query, ?int $limit, int $offset): array {
                $this->query = [$formId, $userId, $query, $limit, $offset];
                return [];
            }
        };
        $reader = $this->reader($user, [
            'OCA\\Forms\\Service\\FormsService' => $forms,
            'OCA\\Forms\\Service\\SubmissionService' => $submissions,
        ]);

        self::assertSame(['formId' => 12, 'isAnonymous' => true, 'fileQuestionIds' => [8], 'questions' => [['id' => 8, 'type' => 'file'], ['id' => 9, 'type' => 'text']], 'questionsTruncated' => false, 'submissions' => []], $reader->readResponses('alice', 12, 20, 10));
        self::assertSame([12, 'results'], $forms->lookup);
        self::assertSame([12, null, null, 11, 20], $submissions->query);
    }

    public function testSubmitterWithoutExplicitResultsPermissionCannotReadTheSharedResponseSet(): void {
        $user = $this->createMock(IUser::class);
        $forms = new class {
            public function getFormIfAllowed(int $id, string $permission): object { return new \stdClass(); }
            public function getPermissions(object $form): array { return ['submit']; }
        };
        $reader = $this->reader($user, ['OCA\\Forms\\Service\\FormsService' => $forms]);
        self::assertNull($reader->readResponses('alice', 12, 0, 50));
    }

    public function testHidesNativeMissingAndForbiddenResultFormsAsNull(): void {
        if (!class_exists('OCA\\Forms\\Exception\\NoSuchFormException')) {
            class_alias(FormNoSuchFormException::class, 'OCA\\Forms\\Exception\\NoSuchFormException');
        }
        $user = $this->createMock(IUser::class);
        $service = new class {
            public function getFormIfAllowed(int $id, string $permission): never {
                throw new FormNoSuchFormException('forbidden or missing');
            }
        };
        $reader = $this->reader($user, ['OCA\\Forms\\Service\\FormsService' => $service]);
        self::assertNull($reader->readResponses('alice', 12, 0, 10));
    }

    public function testRejectsMismatchedSessionBeforeResolvingFormsServices(): void {
        $user = $this->createMock(IUser::class);
        $serviceCalled = false;
        $reader = $this->reader($user, [], 'bob', $serviceCalled);

        try {
            $reader->list('alice');
            self::fail('A mismatched session must fail closed');
        } catch (ToolFailure $e) {
            self::assertSame(ToolFailure::FORBIDDEN, $e->getMessage());
        }
        self::assertFalse($serviceCalled);
    }

    public function testRejectsMismatchedSessionBeforeResolvingResponseServices(): void {
        $user = $this->createMock(IUser::class);
        $serviceCalled = false;
        $reader = $this->reader($user, [], 'bob', $serviceCalled);
        try {
            $reader->readResponses('alice', 1, 0, 10);
            self::fail('A mismatched session must not read submissions');
        } catch (ToolFailure $e) {
            self::assertSame(ToolFailure::FORBIDDEN, $e->getMessage());
        }
        self::assertFalse($serviceCalled);
    }

    private function reader(IUser $user, array $services, string $sessionUid = 'alice', ?bool &$serviceCalled = null): FormsNativeReader {
        $apps = $this->createMock(IAppManager::class);
        $apps->method('isEnabledForUser')->with('forms', $user)->willReturn(true);
        $apps->method('getAppVersion')->with('forms')->willReturn('5.3.0');
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->with('alice')->willReturn($user);
        $service = static function (string $class) use ($services, &$serviceCalled): object {
            if ($serviceCalled !== null) {
                $serviceCalled = true;
            }
            return $services[$class];
        };

        return new FormsNativeReader($apps, $users, $service, static fn(): ?string => $sessionUid);
    }
}

final class FormNoSuchFormException extends \RuntimeException {}

final class FormStub {
    public function __construct(private int $id, private string $title = 'Form') {
    }

    public function getId(): int {
        return $this->id;
    }

    public function getTitle(): string {
        return $this->title;
    }
}
