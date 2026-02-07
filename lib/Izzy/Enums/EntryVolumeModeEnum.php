<?php

namespace Izzy\Enums;

/**
 * Defines how entry volume is specified in DCA configuration.
 */
enum EntryVolumeModeEnum: string
{
	/**
	 * Absolute value in quote currency (e.g., 140 USDT).
	 * This is the default mode for backward compatibility.
	 */
	case ABSOLUTE_QUOTE = 'ABSOLUTE_QUOTE';

	/**
	 * Percentage of account balance (e.g., 5%).
	 */
	case PERCENT_BALANCE = 'PERCENT_BALANCE';

	/**
	 * Percentage of available margin with 1x leverage (e.g., 5%M).
	 */
	case PERCENT_MARGIN = 'PERCENT_MARGIN';

	/**
	 * Absolute value in base currency (e.g., 0.002 BTC).
	 */
	case ABSOLUTE_BASE = 'ABSOLUTE_BASE';

	/**
	 * Check if mode is absolute quote currency.
	 * @return bool
	 */
	public function isAbsoluteQuote(): bool {
		return $this === self::ABSOLUTE_QUOTE;
	}

	/**
	 * Check if mode is percentage of balance.
	 * @return bool
	 */
	public function isPercentBalance(): bool {
		return $this === self::PERCENT_BALANCE;
	}

	/**
	 * Check if mode is percentage of margin.
	 * @return bool
	 */
	public function isPercentMargin(): bool {
		return $this === self::PERCENT_MARGIN;
	}

	/**
	 * Check if mode is absolute base currency.
	 * @return bool
	 */
	public function isAbsoluteBase(): bool {
		return $this === self::ABSOLUTE_BASE;
	}

	/**
	 * Check if mode requires runtime calculation (balance/margin/price dependent).
	 * @return bool
	 */
	public function requiresRuntimeCalculation(): bool {
		return $this !== self::ABSOLUTE_QUOTE;
	}

	/**
	 * Get human-readable description.
	 * @return string
	 */
	public function getDescription(): string {
		return match ($this) {
			self::ABSOLUTE_QUOTE => 'Absolute value in quote currency (USDT)',
			self::PERCENT_BALANCE => 'Percentage of account balance',
			self::PERCENT_MARGIN => 'Percentage of available margin',
			self::ABSOLUTE_BASE => 'Absolute value in base currency',
		};
	}
}
