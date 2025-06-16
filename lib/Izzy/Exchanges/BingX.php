<?php

namespace Izzy\Exchanges;

use Izzy\Exchanges\AbstractExchangeDriver;
use Izzy\Money;

/**
 * Драйвер для работы с биржей BingX
 */
class BingX extends AbstractExchangeDriver
{
	protected string $exchangeName = 'BingX';

	public function connect(): bool {
		return true;
	}

	public function disconnect(): void {
		// TODO: Implement disconnect() method.
	}

	public function refreshAccountBalance(): void {
		$bingxBalance = new Money(0.0, "USDT");
		$this->setBalance($bingxBalance);
	}
}
