<?php

namespace Izzy\Financial;

use Izzy\Enums\TimeFrameEnum;
use Izzy\Interfaces\IMarket;
use Izzy\Interfaces\IStrategy;

/**
 * Base class for all trading strategies.
 */
abstract class AbstractStrategy implements IStrategy
{
	/**
	 * Market this strategy operates on.
	 * @var IMarket|null
	 */
	protected ?IMarket $market;

	/**
	 * Strategy configuration parameters as typed objects.
	 * @var array<string, AbstractStrategyParameter>
	 */
	protected array $params;

	/**
	 * Create a new strategy instance.
	 *
	 * @param IMarket $market Market to operate on.
	 * @param array $params Raw strategy configuration parameters (name => value).
	 */
	public function __construct(IMarket $market, array $params = []) {
		$this->market = $market;
		$this->params = static::resolveParams($params);
	}

	/**
	 * Resolve raw parameter values into typed AbstractStrategyParameter instances.
	 *
	 * Uses getParameters() to discover known parameter definitions, then
	 * creates a value-holding instance for each via ParamClass::from().
	 *
	 * @param array<string, mixed> $rawParams Raw parameters (name => value).
	 * @return array<string, AbstractStrategyParameter> Typed parameters (name => instance).
	 */
	public static function resolveParams(array $rawParams): array {
		$resolved = [];
		foreach (static::getParameters() as $def) {
			$key = $def::getName();
			$raw = $rawParams[$key] ?? $def->getDefault();
			$resolved[$key] = $def::from($raw);
		}
		return $resolved;
	}

	/**
	 * Get the market this strategy operates on.
	 * @return IMarket|null Market instance.
	 */
	public function getMarket(): ?IMarket {
		return $this->market;
	}

	/**
	 * Set the market for this strategy.
	 * @param IMarket|null $market Market instance.
	 * @return void
	 */
	public function setMarket(?IMarket $market): void {
		$this->market = $market;
	}

	/**
	 * Get all typed strategy parameters.
	 * @return array<string, AbstractStrategyParameter>
	 */
	public function getParams(): array {
		return $this->params;
	}

	/**
	 * Get a specific typed strategy parameter by name.
	 * @param string $name Parameter name.
	 * @return AbstractStrategyParameter|null Parameter instance or null if not set.
	 */
	public function getParam(string $name): ?AbstractStrategyParameter {
		return $this->params[$name] ?? null;
	}

	/**
	 * Get all parameter values as a raw array (for serialization).
	 * @return array<string, string>
	 */
	public function getRawParams(): array {
		$raw = [];
		foreach ($this->params as $name => $param) {
			$raw[$name] = $param->getRawValue();
		}
		return $raw;
	}

	/**
	 * @inheritDoc
	 */
	public function useIndicators(): array {
		return [];
	}

	/**
	 * @inheritDoc
	 *
	 * Default implementation returns an empty (valid) result.
	 * Subclasses should override to add specific checks.
	 */
	public function validateExchangeSettings(IMarket $market): StrategyValidationResult {
		return new StrategyValidationResult();
	}

	/**
	 * Timeframes needed by this strategy beyond the market's native timeframe.
	 * Used by the backtester to pre-load historical candles.
	 *
	 * @return TimeFrameEnum[]
	 */
	public static function requiredTimeframes(): array {
		return [];
	}

	/**
	 * Human-readable strategy name for the UI.
	 * Concrete strategies should override this.
	 */
	public static function getDisplayName(): string {
		return (new \ReflectionClass(static::class))->getShortName();
	}

	/**
	 * Get all typed parameter definitions for the strategy.
	 * Concrete strategies should override and merge with parent.
	 *
	 * @return AbstractStrategyParameter[]
	 */
	public static function getParameters(): array {
		return [];
	}

	public static function getStrategySettingGroupTitle(): string {
		return 'Common Strategy Settings';
	}
}
