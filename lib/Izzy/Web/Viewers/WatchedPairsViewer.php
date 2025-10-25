<?php

namespace Izzy\Web\Viewers;

use Izzy\AbstractApplications\WebApplication;
use Izzy\Configuration\Configuration;
use Izzy\Interfaces\IExchangeDriver;
use Izzy\Financial\Pair;
use Izzy\Strategies\StrategyFactory;
use Psr\Http\Message\ResponseInterface as Response;

class WatchedPairsViewer extends PageViewer {
	public function __construct(WebApplication $webApp) {
		parent::__construct($webApp);
	}

	public function render(Response $response): Response {
		$watchedPairs = $this->getWatchedPairs();

		// Prepare data for display using viewers
		foreach ($watchedPairs as &$pair) {
			// Render strategy parameters table if strategy is configured
			if (!empty($pair['strategyParams'])) {
				$pair['strategyParamsHtml'] = $this->renderStrategyParamsTable($pair['strategyParams'], $pair['strategyName']);
			}
		}

		$body = $this->webApp->getTwig()->render('watched-pairs.htt', [
			'menu' => $this->menu,
			'watchedPairs' => $watchedPairs
		]);
		$response->getBody()->write($body);
		return $response;
	}

	private function getWatchedPairs(): array {
		$config = Configuration::getInstance();
		$exchanges = $config->connectExchanges($this->webApp);
		$watchedPairs = [];

		// Get traded pairs to exclude them
		$tradedPairs = $this->getTradedPairs();
		$tradedPairsKeys = [];
		foreach ($tradedPairs as $tradedPair) {
			$tradedPairsKeys[] = $this->getPairKey($tradedPair['exchange'], $tradedPair['ticker'], $tradedPair['timeframe'], $tradedPair['marketType']);
		}

		foreach ($exchanges as $exchange) {
			$exchangeConfig = $this->getExchangeConfig($exchange->getName());
			if (!$exchangeConfig)
				continue;

			// Spot pairs
			$spotPairs = $exchangeConfig->getSpotPairs($exchange);
			foreach ($spotPairs as $pair) {
				if ($pair->isMonitoringEnabled()) {
					$pairKey = $this->getPairKey($exchange->getName(), $pair->getTicker(), $pair->getTimeframe()->value, $pair->getMarketType()->value);

					// Skip if this pair is already in trading
					if (!in_array($pairKey, $tradedPairsKeys)) {
						$watchedPairs[] = $this->buildPairData($pair, $exchange);
					}
				}
			}

			// Futures pairs
			$futuresPairs = $exchangeConfig->getFuturesPairs($exchange);
			foreach ($futuresPairs as $pair) {
				if ($pair->isMonitoringEnabled()) {
					$pairKey = $this->getPairKey($exchange->getName(), $pair->getTicker(), $pair->getTimeframe()->value, $pair->getMarketType()->value);

					// Skip if this pair is already in trading
					if (!in_array($pairKey, $tradedPairsKeys)) {
						$watchedPairs[] = $this->buildPairData($pair, $exchange);
					}
				}
			}
		}

		return $watchedPairs;
	}

	private function getExchangeConfig(string $exchangeName): ?\Izzy\Configuration\ExchangeConfiguration {
		$config = Configuration::getInstance();
		$exchanges = $config->connectExchanges($this->webApp);

		foreach ($exchanges as $exchange) {
			if ($exchange->getName() === $exchangeName) {
				// Get exchange configuration from XML
				$document = new \DOMDocument();
				$document->load(IZZY_CONFIG."/config.xml");
				$xpath = new \DOMXPath($document);

				$exchangeElement = $xpath->query("//exchanges/exchange[@name='$exchangeName']")->item(0);
				if ($exchangeElement) {
					return new \Izzy\Configuration\ExchangeConfiguration($exchangeElement);
				}
			}
		}

		return null;
	}

	private function buildPairData(Pair $pair, IExchangeDriver $exchange): array {
		$data = [
			'exchange' => $exchange->getName(),
			'ticker' => $pair->getTicker(),
			'timeframe' => $pair->getTimeframe()->value,
			'marketType' => $pair->getMarketType()->value,
			'strategyName' => $pair->getStrategyName(),
			'strategyParams' => $pair->getStrategyParams(),
			'chartKey' => $pair->getChartKey(),
		];

		return $data;
	}

	private function renderStrategyParamsTable(array $strategyParams, string $strategyName): string {
		$viewer = new DetailViewer(['showHeader' => false]);

		// Try to find parameter formatter in strategy class.
		$strategyClass = $this->getStrategyClass($strategyName);
		if ($strategyClass && method_exists($strategyClass, 'formatParameterName')) {
			$viewer->insertKeyColumn('key', 'Parameter', [$strategyClass, 'formatParameterName'], [
				'align' => 'left',
				'width' => '40%',
				'class' => 'param-name'
			]);
		}

		return $viewer->setCaption('Strategy: '.$strategyName)
			->setDataFromArray($strategyParams)
			->render();
	}

	private function getStrategyClass(string $strategyName): ?string {
		return StrategyFactory::getStrategyClass($strategyName);
	}

	/**
	 * Get traded pairs to exclude them from watched pairs.
	 */
	private function getTradedPairs(): array {
		$config = Configuration::getInstance();
		$exchanges = $config->connectExchanges($this->webApp);
		$tradedPairs = [];

		foreach ($exchanges as $exchange) {
			$exchangeConfig = $this->getExchangeConfig($exchange->getName());
			if (!$exchangeConfig)
				continue;

			// Spot pairs
			$spotPairs = $exchangeConfig->getSpotPairs($exchange);
			foreach ($spotPairs as $pair) {
				if ($pair->isTradingEnabled() && $pair->getStrategyName()) {
					$tradedPairs[] = [
						'exchange' => $exchange->getName(),
						'ticker' => $pair->getTicker(),
						'timeframe' => $pair->getTimeframe()->value,
						'marketType' => $pair->getMarketType()->value,
					];
				}
			}

			// Futures pairs
			$futuresPairs = $exchangeConfig->getFuturesPairs($exchange);
			foreach ($futuresPairs as $pair) {
				if ($pair->isTradingEnabled() && $pair->getStrategyName()) {
					$tradedPairs[] = [
						'exchange' => $exchange->getName(),
						'ticker' => $pair->getTicker(),
						'timeframe' => $pair->getTimeframe()->value,
						'marketType' => $pair->getMarketType()->value,
					];
				}
			}
		}

		return $tradedPairs;
	}

	/**
	 * Create a unique key for a trading pair.
	 */
	private function getPairKey(string $exchange, string $ticker, string $timeframe, string $marketType): string {
		return $exchange.':'.$ticker.':'.$timeframe.':'.$marketType;
	}
}
