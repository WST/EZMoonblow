<?php

use Izzy\Financial\DCAStrategy;
use Izzy\Indicators\RSI;

class EZMoonblowDCA extends DCAStrategy
{
	public function useIndicators(): array {
		return [RSI::getName()];
	}

	/**
	 * In this custom strategy, we will buy when the price is low.
	 * @return bool
	 */
	public function shouldLong(): bool {
		if (!$this->market) {
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
		return $rsiSignal === 'oversold';
	}

	/**
	 * Here, we enter the long position.
	 * @return void
	 */
	public function handleLong(): void {
		// TODO: Implement handleLong() method.
	}

	/**
	 * Here, we define the DCA levels for this strategy.
	 * @return array
	 */
	public function getDCALevels(): array {
		// Define DCA levels as percentage drops from entry price
		// Each level represents when to buy more to average down
		return [
			-5.0,   // Buy more when price drops 5% from entry
			-10.0,  // Buy more when price drops 10% from entry
			-15.0,  // Buy more when price drops 15% from entry
			-20.0,  // Buy more when price drops 20% from entry
			-25.0,  // Buy more when price drops 25% from entry
		];
	}
}
