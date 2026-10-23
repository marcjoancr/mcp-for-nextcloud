<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Access;

use Closure;
use OCA\Deck\Db\Card;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Service\CardService;
use OCA\Mcp\Access\AppAvailability\AppEnablement;
use OCP\App\IAppManager;
use OCP\Util;

/** Fail-closed, lazy native API detection; no Deck service is instantiated for catalogue discovery. */
final class DeckCapabilities {
	/** Qualification targets and admission floors; the range does not claim every patch was tested. */
	public const QUALIFICATION_TARGETS = [34 => '1.18.5', 35 => '1.19.0'];

	private ?Closure $nativeProbe;

	/**
	 * @param IAppManager $apps Installed/enabled Deck version.
	 * @param int|null $nextcloudMajor Server major, null to read the real public version API.
	 * @param callable(string, string): bool|null $nativeProbe Test seam; default checks native methods/properties.
	 */
	public function __construct(private IAppManager $apps, private ?int $nextcloudMajor = null,
		?callable $nativeProbe = null) {
		$this->nativeProbe = $nativeProbe === null ? null : Closure::fromCallable($nativeProbe);
	}

	/** @return bool Whether this compatible installation exposes the native dependency API. */
	public function dependencies(): bool {
		$version = (string)$this->apps->getAppVersion('deck');
		if ($version === '') {
			return false;
		}
		$major = $this->nextcloudMajor ?? (int)Util::getVersion()[0];
		$minimum = self::QUALIFICATION_TARGETS[$major] ?? null;
		$maximum = [34 => '1.19.0', 35 => '1.20.0'][$major] ?? null;
		if ($minimum === null || version_compare($version, $minimum, '<')
			|| version_compare($version, $maximum, '>=') || !AppEnablement::forAnyone($this->apps, 'deck')) {
			return false;
		}
		$probe = $this->nativeProbe ?? static fn (string $class, string $member): bool =>
			$member === 'dependentCards' ? property_exists($class, $member) : method_exists($class, $member);
		foreach ([[CardService::class, 'assignDependentCard'], [CardService::class, 'removeDependentCard'],
			[CardMapper::class, 'findDependenciesForCards'], [Card::class, 'dependentCards']] as [$class, $member]) {
			if (!$probe($class, $member)) {
				return false;
			}
		}
		return true;
	}
}
