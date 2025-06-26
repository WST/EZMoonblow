<?php

namespace Izzy\Financial;

class Money
{
	private float $amount;
	private string $currency;

	public function __construct(float $amount, string $currency = 'USDT') {
		$this->amount = $amount;
		$this->currency = $currency;
	}

	public function getAmount(): float {
		return $this->amount;
	}

	public function getCurrency(): string {
		return $this->currency;
	}

	public function format($format = '%.2f'): string {
		return sprintf($format, $this->amount) . " " . $this->currency;
	}

	public function __toString(): string {
		return $this->format();
	}

	public function setAmount(float $amount): void {
		$this->amount = $amount;
	}

	public function formatForOrder(): string {
		return $this->format('%.6f');
	}
}