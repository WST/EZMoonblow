<?php

namespace Izzy\Indicators;

use Izzy\Interfaces\IIndicator;
use Izzy\Interfaces\IPair;

/**
 * Factory for creating technical indicators.
 * Provides a centralized way to instantiate indicators by name.
 */
class IndicatorFactory
{
	/**
	 * Available indicators mapping.
	 * @var array
	 */
	private static array $indicators = [
		'RSI' => RSI::class,
		// Add more indicators here as they are implemented
	];
	
	/**
	 * Create indicator instance by type.
	 * 
	 * @param string $type Indicator type (e.g., 'RSI', 'MACD').
	 * @param array $parameters Indicator parameters.
	 * @return IIndicator Indicator instance.
	 * @throws \InvalidArgumentException If indicator type is unknown.
	 */
	public static function create(IPair $pair, string $type, array $parameters = []): IIndicator {
		if (!isset(self::$indicators[$type])) {
			throw new \InvalidArgumentException("Unknown indicator type: $type");
		}
		
		$className = self::$indicators[$type];
		return new $className($pair, $parameters);
	}
	
	/**
	 * Create indicator instance by class name.
	 * 
	 * @param string $className Full class name of the indicator.
	 * @param array $parameters Indicator parameters.
	 * @return IIndicator Indicator instance.
	 * @throws \InvalidArgumentException If class doesn't exist or doesn't implement IIndicator.
	 */
	public function createIndicator(IPair $pair, string $className, array $parameters = []): IIndicator {
		if (!class_exists($className)) {
			throw new \InvalidArgumentException("Indicator class does not exist: $className");
		}
		
		if (!is_subclass_of($className, IIndicator::class)) {
			throw new \InvalidArgumentException("Class $className does not implement IIndicator interface");
		}
		
		return new $className($pair, $parameters);
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
