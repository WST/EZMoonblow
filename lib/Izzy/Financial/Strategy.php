<?php

namespace Izzy\Financial;

use Izzy\Interfaces\IMarket;
use Izzy\Interfaces\IStrategy;

abstract class Strategy implements IStrategy
{
	protected ?IMarket $market;

	public function __construct(IMarket $market) {
		$this->market = $market;
	}

	public function getMarket(): ?IMarket {
		return $this->market;
	}

	public function setMarket(?IMarket $market): void {
		$this->market = $market;
	}
}
