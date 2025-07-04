<?php

namespace Izzy\Enums;

enum PositionDirectionEnum: string
{
	case LONG = 'LONG';
	case SHORT = 'SHORT';

	/**
	 * Indicates if the direction is Long.
	 * @return bool
	 */
	public function isLong(): bool {
		return $this === self::LONG;
	}

	/**
	 * Indicates if the direction is Short.
	 * @return bool
	 */
	public function isShort(): bool {
		return $this === self::SHORT;
	}

	/**
	 * Returns the string representation of the direction.
	 * @return string
	 */
	public function toString(): string {
		return $this->value;
	}
	
	public function getBuySell(): string {
		return $this->isLong() ? 'Buy' : 'Sell';
	}

	public function getMultiplier(): float {
		return $this->isLong() ? 1.0 : -1.0;
	}
}
