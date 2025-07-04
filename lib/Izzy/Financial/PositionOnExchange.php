<?php

namespace Izzy\Financial;

use Izzy\Enums\MarketTypeEnum;
use Izzy\Enums\PositionDirectionEnum;
use Izzy\Enums\PositionStatusEnum;
use Izzy\Interfaces\IPositionOnExchange;

class PositionOnExchange implements IPositionOnExchange
{

	public function getVolume(): Money {
		// TODO: Implement getVolume() method.
	}

	public function getDirection(): PositionDirectionEnum {
		// TODO: Implement getDirection() method.
	}

	public function getEntryPrice(): Money {
		// TODO: Implement getEntryPrice() method.
	}

	public function getCurrentPrice(): Money {
		// TODO: Implement getCurrentPrice() method.
	}

	public function getUnrealizedPnL(): Money {
		// TODO: Implement getUnrealizedPnL() method.
	}

	public function getUnrealizedPnLPercent(): float {
		// TODO: Implement getUnrealizedPnLPercent() method.
	}

	public function getStatus(): PositionStatusEnum {
		// TODO: Implement getStatus() method.
	}

	public function isOpen(): bool {
		// TODO: Implement isOpen() method.
	}

	public function getPositionId(): int {
		// TODO: Implement getPositionId() method.
	}

	public function getMarketType(): MarketTypeEnum {
		// TODO: Implement getMarketType() method.
	}

	public function getExpectedProfitPercent(): float {
		// TODO: Implement getExpectedProfitPercent() method.
	}
}
