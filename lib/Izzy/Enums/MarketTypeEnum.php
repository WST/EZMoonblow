<?php

namespace Izzy\Enums;

enum MarketTypeEnum: string {
	case SPOT = 'spot';
	case FUTURES = 'futures';
	
	public function isFutures(): bool {
		return $this === self::FUTURES;
	}

	public function isSpot(): bool {
		return $this === self::SPOT;
	}
}
