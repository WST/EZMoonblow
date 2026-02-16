<?php

namespace Izzy\Financial;

use Izzy\Enums\PositionDirectionEnum;

class Money
{
	private float $amount;
	private string $currency;

	public function __construct(string|int|float $amount, string $currency = 'USDT') {
		$this->amount = (float)$amount;
		$this->currency = $currency;
	}

	public static function from(string|int|float|null $amount, string $currency = 'USDT'): ?Money {
		if (is_null($amount))
			return null;
		return new self($amount, $currency);
	}

	public function getAmount(): float {
		return $this->amount;
	}

	public function getCurrency(): string {
		return $this->currency;
	}

	/**
	 * Format the amount with optional currency suffix.
	 *
	 * @param string $format sprintf format string for the amount.
	 * @param bool $appendCurrency Whether to append the currency name after the amount.
	 * @return string Formatted string like "0.0047 USDT".
	 */
	public function format(string $format = '%.4f', bool $appendCurrency = true): string {
		$result = sprintf($format, $this->amount);
		if ($appendCurrency) {
			$result .= " $this->currency";
		}
		return $result;
	}

	/**
	 * Format for display in UI: compact currency symbol before the number.
	 * USDT → ₮1,234.5678, BTC → BTC 0.0012, etc.
	 *
	 * Decimal places are chosen automatically:
	 *   - fractional part < 0.01 and non-zero → 4 decimals (e.g. ₮0.0047)
	 *   - fractional part < 0.1  and non-zero → 3 decimals (e.g. ₮1.053)
	 *   - otherwise                           → 2 decimals (e.g. ₮1,600.00)
	 *
	 * @return string Formatted string like "₮0.0047" or "1,600.00 RATS".
	 */
	public function formatDisplay(): string {
		$abs = abs($this->amount);
		$frac = $abs - floor($abs);

		if ($frac == 0) {
			$decimals = 0;
		} elseif ($frac < 0.01) {
			$decimals = 4;
		} elseif ($frac < 0.1) {
			$decimals = 3;
		} else {
			$decimals = 2;
		}

		$formatted = number_format(abs($this->amount), $decimals, '.', ',');
		$sign = $this->amount < 0 ? '-' : '';
		if ($this->currency === 'USDT') {
			return "{$sign}₮{$formatted}";
		}
		return "{$sign}{$formatted} {$this->currency}";
	}

	public function __toString(): string {
		return $this->format();
	}

	public function setAmount(float $amount): void {
		$this->amount = $amount;
	}

	public function formatForOrder(string $qtyStep = '0.01'): string {
		$precision = str_contains($qtyStep, '.')
			? strlen(rtrim(substr($qtyStep, strpos($qtyStep, '.') + 1), '0')) : 0;
		$multiplier = pow(10, $precision);
		$value = floor($this->amount * $multiplier) / $multiplier;
		return number_format($value, $precision, '.', '');
	}

	public function isLessThan(Money $otherAmount): bool {
		$diff = ($otherAmount->getAmount() - $this->amount);
		return ($diff > 0);
	}

	public function isZero(): bool {
		return $this->amount < 0.0001;
	}

	public function modifyByPercent(float $percent): Money {
		$newAmount = $this->amount;
		$change = ($percent / 100.0) * $this->amount;
		$newAmount += $change;
		return new Money($newAmount, $this->currency);
	}

	public function modifyByPercentWithDirection(float $percent, PositionDirectionEnum $direction): Money {
		return $this->modifyByPercent($direction->getMultiplier() * $percent);
	}

	public function getPercentDifference(?Money $otherPrice): float {
		return (($otherPrice->getAmount() - $this->amount) / $this->amount) * 100;
	}
}
