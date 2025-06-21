<?php

namespace Izzy\Configuration;

use DOMElement;
use Izzy\Enums\MarketTypeEnum;
use Izzy\Interfaces\IExchangeDriver;

class ExchangeConfiguration
{
	private DOMElement $exchangeElement;
	
	public function __construct(DOMElement $exchangeElement) {
		
	}
	
	public function getKey(): string {
		
	}

	public function getSecret(): string {

	}
	
	public function getPassword(): string {
		
	}
	
	public function getName(): string {
		
	}
	
	public function connectToExchange(): IExchangeDriver {
		
	}
	
	public function getPairs(MarketTypeEnum $marketType = MarketTypeEnum::SPOT): array {
		
	}
}
