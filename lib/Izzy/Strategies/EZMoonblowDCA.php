<?php

namespace Izzy\Strategies;

use Izzy\Financial\Money;
use Izzy\Indicators\RSI;
use Izzy\Interfaces\IPosition;
use Izzy\Interfaces\IMarket;

class EZMoonblowDCA extends AbstractDCAStrategy
{
	public function useIndicators(): array {
		return [RSI::class];
	}

	/**
	 * In this custom strategy, we will buy when the price is low.
	 * @return bool
	 */
	public function shouldLong(): bool {
		/*if (!$this->market) {
			return false;
		}

		// Calculate indicators first
		$this->market->calculateIndicators();

		// Get RSI value
		$rsiValue = $this->market->getLatestIndicatorValue('RSI');

		if ($rsiValue === null) {
			return false;
		}

		// Get RSI signal
		$rsiSignal = $this->market->getLatestIndicatorSignal('RSI');

		// Buy when RSI shows oversold condition
		return $rsiSignal === 'oversold';*/
		return true; // Temporary enable always entering long
	}

	/**
	 * Here, we enter the long position.
	 * @param IMarket $market
	 * @return IPosition|false
	 */
	public function handleLong(IMarket $market): IPosition|false {
		return $market->openLongPosition(Money::from(50.0));
	}
}
