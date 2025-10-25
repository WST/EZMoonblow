<?php

namespace Izzy\Enums;

enum OrderTypeEnum: string {
	case LIMIT = 'Limit';
	case MARKET = 'Market';

	public function isLimit(): bool {
		return $this === self::LIMIT;
	}

	public function isMarket(): bool {
		return $this === self::MARKET;
	}
}
