<?php

namespace Izzy\Web\Viewers;

use Izzy\AbstractApplications\WebApplication;
use Izzy\Configuration\Configuration;
use Izzy\Interfaces\IExchangeDriver;
use Izzy\Financial\Pair;
use Izzy\Strategies\AbstractDCAStrategy;
use Izzy\Strategies\DCAOrderGrid;
use Izzy\Strategies\StrategyFactory;
use Psr\Http\Message\ResponseInterface as Response;

class TradedPairsViewer extends PageViewer {
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
				$context = $pair['dcaInfo']['context'];

				/** @var DCAOrderGrid $longGrid */
				$longGrid = $pair['dcaInfo']['longGrid'];
				if (!$longGrid->isEmpty()) {
					$pair['dcaTables']['long'] = $this->renderDCAGridTable(
						$longGrid,
						$context,
						'Long'
					);
				}

				/** @var DCAOrderGrid $shortGrid */
				$shortGrid = $pair['dcaInfo']['shortGrid'];
				if (!$shortGrid->isEmpty()) {
					$pair['dcaTables']['short'] = $this->renderDCAGridTable(
						$shortGrid,
						$context,
						'Short'
					);
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
			if (!$exchangeConfig)
				continue;

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
			$context = $strategy->getMarket()->getTradingContext();

			$data['dcaInfo'] = [
				'context' => $context,
				'longGrid' => $dcaSettings->getLongGrid(),
				'shortGrid' => $dcaSettings->getShortGrid(),
				'maxLongVolume' => [
					'amount' => number_format($dcaSettings->getMaxLongPositionVolume($context)->getAmount(), 2)
				],
				'maxShortVolume' => [
					'amount' => number_format($dcaSettings->getMaxShortPositionVolume($context)->getAmount(), 2)
				],
				'expectedProfitLong' => $dcaSettings->getLongGrid()->getExpectedProfit(),
				'expectedProfitShort' => $dcaSettings->getShortGrid()->getExpectedProfit(),
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

		return $viewer->setCaption('Strategy: '.$strategyName)
			->setDataFromArray($strategyParams)
			->render();
	}

	private function getStrategyClass(string $strategyName): ?string {
		return StrategyFactory::getStrategyClass($strategyName);
	}

	/**
	 * Render DCA order grid as HTML table.
	 *
	 * @param DCAOrderGrid $grid DCA order grid.
	 * @param \Izzy\Financial\TradingContext $context Trading context for volume resolution.
	 * @param string $label Display label for the table caption.
	 * @return string Rendered HTML table.
	 */
	private function renderDCAGridTable(
		DCAOrderGrid $grid,
		\Izzy\Financial\TradingContext $context,
		string $label
	): string {
		$displayData = $grid->getDisplayData($context);

		// Transform data to required format.
		$tableData = [];
		foreach ($displayData as $level => $order) {
			$tableData[] = [
				'level' => ($level == 0) ? 'Entry' : $level,
				'volume' => $order['volume'],
				'offset' => $order['offset']
			];
		}

		$viewer = new TableViewer();
		return $viewer->setCaption($label.' positions')
			->insertTextColumn('level', 'Level', ['align' => 'center'])
			->insertMoneyColumn('volume', 'Volume', ['align' => 'right'])
			->insertPercentColumn('offset', 'Price change (%)', ['align' => 'right'])
			->setData($tableData)
			->render();
	}
}
