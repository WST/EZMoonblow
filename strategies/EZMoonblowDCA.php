<?php

use Izzy\Financial\DCAStrategy;

class EZMoonblowDCA extends DCAStrategy {
	/**
	 * In this custom strategy, we will buy when the price is low.
	 * @return bool
	 */
	public function shouldLong(): bool {
		return $this->market->isLowPrice();
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
		// TODO: Implement getDCALevels() method.
	}
}
