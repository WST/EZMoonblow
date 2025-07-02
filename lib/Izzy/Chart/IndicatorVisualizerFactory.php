<?php

namespace Izzy\Chart;

use Izzy\Indicators\RSI;
use Izzy\Interfaces\IIndicator;

/**
 * Factory for creating indicator visualizers.
 * Provides a centralized way to instantiate visualizers by indicator type.
 */
class IndicatorVisualizerFactory
{
	/**
	 * Available visualizers mapping.
	 * @var array
	 */
	private static array $visualizers = [
		RSI::class => RSIVisualizer::class,
		// Add more visualizers here as they are implemented
		// SMA::class => SMAVisualizer::class,
		// MACD::class => MACDVisualizer::class,
	];

	/**
	 * Create visualizer instance for the given indicator.
	 *
	 * @param IIndicator $indicator The indicator to create visualizer for.
	 * @return IIndicatorVisualizer|null Visualizer instance or null if not found.
	 */
	public static function createVisualizer(IIndicator $indicator): ?IIndicatorVisualizer {
		$indicatorClass = get_class($indicator);

		if (!isset(self::$visualizers[$indicatorClass])) {
			return null;
		}

		$visualizerClass = self::$visualizers[$indicatorClass];
		return new $visualizerClass();
	}

	/**
	 * Get list of available visualizer types.
	 *
	 * @return array Array of available indicator class names.
	 */
	public static function getAvailableTypes(): array {
		return array_keys(self::$visualizers);
	}

	/**
	 * Check if visualizer is available for indicator type.
	 *
	 * @param string $indicatorClass Indicator class name.
	 * @return bool True if available, false otherwise.
	 */
	public static function isAvailable(string $indicatorClass): bool {
		return isset(self::$visualizers[$indicatorClass]);
	}

	/**
	 * Register a new visualizer type.
	 *
	 * @param string $indicatorClass Indicator class name.
	 * @param string $visualizerClass Visualizer class name.
	 * @return void
	 */
	public static function register(string $indicatorClass, string $visualizerClass): void {
		self::$visualizers[$indicatorClass] = $visualizerClass;
	}

	/**
	 * Get all registered visualizers.
	 *
	 * @return array Array of registered visualizers.
	 */
	public static function getRegisteredVisualizers(): array {
		return self::$visualizers;
	}
}
