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
	 * Strategy configuration parameters.
	 * @var array
	 */
	protected array $params;

	/**
	 * Create a new strategy instance.
	 *
	 * @param IMarket $market Market to operate on.
	 * @param array $params Strategy configuration parameters.
	 */
	public function __construct(IMarket $market, array $params = []) {
		$this->market = $market;
		$this->params = $params;
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
	 * Get all strategy parameters.
	 * @return array Strategy parameters.
	 */
	public function getParams(): array {
		return $this->params;
	}

	/**
	 * Get a specific strategy parameter by name.
	 * @param string $name Parameter name.
	 * @return string|null Parameter value or null if not set.
	 */
	public function getParam(string $name): ?string {
		return $this->params[$name] ?? null;
	}

	/**
	 * Set strategy parameters.
	 * @param array $params Strategy parameters.
	 * @return void
	 */
	public function setParams(array $params): void {
		$this->params = $params;
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
	 * Get all typed parameter definitions for the strategy.
	 * Concrete strategies should override and merge with parent.
	 *
	 * @return AbstractStrategyParameter[]
	 */
	public static function getParameters(): array {
		return [];
	}
}
