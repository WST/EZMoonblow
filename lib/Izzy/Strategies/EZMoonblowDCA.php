<?php

namespace Izzy\Strategies;

use Izzy\Financial\Money;
use Izzy\Indicators\RSI;
use Izzy\System\Logger;

class EZMoonblowDCA extends AbstractDCAStrategy
{
	const float DEFAULT_ENTRY_VOLUME = 50;
	
	public function useIndicators(): array {
		return [RSI::class];
	}

	/**
	 * Get the entry volume (in USDT for USDT pairs) from the configuration file.
	 * @return Money
	 */
	protected function getEntryVolume(): Money {
		$entryVolume = $this->getParam('entryVolume');
		if (!$entryVolume) {
			Logger::getLogger()->debug("Entry volume is not set, defaulting to 50.00 USDT");
			$entryVolume = self::DEFAULT_ENTRY_VOLUME;
		}
		return Money::from($entryVolume);
	}

	/**
	 * In this custom strategy, we will buy when the price is low.
	 * @return bool
	 */
	public function shouldLong(): bool {
		// Get RSI signal
		$rsiSignal = $this->market->getLatestIndicatorSignal('RSI');
		
		// Buy when RSI shows oversold condition
		return $rsiSignal === 'oversold';
	}
	
	/**
	 * Convert machine-readable parameter names to human-readable format.
	 * @param string $paramName Machine-readable parameter name.
	 * @return string Human-readable parameter name.
	 */
	public static function formatParameterName(string $paramName): string {
		$formattedNames = [
			'numberOfLevels' => 'Number of DCA orders including the entry order',
			'entryVolume' => 'Initial entry volume (USDT)',
			'volumeMultiplier' => 'Volume multiplier for each subsequent order',
			'priceDeviation' => 'Price deviation for first averaging (%)',
			'priceDeviationMultiplier' => 'Price deviation multiplier for subsequent orders',
			'expectedProfit' => 'Expected profit percentage',
			'UseLimitOrders' => 'Use limit orders instead of market orders'
		];
		
		return $formattedNames[$paramName] ?? $paramName;
	}
}
