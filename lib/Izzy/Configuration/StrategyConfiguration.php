<?php

namespace Izzy\Configuration;

use Izzy\Financial\AbstractStrategy;
use Izzy\Financial\AbstractStrategyParameter;
use Izzy\Financial\StrategyFactory;

/**
 * Value-object representing a strategy and its parameters for a specific pair.
 * Internally stores typed parameter objects for accurate comparison.
 */
class StrategyConfiguration
{
	/** @var array<string, AbstractStrategyParameter> Typed parameter instances. */
	private array $typedParams;

	public function __construct(
		private string $strategyName,
		private array $params,
	) {
		$this->typedParams = $this->resolveTypedParams();
	}

	public function getStrategyName(): string {
		return $this->strategyName;
	}

	/**
	 * Compare this configuration with another using typed parameter equality.
	 * Only backtest-relevant parameters are compared.
	 */
	public function equals(self $other): bool {
		if ($this->strategyName !== $other->strategyName) {
			return false;
		}

		$myParams = $this->typedParams;
		$otherParams = $other->typedParams;

		$strategyClass = StrategyFactory::getStrategyClass($this->strategyName);
		if ($strategyClass === null) {
			return $myParams === $otherParams;
		}

		foreach ($strategyClass::getParameters() as $def) {
			if (!$def->isBacktestRelevant()) {
				continue;
			}
			$key = $def::getName();
			$a = $myParams[$key] ?? null;
			$b = $otherParams[$key] ?? null;
			if ($a === null || $b === null) {
				if ($a !== $b) {
					return false;
				}
				continue;
			}
			if (!$a->equals($b)) {
				return false;
			}
		}

		return true;
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
		$result = [];
		foreach ($this->typedParams as $key => $param) {
			$result[$key] = $param->normalizeValue($param->getRawValue());
		}
		return $result;
	}

	/**
	 * Build typed parameter instances from the strategy definition and raw params.
	 *
	 * @return array<string, AbstractStrategyParameter>
	 */
	private function resolveTypedParams(): array {
		$strategyClass = StrategyFactory::getStrategyClass($this->strategyName);
		if ($strategyClass === null) {
			return [];
		}
		return $strategyClass::resolveParams($this->params);
	}
}
