<?php

namespace Izzy\Interfaces;

use Izzy\Enums\MarketTypeEnum;

interface IHasMarketType
{
	public function isSpot(): bool;

	public function isFutures(): bool;
}