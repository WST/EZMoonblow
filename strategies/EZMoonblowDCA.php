<?php

use Izzy\DCAStrategy;

class EZMoonblowDCA extends DCAStrategy
{
	/**
	 * В кастомной DCA-стратегии будем заходить в лонг на снижениях цены.
	 * @return bool
	 */
	public function shouldLong(): bool {
		$isLowPrice = $this->getMarket()->isLowPrice();
		return $isLowPrice;
	}

	public function isShort(): bool {
		// TODO: Implement isShort() method.
	}

	public function handleLong() {
		// TODO: Implement handleLong() method.
	}

	public function handleShort() {
		// TODO: Implement handleShort() method.
	}

	public function updatePosition() {
		// TODO: Implement updatePosition() method.
	}
}
