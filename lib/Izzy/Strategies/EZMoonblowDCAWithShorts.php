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
		// Get RSI signal
		$rsiSignal = $this->market->getLatestIndicatorSignal('RSI');

		// Buy when RSI shows oversold condition
		return $rsiSignal === 'overbought';
	}
}
