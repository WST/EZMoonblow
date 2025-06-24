<?php

namespace Izzy\Financial;

/**
 * Base class for Dollar-Cost Averaging (DCA) strategies.
 */
abstract class DCAStrategy extends Strategy
{
	/**
	 * In this base strategy, we always long.
	 * @return bool
	 */
	public function shouldLong(): bool {
		return true;
	}

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
		$dcaLevels = $this->getDCALevels();
	}
}
