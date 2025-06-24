<?php

namespace Izzy\Interfaces;

use Izzy\Financial\Money;

/**
 * Represents currently open position.
 */
interface IPosition
{
	/**
	 * Get current position volume.
	 * @return Money
	 */
	public function getVolume(): Money;
}
