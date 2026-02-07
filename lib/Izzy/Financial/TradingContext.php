<?php

namespace Izzy\Financial;

/**
 * Represents runtime trading context for volume calculations.
 *
 * This DTO encapsulates market conditions needed to resolve
 * dynamic volume modes (percentage of balance, margin, or base currency).
 */
class TradingContext
{
	/**
	 * Creates a new trading context.
	 *
	 * @param float $balance Current account balance in quote currency.
	 * @param float $margin Available margin in quote currency.
	 * @param Money $currentPrice Current price of base currency.
	 */
	public function __construct(
		private float $balance,
		private float $margin,
		private Money $currentPrice
	) {}

	/**
	 * Get the current account balance.
	 * @return float Balance in quote currency (e.g., USDT).
	 */
	public function getBalance(): float {
		return $this->balance;
	}

	/**
	 * Get the available margin.
	 * @return float Margin in quote currency.
	 */
	public function getMargin(): float {
		return $this->margin;
	}

	/**
	 * Get the current price of base currency.
	 * @return Money Price in quote currency.
	 */
	public function getCurrentPrice(): Money {
		return $this->currentPrice;
	}

	/**
	 * Create an empty context (for backward compatibility or display purposes).
	 * @return self
	 */
	public static function empty(): self {
		return new self(0.0, 0.0, new Money(0.0));
	}

	/**
	 * Check if the context has valid data for calculations.
	 * @return bool
	 */
	public function isValid(): bool {
		return $this->balance > 0 || $this->margin > 0 || $this->currentPrice->getAmount() > 0;
	}
}
