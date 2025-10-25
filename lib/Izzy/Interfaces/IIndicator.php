<?php

namespace Izzy\Interfaces;

use Izzy\Financial\IndicatorResult;

/**
 * Interface for technical indicators.
 * All technical indicators must implement this interface.
 */
interface IIndicator {
	/**
	 * Calculate indicator values for the given market.
	 *
	 * @param IMarket $market Market with candle data.
	 * @return IndicatorResult Result containing calculated values.
	 */
	public function calculate(IMarket $market): IndicatorResult;

	/**
	 * Get indicator name.
	 *
	 * @return string Indicator name.
	 */
	public static function getName(): string;

	/**
	 * Get indicator parameters.
	 *
	 * @return array Indicator parameters.
	 */
	public function getParameters(): array;
}
