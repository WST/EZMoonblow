<?php

namespace Izzy\Interfaces;

interface IPair extends IHasMarketType
{
	public function getTicker(): string;
}
