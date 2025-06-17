<?php

namespace Izzy\Interfaces;

/**
 * Interface for a fair value gap (FVG).
 */
interface IFVG {
	/**
	 * Returns the size of the FVG in percents.
	 * @return float
	 */
	public function getSize(): float;
}
