<?php

namespace Izzy\Web\Viewers;

use Izzy\AbstractApplications\WebApplication;
use Izzy\Configuration\Configuration;
use Izzy\Interfaces\IExchangeDriver;
use Izzy\Financial\Pair;
use Psr\Http\Message\ResponseInterface as Response;

class WatchedPairsViewer extends PageViewer
{
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
		
		foreach ($exchanges as $exchange) {
			$exchangeConfig = $this->getExchangeConfig($exchange->getName());
			if (!$exchangeConfig) continue;
			
			// Spot pairs
			$spotPairs = $exchangeConfig->getSpotPairs($exchange);
			foreach ($spotPairs as $pair) {
				if ($pair->isMonitoringEnabled()) {
					$watchedPairs[] = $this->buildPairData($pair, $exchange);
				}
			}
			
			// Futures pairs
			$futuresPairs = $exchangeConfig->getFuturesPairs($exchange);
			foreach ($futuresPairs as $pair) {
				if ($pair->isMonitoringEnabled()) {
					$watchedPairs[] = $this->buildPairData($pair, $exchange);
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
				$document->load(IZZY_CONFIG . "/config.xml");
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
		];
		
		return $data;
	}
	
	private function renderStrategyParamsTable(array $strategyParams, string $strategyName): string {
		$viewer = new DetailViewer();
		return $viewer->setCaption('Strategy: ' . $strategyName)
		             ->setDataFromArray($strategyParams)
		             ->render();
	}
} 