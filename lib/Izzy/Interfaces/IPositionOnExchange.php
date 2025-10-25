<?php

namespace Izzy\Interfaces;

interface IPositionOnExchange extends IPosition {
	/**
	 * Create a Stored Position from the given Position on Exchange.
	 * @return mixed
	 */
	public function store(): IStoredPosition;
}
