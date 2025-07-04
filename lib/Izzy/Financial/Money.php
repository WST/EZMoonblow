<?php

namespace Izzy\Financial;

class Money
{
	private float $amount;
	private string $currency;

	public function __construct(string|int|float $amount, string $currency = 'USDT') {
		$this->amount = (float) $amount;
		$this->currency = $currency;
	}
	
	public static function from(string|int|float|null $amount, string $currency = 'USDT'): ?Money {
		if (is_null($amount)) return null;
		return new self($amount, $currency);
	}

	public function getAmount(): float {
		return $this->amount;
	}

	public function getCurrency(): string {
		return $this->currency;
	}

	public function format($format = '%.2f', bool $appendCurrency = true): string {
		$result = sprintf($format, $this->amount);
		if ($appendCurrency) $result .= " $this->currency";
		return $result;
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

	public function modifyByPercent(mixed $percent): Money {
		$newAmount = $this->amount;
		$change = ($percent / 100.0) * $this->amount;
		$newAmount += $change;
		return new Money($newAmount, $this->currency);
	}

	public function getPercentDifference(?Money $otherPrice): float {
		return (($otherPrice->getAmount() - $this->amount) / $this->amount) * 100;
	}
}
