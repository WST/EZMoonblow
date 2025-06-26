<?php

namespace Izzy\Strategies;

use InvalidArgumentException;
use Izzy\Interfaces\IMarket;
use Izzy\Interfaces\IStrategy;

/**
 * Factory for creating trading strategies.
 * Provides a centralized way to instantiate strategies by name.
 */
class StrategyFactory
{
	/**
	 * Available strategies mapping.
	 * @var array
	 */
	private static array $strategies = [
		'EZMoonblowDCA' => EZMoonblowAbstractDCA::class,
		// Add more strategies here as they are implemented
	];

	/**
	 * Create strategy instance by name.
	 * 
	 * @param string $strategyName Strategy name.
	 * @param IMarket $market Market instance.
	 * @param array $params Strategy parameters.
	 * @return IStrategy Strategy instance.
	 * @throws InvalidArgumentException If strategy name is unknown.
	 */
	public static function create(string $strategyName, IMarket $market, array $params = []): IStrategy {
		if (!isset(self::$strategies[$strategyName])) {
			throw new InvalidArgumentException("Unknown strategy: $strategyName");
		}

		$className = self::$strategies[$strategyName];
		return new $className($market, $params);
	}

	/**
	 * Get list of available strategy names.
	 * 
	 * @return array Array of available strategy names.
	 */
	public static function getAvailableStrategies(): array {
		return array_keys(self::$strategies);
	}

	/**
	 * Check if strategy is available.
	 * 
	 * @param string $strategyName Strategy name.
	 * @return bool True if available, false otherwise.
	 */
	public static function isAvailable(string $strategyName): bool {
		return isset(self::$strategies[$strategyName]);
	}

	/**
	 * Register a new strategy.
	 * 
	 * @param string $strategyName Strategy name.
	 * @param string $className Full class name.
	 * @return void
	 */
	public static function register(string $strategyName, string $className): void {
		self::$strategies[$strategyName] = $className;
	}
}
