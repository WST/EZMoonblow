<?php

namespace Izzy\Configuration;

use DOMElement;
use InvalidArgumentException;
use Izzy\AbstractApplications\IzzyApplication;
use Izzy\Enums\MarketTypeEnum;
use Izzy\Enums\TimeFrameEnum;
use Izzy\Financial\Pair;
use Izzy\Interfaces\IExchangeDriver;
use Izzy\Interfaces\IMarket;

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

	public function connectToExchange(IzzyApplication $application): IExchangeDriver|false {
		// Check if exchange is enabled
		if (!$this->isEnabled()) {
			return false;
		}

		$exchangeName = $this->getName();
		$className = "\\Izzy\\Exchanges\\$exchangeName\\$exchangeName";
		if (!class_exists($className))
			return false;
		$exchange = new $className($this, $application);
		$exchange->connect();
		return $exchange;
	}

	public function isEnabled(): bool {
		return $this->exchangeElement->getAttribute('enabled') === 'yes';
	}

	public function getSpotPairs(IExchangeDriver $exchangeDriver): array {
		$spot = $this->getChildElementByTagName($this->exchangeElement, 'spot');
		if (!$spot)
			return [];

		$pairs = [];
		foreach ($spot->getElementsByTagName('pair') as $pairElement) {
			if (!$pairElement instanceof DOMElement)
				continue;
			$ticker = $pairElement->getAttribute('ticker');
			$timeframe = TimeFrameEnum::from($pairElement->getAttribute('timeframe'));
			$trade = $pairElement->getAttribute('trade');
			$notify = $pairElement->getAttribute('notify');

			$pair = new Pair(
				$ticker,
				$timeframe,
				$this->getName(),
				MarketTypeEnum::SPOT
			);
			$pair->setTradingEnabled($trade == 'yes');
			$pair->setNotificationsEnabled($notify !== 'no');

			// Parse strategy configuration
			$strategyConfig = $this->parseStrategyConfig($pairElement);
			if ($strategyConfig) {
				$pair->setStrategyName($strategyConfig['name']);
				$pair->setStrategyParams($strategyConfig['params']);
				$pair->setBacktestDays($strategyConfig['backtest_days'] ?? null);
				$pair->setBacktestInitialBalance($strategyConfig['backtest_initial_balance'] ?? null);
			}

			$pairs[$pair->getExchangeTicker($exchangeDriver)] = $pair;
		}

		return $pairs;
	}

	public function getFuturesPairs(IExchangeDriver $exchangeDriver): array {
		$futures = $this->getChildElementByTagName($this->exchangeElement, 'futures');
		if (!$futures)
			return [];

		$pairs = [];
		foreach ($futures->getElementsByTagName('pair') as $pairElement) {
			if (!$pairElement instanceof DOMElement)
				continue;
			$ticker = $pairElement->getAttribute('ticker');
			$timeframe = TimeFrameEnum::from($pairElement->getAttribute('timeframe'));
			$trade = $pairElement->getAttribute('trade');
			$notify = $pairElement->getAttribute('notify');

			$pair = new Pair(
				$ticker,
				$timeframe,
				$this->getName(),
				MarketTypeEnum::FUTURES
			);
			$pair->setTradingEnabled($trade == 'yes');
			$pair->setNotificationsEnabled($notify !== 'no');

			// Parse strategy configuration
			$strategyConfig = $this->parseStrategyConfig($pairElement);
			if ($strategyConfig) {
				$pair->setStrategyName($strategyConfig['name']);
				$pair->setStrategyParams($strategyConfig['params']);
				$pair->setBacktestDays($strategyConfig['backtest_days'] ?? null);
				$pair->setBacktestInitialBalance($strategyConfig['backtest_initial_balance'] ?? null);
			}

			$pairs[$pair->getExchangeTicker($exchangeDriver)] = $pair;
		}

		return $pairs;
	}

	/**
	 * Parse strategy configuration from pair element.
	 *
	 * @param DOMElement $pairElement Pair element containing strategy configuration.
	 * @return array|null Strategy configuration with 'name' and 'params' keys, or null if no strategy.
	 */
	private function parseStrategyConfig(DOMElement $pairElement): ?array {
		$strategyElements = $pairElement->getElementsByTagName('strategy');

		// Check that there’s only 0 or 1 strategy elements
		if ($strategyElements->length > 1) {
			throw new InvalidArgumentException("Pair {$pairElement->getAttribute('ticker')} has more than one strategy defined");
		}

		if ($strategyElements->length === 0) {
			return null;
		}

		$strategyElement = $strategyElements->item(0);
		if (!$strategyElement instanceof DOMElement) {
			return null;
		}

		$strategyName = $strategyElement->getAttribute('name');
		if (empty($strategyName)) {
			throw new InvalidArgumentException("Strategy name is required for pair {$pairElement->getAttribute('ticker')}");
		}

		// Parse strategy parameters
		$params = [];
		foreach ($strategyElement->getElementsByTagName('param') as $paramElement) {
			if (!$paramElement instanceof DOMElement)
				continue;

			$name = $paramElement->getAttribute('name');
			$value = $paramElement->getAttribute('value');

			if (empty($name))
				continue;

			$params[$name] = $this->parseParameterValue($value);
		}

		$backtestDaysAttr = $strategyElement->getAttribute('backtest_days');
		$backtestDays = $backtestDaysAttr !== '' && ctype_digit($backtestDaysAttr) ? (int)$backtestDaysAttr : null;

		$backtestBalanceAttr = $strategyElement->getAttribute('backtest_initial_balance');
		$backtestInitialBalance = ($backtestBalanceAttr !== '' && is_numeric($backtestBalanceAttr) && (float)$backtestBalanceAttr > 0)
			? (float)$backtestBalanceAttr : null;

		return [
			'name' => $strategyName,
			'params' => $params,
			'backtest_days' => $backtestDays,
			'backtest_initial_balance' => $backtestInitialBalance,
		];
	}

	/**
	 * Get indicators configuration for a specific trading pair.
	 * @param IMarket $market
	 * @return array Indicators configuration.
	 */
	public function getIndicatorsConfig(IMarket $market): array {
		$marketElement = $this->getChildElementByTagName($this->exchangeElement, $market->getMarketType()->toString());
		if (!$marketElement) {
			return [];
		}

		// Find the pair element with matching ticker and timeframe
		$pairElement = null;
		foreach ($marketElement->getElementsByTagName('pair') as $element) {
			if ($element instanceof DOMElement &&
				$element->getAttribute('ticker') === $market->getTicker() &&
				$element->getAttribute('timeframe') === $market->getTimeframe()->value) {
				$pairElement = $element;
				break;
			}
		}

		if (!$pairElement) {
			return [];
		}

		// Get indicators element
		$indicatorsElement = $this->getChildElementByTagName($pairElement, 'indicators');
		if (!$indicatorsElement) {
			return [];
		}

		$indicators = [];
		foreach ($indicatorsElement->getElementsByTagName('indicator') as $indicatorElement) {
			if (!$indicatorElement instanceof DOMElement)
				continue;

			$type = $indicatorElement->getAttribute('type');
			if (empty($type))
				continue;

			$parameters = [];
			// Get all attributes except 'type'
			foreach ($indicatorElement->attributes as $attribute) {
				if ($attribute->name !== 'type') {
					$parameters[$attribute->name] = $this->parseParameterValue($attribute->value);
				}
			}

			$indicators[$type] = $parameters;
		}

		return $indicators;
	}

	/**
	 * Get pair tickers for a specific market type without requiring an exchange driver.
	 *
	 * This is a lightweight method that returns only ticker strings,
	 * useful for UI display when full Pair objects are not needed.
	 *
	 * @param MarketTypeEnum $marketType Market type.
	 * @return string[] Array of ticker strings (e.g., ['BTC/USDT', 'ETH/USDT']).
	 */
	public function getPairTickers(MarketTypeEnum $marketType): array {
		$marketElement = $this->getChildElementByTagName($this->exchangeElement, $marketType->toString());
		if (!$marketElement) {
			return [];
		}

		$tickers = [];
		foreach ($marketElement->getElementsByTagName('pair') as $pairElement) {
			if (!$pairElement instanceof DOMElement) {
				continue;
			}
			$ticker = $pairElement->getAttribute('ticker');
			if ($ticker) {
				$tickers[] = $ticker;
			}
		}

		return $tickers;
	}

	/**
	 * Get pairs that have indicators configured.
	 *
	 * Returns an array of Pair objects for pairs that have at least one indicator defined.
	 *
	 * @return Pair[] Array of Pair objects with indicators.
	 */
	public function getPairsWithIndicators(): array {
		$pairsWithIndicators = [];
		$exchangeName = $this->getName();

		foreach (MarketTypeEnum::cases() as $marketType) {
			$marketElement = $this->getChildElementByTagName($this->exchangeElement, $marketType->value);
			if (!$marketElement) {
				continue;
			}

			foreach ($marketElement->getElementsByTagName('pair') as $pairElement) {
				if (!$pairElement instanceof DOMElement) {
					continue;
				}
				$indicatorsElement = $this->getChildElementByTagName($pairElement, 'indicators');
				if ($indicatorsElement && $indicatorsElement->getElementsByTagName('indicator')->length > 0) {
					$timeframe = TimeFrameEnum::tryFrom($pairElement->getAttribute('timeframe'));
					if (!$timeframe) {
						continue;
					}
					$pairsWithIndicators[] = new Pair(
						ticker: $pairElement->getAttribute('ticker'),
						timeFrame: $timeframe,
						exchangeName: $exchangeName,
						marketType: $marketType
					);
				}
			}
		}

		return $pairsWithIndicators;
	}

	/**
	 * Parse parameter value to appropriate type.
	 *
	 * @param string $value Parameter value as string.
	 * @return mixed Parsed value (int, float, string, or bool).
	 */
	private function parseParameterValue(string $value): mixed {
		// Try to parse as integer
		if (is_numeric($value) && ctype_digit($value)) {
			return (int)$value;
		}

		// Try to parse as float
		if (is_numeric($value)) {
			return (float)$value;
		}

		// Try to parse as boolean
		if (in_array(strtolower($value), ['true', 'false', 'yes', 'no', '1', '0'])) {
			return in_array(strtolower($value), ['true', 'yes', '1']);
		}

		// Return as string
		return $value;
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
