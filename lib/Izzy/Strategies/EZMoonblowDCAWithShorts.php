<?php

namespace Izzy\Strategies;

use Izzy\Interfaces\IStoredPosition;
use Izzy\Interfaces\IMarket;

/**
 * Alternative EZMoonblowDCA implementation that adds support for Short positions.
 */
class EZMoonblowDCAWithShorts extends EZMoonblowDCA
{
	public function shouldShort(): bool {
		// Get RSI signal.
		$rsiSignal = $this->market->getLatestIndicatorSignal('RSI');

		// Buy when RSI shows oversold condition.
		return $rsiSignal === 'overbought';
	}
	
	/**
	 * Convert machine-readable parameter names to human-readable format.
	 * @param string $paramName Machine-readable parameter name.
	 * @return string Human-readable parameter name.
	 */
	public static function formatParameterName(string $paramName): string {
		// Get base parameter names from parent class.
		$baseFormattedNames = parent::formatParameterName($paramName);
		if ($baseFormattedNames !== $paramName) {
			return $baseFormattedNames;
		}
		
		// Add short-specific parameter names.
		$shortFormattedNames = [
			'numberOfLevelsShort' => 'Number of short DCA orders including the entry order',
			'entryVolumeShort' => 'Initial short entry volume (USDT)',
			'volumeMultiplierShort' => 'Short volume multiplier for each subsequent order',
			'priceDeviationShort' => 'Short price deviation for first averaging (%)',
			'priceDeviationMultiplierShort' => 'Short price deviation multiplier for subsequent orders',
			'expectedProfitShort' => 'Expected short profit percentage'
		];
		
		return $shortFormattedNames[$paramName] ?? $paramName;
	}
}
