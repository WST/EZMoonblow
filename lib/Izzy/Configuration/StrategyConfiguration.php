<?php

namespace Izzy\Configuration;

use Izzy\Financial\StrategyFactory;

/**
 * Value-object representing a strategy and its parameters for a specific pair.
 * Supports smart equality comparison with type normalization and default filling.
 */
class StrategyConfiguration
{
	public function __construct(
		private string $strategyName,
		private array $params,
	) {}

	public function getStrategyName(): string {
		return $this->strategyName;
	}

	/**
	 * Compare this configuration with another using normalized values.
	 * Booleans are compared canonically ("yes"/"true"/"1" are equal).
	 * Missing parameters are filled from the strategy's class defaults.
	 * Only backtest-relevant parameters are compared.
	 */
	public function equals(self $other): bool {
		if ($this->strategyName !== $other->strategyName) {
			return false;
		}
		return $this->normalizedParams() === $other->normalizedParams();
	}

	/**
	 * @return array<string, string> Raw params map.
	 */
	public function getParams(): array {
		return $this->params;
	}

	/**
	 * Serialize to a safe array suitable for JSON API responses.
	 * Fills missing params with defaults so the frontend gets a complete picture.
	 *
	 * @return array<string, string> key => value for every known strategy parameter.
	 */
	public function toFullParams(): array {
		$strategyClass = StrategyFactory::getStrategyClass($this->strategyName);
		if ($strategyClass === null) {
			return $this->params;
		}
		$paramDefs = $strategyClass::getParameters();
		$full = [];
		foreach ($paramDefs as $def) {
			$key = $def->getName();
			$full[$key] = $def->normalizeValue($this->params[$key] ?? $def->getDefault());
		}
		return $full;
	}

	/**
	 * Build a normalized param map using the strategy's parameter definitions.
	 *
	 * @return array<string, string> Canonical key => value pairs.
	 */
	private function normalizedParams(): array {
		$strategyClass = StrategyFactory::getStrategyClass($this->strategyName);
		if ($strategyClass === null) {
			return [];
		}
		$paramDefs = $strategyClass::getParameters();
		$normalized = [];
		foreach ($paramDefs as $def) {
			if (!$def->isBacktestRelevant()) {
				continue;
			}
			$key = $def->getName();
			$raw = $this->params[$key] ?? $def->getDefault();
			$normalized[$key] = $def->normalizeValue($raw);
		}
		return $normalized;
	}
}
