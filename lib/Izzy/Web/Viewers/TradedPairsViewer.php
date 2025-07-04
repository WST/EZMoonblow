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

		// Prepare data for display using viewers
		foreach ($tradedPairs as &$pair) {
			// Render strategy parameters table
			$pair['strategyParamsHtml'] = $this->renderStrategyParamsTable($pair['strategyParams'], $pair['strategyName']);

			// Render DCA tables if available
			if (isset($pair['dcaInfo'])) {
				$pair['dcaTables'] = [];

				if (!empty($pair['dcaInfo']['orderMap']['LONG'])) {
					$pair['dcaTables']['long'] = $this->renderDCATable($pair['dcaInfo']['orderMap']['LONG'], 'Long');
				}

				if (!empty($pair['dcaInfo']['orderMap']['SHORT'])) {
					$pair['dcaTables']['short'] = $this->renderDCATable($pair['dcaInfo']['orderMap']['SHORT'], 'Short');
				}
			}
		}

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

			// Spot pairs
			$spotPairs = $exchangeConfig->getSpotPairs($exchange);
			foreach ($spotPairs as $pair) {
				if ($pair->isTradingEnabled() && $pair->getStrategyName()) {
					$tradedPairs[] = $this->buildPairData($pair, $exchange);
				}
			}

			// Futures pairs
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
		// Create Market from Pair for strategy creation
		$market = $exchange->createMarket($pair);
		if (!$market) {
			// If failed to create market, return basic information without DCA
			return [
				'exchange' => $exchange->getName(),
				'ticker' => $pair->getTicker(),
				'timeframe' => $pair->getTimeframe()->value,
				'marketType' => $pair->getMarketType()->value,
				'strategyName' => $pair->getStrategyName(),
				'strategyParams' => $pair->getStrategyParams(),
			];
		}

		// Create strategy for analysis
		$strategy = StrategyFactory::create($market, $pair->getStrategyName(), $pair->getStrategyParams());

		$data = [
			'exchange' => $exchange->getName(),
			'ticker' => $pair->getTicker(),
			'timeframe' => $pair->getTimeframe()->value,
			'marketType' => $pair->getMarketType()->value,
			'strategyName' => $pair->getStrategyName(),
			'strategyParams' => $pair->getStrategyParams(),
			'chartKey' => $pair->getChartKey(),
		];

		// If this is a DCA strategy, add order information
		if ($strategy instanceof AbstractDCAStrategy) {
			$dcaSettings = $strategy->getDCASettings();
			$orderMap = $dcaSettings->getOrderMap();

			// Don't format volumes and offsets here - let TableViewer handle formatting
			// foreach ($orderMap as $direction => &$levels) {
			// 	foreach ($levels as &$level) {
			// 		$level['volume'] = number_format($level['volume'], 2);
			// 		$level['offset'] = number_format($level['offset'], 2);
			// 	}
			// }

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

		return $viewer->setCaption('Strategy: ' . $strategyName)
			->setDataFromArray($strategyParams)
			->render();
	}

	private function getStrategyClass(string $strategyName): ?string
	{
		return StrategyFactory::getStrategyClass($strategyName);
	}

	private function renderDCATable(array $orders, string $direction): string {
		// Transform data to required format.
		$tableData = [];
		foreach ($orders as $level => $order) {
			$tableData[] = [
				'level' => ($level == 0) ? 'Entry' : $level,
				'volume' => $order['volume'],
				'offset' => $order['offset']
			];
		}

		$viewer = new TableViewer();
		return $viewer->setCaption($direction . ' positions')
			->insertTextColumn('level', 'Level', ['align' => 'center'])
			->insertMoneyColumn('volume', 'Volume (USDT)', ['align' => 'right'])
			->insertPercentColumn('offset', 'Price deviation (%)', ['align' => 'right'])
			->setData($tableData)
			->render();
	}
}
