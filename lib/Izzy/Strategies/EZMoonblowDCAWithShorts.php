<?php

namespace Izzy\Strategies;

use Izzy\Interfaces\IPosition;
use Izzy\Interfaces\IMarket;

/**
 * Alternative EZMoonblowDCA implementation that adds support for Short positions.
 */
class EZMoonblowDCAWithShorts extends EZMoonblowDCA
{
	public function shouldShort(): bool {
		// Get RSI signal
		$rsiSignal = $this->market->getLatestIndicatorSignal('RSI');

		// Buy when RSI shows oversold condition
		return $rsiSignal === 'overbought';
	}

	/**
	 * Here, we enter the long position.
	 * @param IMarket $market
	 * @return IPosition|false
	 */
	public function handleShort(IMarket $market): IPosition|false {
		return $market->openShortPosition($this->getEntryVolume());
	}
}
