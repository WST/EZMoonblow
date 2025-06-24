<?php

namespace Izzy\Financial;

use Izzy\Interfaces\IMarket;
use Izzy\Interfaces\IStrategy;

abstract class Strategy implements IStrategy
{
	protected ?IMarket $market {
		get {
			return $this->market;
		}
	}

	public function __construct(IMarket $market) {
		$this->market = $market;
	}

}
