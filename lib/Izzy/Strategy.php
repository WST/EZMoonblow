<?php

namespace Izzy;

use Izzy\Interfaces\IMarket;
use Izzy\Interfaces\IStrategy;

abstract class Strategy implements IStrategy
{
	protected ?IMarket $market;
	
	public function __construct(IMarket $market) {
		$this->market = $market;
	}
	
	protected function getMarket(): IMarket {
		return $this->market;
	}
}
