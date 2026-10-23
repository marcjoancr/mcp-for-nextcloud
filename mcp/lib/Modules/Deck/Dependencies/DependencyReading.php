<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Dependencies;



use OCA\Mcp\Modules\Deck\Presentation\CapabilityMessages;
use OCA\Mcp\Modules\Deck\Dependencies\DependencyGraph;
use Psr\Log\LoggerInterface;

/** Publishes only the complete readable outgoing graph; there is no hidden total or opaque reference. */
final class DependencyReading {
	public const TOOL = 'deck_read_card_dependencies';

	/**
	 * @param DependencyAccess $gateway Native READ boundary.
	 * @param LoggerInterface $logger Receives exception classes, never target metadata.
	 * @param \OCA\Mcp\Modules\Deck\Presentation\DeckFormatterFactory $formatters Minimal public card references.
	 */
	public function __construct(private DependencyAccess $gateway, private LoggerInterface $logger, private \OCA\Mcp\Modules\Deck\Presentation\DeckFormatterFactory $formatters) {

	}

	/** {@inheritDoc} Limits are applied only to readable nodes and edges, never to raw native IDs. */
	public function handle(array $arguments, string $userId): array {
		return $this->run(function () use ($arguments, $userId): array {
			$graph = (new DependencyGraph($this->gateway))->read($userId, (int)$arguments['cardId']);
			$graph['nodes'] = array_map(fn (array $node): array =>
				$this->formatters->forUser($userId)->reference($node['card'], $node['boardId']), $graph['nodes']);
			return $graph + ['direction' => 'origin.dependentCards contains target',
				'message' => CapabilityMessages::dependencyGraphVisibility()];
		});
	}

	/** {@inheritDoc} */
	protected function toolName(): string {
		return self::TOOL;
	}
    /** @param callable(): mixed $operation @return array<string, mixed> */
    private function run(callable $operation): array {
        return (new \OCA\Mcp\Modules\Deck\Execution\ResultBoundary($this->logger))->run($this->toolName(), 'deck_read_card', $operation);
    }
}
