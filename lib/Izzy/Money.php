<?php

namespace Izzy;

class Money {
	private $amount;
	private $currency;

	public function __construct(float $amount, string $currency = 'USDT') {
		$this->amount = $amount;
		$this->currency = $currency;
	}

	public function getAmount(): float {
		return $this->amount;
	}

	public function format($format = '%.2f'): string {
		return sprintf($format, $this->amount) . " " . $this->currency;
	}

	public function __toString(): string {
		return $this->format();
	}

	public function setAmount(float $amount) {
		$this->amount = $amount;
	}
}