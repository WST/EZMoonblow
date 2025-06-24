<?php

namespace Izzy\Configuration;

use DOMElement;
use Izzy\AbstractApplications\ConsoleApplication;
use Izzy\Enums\MarketTypeEnum;
use Izzy\Enums\TimeFrameEnum;
use Izzy\Financial\Pair;
use Izzy\Interfaces\IExchangeDriver;

class ExchangeConfiguration
{
	private DOMElement $exchangeElement;
	
	public function __construct(DOMElement $exchangeElement) {
		$this->exchangeElement = $exchangeElement;
	}
	
	public function getKey(): string {
		return $this->exchangeElement->getAttribute('key');
	}

	public function getSecret(): string {
		return $this->exchangeElement->getAttribute('secret');
	}
	
	public function getPassword(): string {
		return $this->exchangeElement->getAttribute('password');
	}
	
	public function getName(): string {
		return $this->exchangeElement->getAttribute('name');
	}
	
	public function connectToExchange(ConsoleApplication $application): IExchangeDriver|false {
		$exchangeName = $this->getName();
		$className = "\\Izzy\\Exchanges\\$exchangeName";
		if (!class_exists($className)) return false;
		$exchange = new $className($this, $application);
		$exchange->connect();
		return $exchange;
	}
	
	public function isEnabled(): bool {
		return $this->exchangeElement->getAttribute('enabled') === 'yes';
	}

	public function getSpotPairs(): array {
		$spot = $this->getChildElementByTagName($this->exchangeElement, 'spot');
		if (!$spot) return [];

		$pairs = [];
		foreach ($spot->getElementsByTagName('pair') as $pairElement) {
			if (!$pairElement instanceof DOMElement) continue;
			$ticker = $pairElement->getAttribute('ticker');
			$timeframe = TimeFrameEnum::from($pairElement->getAttribute('timeframe'));
			$monitor = $pairElement->getAttribute('monitor');
			$trade = $pairElement->getAttribute('trade');
			$strategy = $pairElement->getAttribute('strategy');
			$pairs[$ticker] = new Pair($ticker, $timeframe, $this->getName(), MarketTypeEnum::SPOT);
		}

		return $pairs;
	}
	
	public function getFuturesPairs(): array {
		$futures = $this->getChildElementByTagName($this->exchangeElement, 'futures');
		if (!$futures) return [];
		
		$pairs = [];
		foreach ($futures->getElementsByTagName('pair') as $pairElement) {
			if (!$pairElement instanceof DOMElement) continue;
			$ticker = $pairElement->getAttribute('ticker');
			$timeframe = TimeFrameEnum::from($pairElement->getAttribute('timeframe'));
			$monitor = $pairElement->getAttribute('monitor');
			$trade = $pairElement->getAttribute('trade');
			$strategy = $pairElement->getAttribute('strategy');
			$pairs[$ticker] = new Pair($ticker, $timeframe, $this->getName(), MarketTypeEnum::FUTURES);
		}
		
		return $pairs;
	}

	private function getChildElementByTagName(DOMElement $parent, string $tagName): ?DOMElement {
		foreach ($parent->childNodes as $child) {
			if ($child instanceof DOMElement && $child->tagName === $tagName) {
				return $child;
			}
		}
		return null;
	}
}
