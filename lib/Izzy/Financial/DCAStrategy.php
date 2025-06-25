<?php

namespace Izzy\Financial;

/**
 * Base class for Dollar-Cost Averaging (DCA) strategies.
 */
abstract class DCAStrategy extends Strategy
{
	/**
	 * This method should be implemented by child classes to determine when to enter long position.
	 * @return bool
	 */
	abstract public function shouldLong(): bool;

	/**
	 * In this base strategy, we never short.
	 * @return bool
	 */
	public function shouldShort(): bool {
		return false;
	}

	public function handleShort(): void {
		// Nothing.
	}
	
	public function handleLong(): void {
		// Nothing.
	}
	
	/**
	 * This method should be implemented by child classes to indicate the intended DCA levels.
	 */
	abstract public function getDCALevels(): array;

	/**
	 * This strategy does not use stop loss.
	 * Instead, it relies on the DCA mechanism to average down the position.
	 * Since this is an abstract class, this method will call the getDCALevels() method
	 * of the child class to determine the DCA levels.
	 * @return void
	 */
	public function updatePosition(): void {
		if (!$this->market) {
			return;
		}

		$dcaLevels = $this->getDCALevels();
		$currentPosition = $this->market->getPosition();
		
		if (!$currentPosition || !$currentPosition->isOpen()) {
			return;
		}

		$entryPrice = $currentPosition->getEntryPrice();
		$currentPrice = $currentPosition->getCurrentPrice();
		
		// Calculate current price drop percentage
		$priceDropPercent = (($currentPrice - $entryPrice) / $entryPrice) * 100;
		
		// Check if we should execute DCA
		foreach ($dcaLevels as $level) {
			if ($priceDropPercent <= $level) {
				// Execute DCA buy order
				$exchange = $this->market->getExchange();
				$dcaAmount = new Money(5.0, 'USDT'); // $5 DCA amount
				$exchange->buyAdditional($this->market->getTicker(), $dcaAmount);
				break;
			}
		}
	}
}
