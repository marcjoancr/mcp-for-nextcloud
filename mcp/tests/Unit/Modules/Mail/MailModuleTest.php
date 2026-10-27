<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Mail;

use InvalidArgumentException;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore;
use OCA\Mcp\Modules\Mail\Integration\MailGateway;
use OCA\Mcp\Modules\Mail\MailModule;
use OCA\Mcp\Mcp\Confirmation\PreviewsWrites;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCA\Mcp\Mcp\Confirmation\WriteGate;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The Mail module publishes two read tools and nothing else, the grant catalog offers only reading Mail, and the
 * registry hides both tools while Mail is off for the user or older than MCP supports.
 */
final class MailModuleTest extends TestCase {
	/** The tools of the module, in tools/list order. */
	private const TOOLS = ['mail_list_accounts', 'mail_list_mailboxes'];

	/**
	 * Both tools read, carry the Mail app id and never ask for confirm.
	 *
	 * @return void
	 */
	public function testTheModulePublishesTwoReadToolsOfTheMailApp(): void {
		$definitions = $this->module()->definitions();

		$this->assertSame(self::TOOLS, array_column($definitions, 'name'));
		foreach ($definitions as $definition) {
			$this->assertSame('mail', $definition['module']);
			$this->assertSame('read', $definition['operation']);
			$this->assertSame('mail', $definition['app']);
			$this->assertFalse(WriteGate::isWrite($definition), $definition['name']);
			$this->assertArrayNotHasKey('confirm', (array)$definition['inputSchema']['properties']);
			$this->assertFalse($definition['inputSchema']['additionalProperties']);
		}
		$this->assertSame(['account_id'], $definitions[1]['inputSchema']['required']);
		$this->assertFalse(is_subclass_of(MailModule::class, PreviewsWrites::class));
	}

	/**
	 * The grant catalog has only the read of Mail, denied until explicitly granted.
	 *
	 * @return void
	 */
	public function testTheCatalogOffersOnlyReadingMail(): void {
		$this->assertSame(['read'], GrantPolicy::CATALOG['mail']);
		$this->assertContains('mail', GrantPolicy::DENIED_READS);
		$policy = InMemoryConfig::policy((new InMemoryConfig())->mock($this), new InMemoryOAuthStore());
		$this->assertFalse($policy->granted('alice', 'mail', 'read'));
        $this->assertTrue($policy->granted('alice', 'files', 'read'));
        $policy->setGrant('alice', 'mail', 'read', true);
        $this->assertTrue($policy->granted('alice', 'mail', 'read'));
        $this->assertFalse($policy->granted('bob', 'mail', 'read'));
	}

	/**
	 * A tool name the module does not have is an invalid call.
	 *
	 * @return void
	 */
	public function testAnUnknownToolIsRejected(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->module()->call('mail_send', [], 'alice');
	}

	/**
	 * The registry lists both tools when Mail 5.0.0 or later is enabled for the user.
	 *
	 * @return void
	 */
	public function testTheToolsAreListedWhenMailIsEnabledInASupportedVersion(): void {
		$this->assertSame(self::TOOLS, $this->listed(true, '5.0.0'));
		$this->assertSame(self::TOOLS, $this->listed(true, '5.12.5'));
	}

	/**
	 * The registry hides both tools when Mail is off for the user or older than the minimum, and refuses a call.
	 *
	 * @return void
	 */
	public function testTheToolsAreHiddenWhenMailIsOffOrTooOld(): void {
		$this->assertSame([], $this->listed(false, '5.12.5'));
		$this->assertSame([], $this->listed(true, '4.4.0'));
        $this->assertSame([], $this->listed(true, ''));

		$this->expectException(InvalidArgumentException::class);
		$this->registry(false, '5.12.5')->call('mail_list_accounts', [], 'alice');
	}

	/**
	 * Names of the Mail tools the registry lists for alice.
	 *
	 * @param bool $enabled whether Mail is enabled for the user
	 * @param string $version installed Mail version
	 * @return list<string> listed tool names
	 */
	private function listed(bool $enabled, string $version): array {
		return array_values(array_intersect(array_column($this->registry($enabled, $version)->list('alice'), 'name'), self::TOOLS));
	}

	/**
	 * The registry with the Mail module only.
	 *
	 * @param bool $enabled whether Mail is enabled for the user
	 * @param string $version installed Mail version
	 * @return ToolRegistry the registry
	 */
	private function registry(bool $enabled, string $version): ToolRegistry {
		$policy = InMemoryConfig::policy((new InMemoryConfig())->mock($this), new InMemoryOAuthStore());
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturnCallback(static fn (string $app): bool => $app === 'mail' && $enabled);
		$apps->method('getAppVersion')->willReturn($version);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(IUser::class));
		$policy->setGrant('alice', 'mail', 'read', true);
        $availability = new \OCA\Mcp\Mcp\Tools\Discovery\ToolAvailability($policy, $apps, $users);
        return new ToolRegistry([$this->module()], $availability, new \OCA\Mcp\Mcp\Tools\Execution\ToolExecutor($availability, $this->createMock(LoggerInterface::class)));
	}
    private function module(): MailModule {
        $reader = new MailGateway(new FakeMailApp());
        $rows = new \OCA\Mcp\Modules\Mail\Presentation\MailRows();
        return new MailModule([
            'mail_list_accounts' => static fn () => new \OCA\Mcp\Modules\Mail\Tools\ListAccounts(new \OCA\Mcp\Modules\Mail\Accounts\AccountLister($reader, $rows)),
            'mail_list_mailboxes' => static fn () => new \OCA\Mcp\Modules\Mail\Tools\ListMailboxes(new \OCA\Mcp\Modules\Mail\Mailboxes\MailboxLister($reader, $rows)),
        ]);
    }

    public function testMetadataDoesNotResolveOptionalServices(): void {
        $factory = static fn () => throw new \LogicException('Unexpected resolution');
        $module = new MailModule(['mail_list_accounts' => $factory, 'mail_list_mailboxes' => $factory]);
        self::assertCount(2, $module->definitions());
        self::assertNotEmpty($module->guideNotes());
    }
}
