<?php

namespace Izzy\Indicators;

use InvalidArgumentException;
use Izzy\Interfaces\IIndicator;
use Izzy\Interfaces\IMarket;

/**
 * Factory for creating technical indicators.
 * Provides a centralized way to instantiate indicators by name.
 */
class IndicatorFactory {
	/**
	 * Available indicators mapping.
	 * @var array
	 */
	private static array $indicators = [
		'Izzy\Indicators\RSI' => RSI::class,
		'RSI' => RSI::class,
		// Add more indicators here as they are implemented
	];

	/**
	 * Create indicator instance by type.
	 *
	 * @param IMarket $market
	 * @param string $type Indicator type (e.g., 'RSI', 'MACD').
	 * @param array $parameters Indicator parameters.
	 * @return IIndicator Indicator instance.
	 * @throws InvalidArgumentException If indicator type is unknown.
	 */
	public static function create(IMarket $market, string $type, array $parameters = []): IIndicator {
		if (!isset(self::$indicators[$type])) {
			throw new InvalidArgumentException("Unknown indicator type: $type");
		}

		$className = self::$indicators[$type];
		return new $className($market, $parameters);
	}

	/**
	 * Get list of available indicator types.
	 *
	 * @return array Array of available indicator types.
	 */
	public static function getAvailableTypes(): array {
		return array_keys(self::$indicators);
	}

	/**
	 * Check if indicator type is available.
	 *
	 * @param string $type Indicator type.
	 * @return bool True if available, false otherwise.
	 */
	public static function isAvailable(string $type): bool {
		return isset(self::$indicators[$type]);
	}

	/**
	 * Register a new indicator type.
	 *
	 * @param string $type Indicator type name.
	 * @param string $className Full class name.
	 * @return void
	 */
	public static function register(string $type, string $className): void {
		self::$indicators[$type] = $className;
	}
}
