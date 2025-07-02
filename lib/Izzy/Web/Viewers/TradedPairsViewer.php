<?php

namespace Izzy\Web\Viewers;

use Izzy\AbstractApplications\WebApplication;
use Izzy\Configuration\Configuration;
use Izzy\Interfaces\IExchangeDriver;
use Izzy\Financial\Pair;
use Izzy\Strategies\AbstractDCAStrategy;
use Izzy\Strategies\StrategyFactory;
use Psr\Http\Message\ResponseInterface as Response;

class TradedPairsViewer extends PageViewer
{
	public function __construct(WebApplication $webApp) {
		parent::__construct($webApp);
	}
	
	public function render(Response $response): Response {
		$tradedPairs = $this->getTradedPairs();
		
		$body = $this->webApp->getTwig()->render('traded-pairs.htt', [
			'menu' => $this->menu,
			'tradedPairs' => $tradedPairs
		]);
		$response->getBody()->write($body);
		return $response;
	}
	
	private function getTradedPairs(): array {
		$config = Configuration::getInstance();
		$exchanges = $config->connectExchanges($this->webApp);
		$tradedPairs = [];
		
		foreach ($exchanges as $exchange) {
			$exchangeConfig = $this->getExchangeConfig($exchange->getName());
			if (!$exchangeConfig) continue;
			
			// Spot пары
			$spotPairs = $exchangeConfig->getSpotPairs($exchange);
			foreach ($spotPairs as $pair) {
				if ($pair->isTradingEnabled() && $pair->getStrategyName()) {
					$tradedPairs[] = $this->buildPairData($pair, $exchange);
				}
			}
			
			// Futures пары
			$futuresPairs = $exchangeConfig->getFuturesPairs($exchange);
			foreach ($futuresPairs as $pair) {
				if ($pair->isTradingEnabled() && $pair->getStrategyName()) {
					$tradedPairs[] = $this->buildPairData($pair, $exchange);
				}
			}
		}
		
		return $tradedPairs;
	}
	
	private function getExchangeConfig(string $exchangeName): ?\Izzy\Configuration\ExchangeConfiguration {
		$config = Configuration::getInstance();
		$exchanges = $config->connectExchanges($this->webApp);
		
		foreach ($exchanges as $exchange) {
			if ($exchange->getName() === $exchangeName) {
				// Получить конфигурацию биржи из XML
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
		// Создать Market из Pair для создания стратегии
		$market = $exchange->createMarket($pair);
		if (!$market) {
			// Если не удалось создать market, возвращаем базовую информацию без DCA
			return [
				'exchange' => $exchange->getName(),
				'ticker' => $pair->getTicker(),
				'timeframe' => $pair->getTimeframe()->value,
				'marketType' => $pair->getMarketType()->value,
				'strategyName' => $pair->getStrategyName(),
				'strategyParams' => $pair->getStrategyParams(),
			];
		}
		
		// Создать стратегию для анализа
		$strategy = StrategyFactory::create($market, $pair->getStrategyName(), $pair->getStrategyParams());
		
		$data = [
			'exchange' => $exchange->getName(),
			'ticker' => $pair->getTicker(),
			'timeframe' => $pair->getTimeframe()->value,
			'marketType' => $pair->getMarketType()->value,
			'strategyName' => $pair->getStrategyName(),
			'strategyParams' => $pair->getStrategyParams(),
		];
		
		// Если это DCA-стратегия, добавить информацию об ордерах
		if ($strategy instanceof AbstractDCAStrategy) {
			$dcaSettings = $strategy->getDCASettings();
			$orderMap = $dcaSettings->getOrderMap();
			
			// Форматируем объемы и отступы
			foreach ($orderMap as $direction => &$levels) {
				foreach ($levels as &$level) {
					$level['volume'] = number_format($level['volume'], 2);
					$level['offset'] = number_format($level['offset'], 2);
				}
			}
			
			$data['dcaInfo'] = [
				'orderMap' => $orderMap,
				'maxLongVolume' => [
					'amount' => number_format($dcaSettings->getMaxLongPositionVolume()->getAmount(), 2)
				],
				'maxShortVolume' => [
					'amount' => number_format($dcaSettings->getMaxShortPositionVolume()->getAmount(), 2)
				],
				'useLimitOrders' => $dcaSettings->isUseLimitOrders(),
			];
		}
		
		return $data;
	}
} 