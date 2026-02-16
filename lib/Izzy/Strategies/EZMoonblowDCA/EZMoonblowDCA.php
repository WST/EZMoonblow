<?php

namespace Izzy\Strategies\EZMoonblowDCA;

use Izzy\Financial\AbstractDCAStrategy;
use Izzy\Indicators\RSI;

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
		// Get RSI signal.
		$rsiSignal = $this->market->getLatestIndicatorSignal('RSI');

		// Buy when RSI shows oversold condition.
		return $rsiSignal === 'oversold';
	}

	public function doesLong(): bool {
		return true;
	}

	public function doesShort(): bool {
		return false;
	}
}
