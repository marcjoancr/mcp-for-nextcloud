<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Deck\Dependencies;

use OCA\Mcp\Modules\Deck\Access\DeckCapabilities;



use OCP\App\IAppManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/../../../../Support/Deck/Stubs/deck_stubs.php';

final class DependencyCapabilitiesTest extends TestCase {
	private function apps(string $version): IAppManager {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->willReturn($version);
		$apps->method('isEnabledForAnyone')->willReturn(true);
		$apps->method('isEnabledForUser')->willReturn(true);
		return $apps;
	}

	public static function cells(): iterable {
		foreach ([[32, '1.16.8', false], [33, '1.17.7', false], [34, '1.18.5', true], [35, '1.19.0', true],
			[34, '1.18.4', false], [35, '', false], [34, '1.19.0', false], [35, '1.20.0', false],
			[36, '1.20.0', false], [35, '1.19.1', true]] as $cell) {
			yield $cell;
		}
	}

	#[DataProvider('cells')]
	public function testVersionAndNativeShapeGate(int $major, string $version, bool $expected): void {
		$cap = new DeckCapabilities($this->apps($version), $major, static fn (): bool => true);
		self::assertSame($expected, $cap->dependencies());
	}

	public static function members(): iterable {
		foreach (['assignDependentCard', 'removeDependentCard', 'findDependenciesForCards', 'dependentCards'] as $member) {
			yield [$member];
		}
	}

	#[DataProvider('members')]
	public function testDeclaredVersionWithoutAnyRequiredMemberFailsClosed(string $missing): void {
		$cap = new DeckCapabilities($this->apps('1.18.5'), 34,
			static fn (string $class, string $member): bool => $member !== $missing);
		self::assertFalse($cap->dependencies());
	}

	public function testCapabilitiesAreRecheckedRatherThanCachedBetweenCalls(): void {
		$version = '1.19.0';
		$present = true;
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->willReturnCallback(function () use (&$version): string { return $version; });
		$apps->method('isEnabledForAnyone')->willReturn(true);
		$capability = new DeckCapabilities($apps, 35, function () use (&$present): bool { return $present; });
		self::assertTrue($capability->dependencies());
		$present = false;
		self::assertFalse($capability->dependencies());
		$present = true;
		$version = '';
		self::assertFalse($capability->dependencies());
	}


    public function testDiscoveryPreviewAndExecutionRecheckWithoutResolvingDeck(): void {
        $enabled=true;
        $apps=$this->apps('1.18.5');
        $cap=new DeckCapabilities($apps,34,function()use(&$enabled):bool{return $enabled;});
        $factories=[];
        foreach (\OCA\Mcp\Modules\Deck\DeckToolCatalog::definitions() as $name=>$definition) {
            $factories[$name]=static fn()=>throw new \LogicException('Deck must not resolve');
        }
        $module=new \OCA\Mcp\Modules\Deck\DeckModule($factories,new \OCA\Mcp\Modules\Deck\Access\DeckAvailability($apps),$cap);
        self::assertContains('deck_read_card_dependencies',array_column($module->definitions(),'name'));
        self::assertStringContainsString('outgoing',implode(' ',$module->guideNotes()));
        $enabled=false;
        self::assertNotContains('deck_read_card_dependencies',array_column($module->definitions(),'name'));
        self::assertStringNotContainsString('outgoing',implode(' ',$module->guideNotes()));
        foreach(['call','preview'] as $method){
            try{$module->$method('deck_add_card_dependency',['cardId'=>7,'targetId'=>8],'alice');self::fail('unsupported call accepted');}
            catch(\InvalidArgumentException $e){self::assertStringContainsString('unavailable',$e->getMessage());}
        }
        self::assertNotContains('deck_link_card_file',array_keys(\OCA\Mcp\Modules\Deck\DeckToolCatalog::definitions()));
    }
}
