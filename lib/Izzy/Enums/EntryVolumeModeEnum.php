<?php

namespace Izzy\Enums;

use Izzy\Financial\Money;
use Izzy\Financial\TradingContext;

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
	 * Percentage of available notional balance provided by available margin (e.g. 10×)
	 */
	case PERCENT_NOTIONAL = 'PERCENT_NOTIONAL';

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
	public function isPercentNotional(): bool {
		return $this === self::PERCENT_NOTIONAL;
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
	 * Resolve a raw volume value to quote currency (USDT) based on this mode.
	 *
	 * @param float $rawVolume Raw volume value (interpretation depends on this mode).
	 * @param TradingContext $context Runtime trading context providing balance, price, etc.
	 * @return Money Resolved volume in quote currency.
	 */
	public function resolve(float $rawVolume, TradingContext $context): Money {
		$amount = match ($this) {
			self::ABSOLUTE_QUOTE => $rawVolume,
			self::ABSOLUTE_BASE => $rawVolume * $context->getCurrentPrice()->getAmount(),
			self::PERCENT_BALANCE => $context->getBalance() * ($rawVolume / 100),
			self::PERCENT_NOTIONAL => $context->getNotional() * ($rawVolume / 100),
		};
		return Money::from($amount);
	}

	/**
	 * Get human-readable description.
	 * @return string
	 */
	public function getDescription(): string {
		return match ($this) {
			self::ABSOLUTE_QUOTE => 'Absolute value in quote currency (USDT)',
			self::PERCENT_BALANCE => 'Percentage of account balance',
			self::PERCENT_NOTIONAL => 'Percentage of available margin',
			self::ABSOLUTE_BASE => 'Absolute value in base currency',
		};
	}
}
