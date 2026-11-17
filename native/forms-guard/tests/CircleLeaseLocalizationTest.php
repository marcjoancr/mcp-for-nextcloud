<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);

namespace OC\L10N {
    require_once dirname(__DIR__, 3) . '/mcp/tests/bootstrap.php';

    final class Factory implements \OCP\L10N\IFactory {
        public array $apps = [];
        public function __construct(private \OCP\IL10N $translation) {}
        public function get($app, $lang = null, $locale = null) { $this->apps[] = $app; return $this->translation; }
        public function findLanguage(?string $appId = null): string { return 'en'; }
        public function findGenericLanguage(?string $appId = null): string { return 'en'; }
        public function findLocale($lang = null): string { return 'en_US'; }
        public function findLanguageFromLocale(string $app = 'core', ?string $locale = null): ?string { return null; }
        public function findAvailableLanguages($app = null): array { return []; }
        public function findAvailableLocales(): array { return []; }
        public function languageExists($app, $lang): bool { return false; }
        public function localeExists($locale): bool { return false; }
        public function getLanguageDirection(string $language): string { return 'ltr'; }
        public function getLanguageIterator(?\OCP\IUser $user = null): \OCP\L10N\ILanguageIterator { throw new \LogicException(); }
        public function getLanguages(): array { return []; }
        public function getUserLanguage(?\OCP\IUser $user = null): string { return 'en'; }
    }
}

namespace {
    require_once dirname(__DIR__) . '/src/Service/CircleDetached/Lease.php';

    if (!class_exists(\OC::class, false)) {
        class OC {
            public static object $server;
        }
    }

    final class CircleLeaseLocalizationContainer {
        public array $lookups = [];
        public function __construct(private object $service) {}
        public function get(string $class): object {
            $this->lookups[] = $class;
            return $this->service;
        }
    }

    final class CircleLeaseWrongLocalizationFactory implements \OCP\L10N\IFactory {
        public function get($app, $lang = null, $locale = null) { throw new \LogicException('unqualified factory must not be called'); }
        public function findLanguage(?string $appId = null): string { return 'en'; }
        public function findGenericLanguage(?string $appId = null): string { return 'en'; }
        public function findLocale($lang = null): string { return 'en_US'; }
        public function findLanguageFromLocale(string $app = 'core', ?string $locale = null): ?string { return null; }
        public function findAvailableLanguages($app = null): array { return []; }
        public function findAvailableLocales(): array { return []; }
        public function languageExists($app, $lang): bool { return false; }
        public function localeExists($locale): bool { return false; }
        public function getLanguageDirection(string $language): string { return 'ltr'; }
        public function getLanguageIterator(?\OCP\IUser $user = null): \OCP\L10N\ILanguageIterator { throw new \LogicException(); }
        public function getLanguages(): array { return []; }
        public function getUserLanguage(?\OCP\IUser $user = null): string { return 'en'; }
    }

    final class CircleLeaseLocalizationTest extends \PHPUnit\Framework\TestCase {
        private object $previousServer;

        protected function setUp(): void {
            $this->previousServer = isset(\OC::$server) ? \OC::$server : new \stdClass();
        }

        protected function tearDown(): void {
            \OC::$server = $this->previousServer;
        }

        private function lease(): \OCA\Forms\Mcp\Service\CircleDetached\Lease {
            $reflection = new \ReflectionClass(\OCA\Forms\Mcp\Service\CircleDetached\Lease::class);
            $lease = $reflection->newInstanceWithoutConstructor();
            (new \ReflectionProperty($lease, 'started'))->setValue($lease, hrtime(true));
            return $lease;
        }

        public function testLeaseReturnsOnlyThePinnedCirclesLocalization(): void {
            $translation = $this->createMock(\OCP\IL10N::class);
            $factory = new \OC\L10N\Factory($translation);
            $container = new CircleLeaseLocalizationContainer($factory);
            \OC::$server = $container;

            self::assertSame($translation, $this->lease()->circleLocalization());
            self::assertSame([\OCP\L10N\IFactory::class], $container->lookups);
            self::assertSame(['circles'], $factory->apps);
        }

        public function testUnqualifiedFactoryAndGenericServiceStayDenied(): void {
            $container = new CircleLeaseLocalizationContainer(new CircleLeaseWrongLocalizationFactory());
            \OC::$server = $container;
            try {
                $this->lease()->circleLocalization();
                self::fail('Unpinned localization factory must be rejected');
            } catch (\RuntimeException) {
                self::assertSame([\OCP\L10N\IFactory::class], $container->lookups);
            }

            $container->lookups = [];
            $this->expectException(\RuntimeException::class);
            $this->lease()->service(\OCP\L10N\IFactory::class);
        }

        public function testClosedLeaseCannotResolveLocalization(): void {
            $container = new CircleLeaseLocalizationContainer(new CircleLeaseWrongLocalizationFactory());
            \OC::$server = $container;
            $lease = $this->lease();
            (new \ReflectionProperty($lease, 'active'))->setValue($lease, false);

            $this->expectException(\RuntimeException::class);
            $lease->circleLocalization();
        }
    }
}
