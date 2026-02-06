<?php

namespace Izzy\Enums;

/**
 * Defines how price offsets are calculated in a DCA order grid.
 */
enum DCAOffsetModeEnum: string {
	/**
	 * Each order's offset is calculated relative to the entry price.
	 * Example: entry=100, step=5% → levels at 100, 95, 90, 85...
	 */
	case FROM_ENTRY = 'FROM_ENTRY';

	/**
	 * Each order's offset is calculated relative to the previous order's price.
	 * Example: entry=100, step=5% → levels at 100, 95, 90.25, 85.74...
	 */
	case FROM_PREVIOUS = 'FROM_PREVIOUS';

	/**
	 * Check if offsets are calculated from entry price.
	 * @return bool
	 */
	public function isFromEntry(): bool {
		return $this === self::FROM_ENTRY;
	}

	/**
	 * Check if offsets are calculated from previous order.
	 * @return bool
	 */
	public function isFromPrevious(): bool {
		return $this === self::FROM_PREVIOUS;
	}

	/**
	 * Get human-readable description of the offset mode.
	 * @return string
	 */
	public function getDescription(): string {
		return match ($this) {
			self::FROM_ENTRY => 'Offset from entry price',
			self::FROM_PREVIOUS => 'Offset from previous order',
		};
	}
}
