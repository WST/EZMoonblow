<?php

namespace Izzy\Enums;

/**
 * Position mode on a futures exchange.
 *
 * Hedge mode allows holding both long and short positions simultaneously.
 * One-way mode allows only one position direction at a time.
 */
enum PositionModeEnum: string
{
	/**
	 * Hedge (Two-Way) mode: long and short positions can coexist.
	 */
	case HEDGE = 'HEDGE';

	/**
	 * One-Way (Merged Single) mode: only one direction at a time.
	 */
	case ONE_WAY = 'ONE_WAY';

	/**
	 * Check if this is hedge (two-way) mode.
	 */
	public function isHedge(): bool {
		return $this === self::HEDGE;
	}

	/**
	 * Check if this is one-way mode.
	 */
	public function isOneWay(): bool {
		return $this === self::ONE_WAY;
	}

	/**
	 * Get human-readable label for the UI.
	 */
	public function getLabel(): string {
		return match ($this) {
			self::HEDGE => 'Hedge (Two-Way)',
			self::ONE_WAY => 'One-Way',
		};
	}
}
