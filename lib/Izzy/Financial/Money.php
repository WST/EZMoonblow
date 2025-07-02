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
	
	public static function from(string|int|float $amount, string $currency = 'USDT'): Money {
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

	public function formatForOrder(): string {
		return $this->format('%.4f', false);
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
}
