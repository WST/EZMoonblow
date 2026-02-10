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

class PairsViewer extends PageViewer
{
	public function __construct(WebApplication $webApp) {
		parent::__construct($webApp);
	}

	public function render(Response $response): Response {
		$pairs = $this->getPairs();

		// Prepare data for display using viewers
		foreach ($pairs as &$pair) {
			// Render strategy parameters table if strategy is configured
			if (!empty($pair['strategyName'])) {
				$pair['strategyParamsHtml'] = $this->renderStrategyParamsTable($pair['strategyParams'], $pair['strategyName']);
			}

			// Render DCA tables only for directions the strategy actually supports.
			if (isset($pair['dcaInfo'])) {
				$pair['dcaTables'] = [];
				$context = $pair['dcaInfo']['context'];
				$doesLong = $pair['doesLong'] ?? true;
				$doesShort = $pair['doesShort'] ?? false;

				/** @var DCAOrderGrid $longGrid */
				$longGrid = $pair['dcaInfo']['longGrid'];
				if ($doesLong && !$longGrid->isEmpty()) {
					$pair['dcaTables']['long'] = $this->renderDCAGridTable(
						$longGrid,
						$context,
						'Long'
					);
				}

				/** @var DCAOrderGrid $shortGrid */
				$shortGrid = $pair['dcaInfo']['shortGrid'];
				if ($doesShort && !$shortGrid->isEmpty()) {
					$pair['dcaTables']['short'] = $this->renderDCAGridTable(
						$shortGrid,
						$context,
						'Short'
					);
				}
			}
		}

		$body = $this->webApp->getTwig()->render('pairs.htt', [
			'menu' => $this->menu,
			'pairs' => $pairs
		]);
		$response->getBody()->write($body);
		return $response;
	}

	/**
	 * Get all configured pairs from all enabled exchanges.
	 *
	 * Every pair in the config is included regardless of the trade flag.
	 * The trade flag is passed through so the template can show the status.
	 *
	 * @return array[]
	 */
	private function getPairs(): array {
		$config = Configuration::getInstance();
		$exchanges = $config->connectExchanges($this->webApp);
		$pairs = [];

		foreach ($exchanges as $exchange) {
			$exchangeConfig = $this->getExchangeConfig($exchange->getName());
			if (!$exchangeConfig) {
				continue;
			}

			// Spot pairs
			$spotPairs = $exchangeConfig->getSpotPairs($exchange);
			foreach ($spotPairs as $pair) {
				$pairs[] = $this->buildPairData($pair, $exchange);
			}

			// Futures pairs
			$futuresPairs = $exchangeConfig->getFuturesPairs($exchange);
			foreach ($futuresPairs as $pair) {
				$pairs[] = $this->buildPairData($pair, $exchange);
			}
		}

		return $pairs;
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
			'tradingEnabled' => $pair->isTradingEnabled(),
			'chartKey' => $pair->getChartKey(),
		];

		// If strategy is configured, try to get DCA grid info for display.
		if ($pair->getStrategyName()) {
			$market = $exchange->createMarket($pair);
			if ($market) {
				$strategy = StrategyFactory::create($market, $pair->getStrategyName(), $pair->getStrategyParams());

				if ($strategy instanceof AbstractDCAStrategy) {
					// Use filtered parameters that respect doesLong/doesShort.
					$data['strategyParams'] = $strategy->getDisplayParameters();
					$data['doesLong'] = $strategy->doesLong();
					$data['doesShort'] = $strategy->doesShort();

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
			}
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

		// Format parameter values (e.g. yes/no -> Yes/No) if the strategy supports it.
		if ($strategyClass && method_exists($strategyClass, 'formatParameterValue')) {
			foreach ($strategyParams as $key => $value) {
				$strategyParams[$key] = $strategyClass::formatParameterValue($key, (string)$value);
			}
		}

		return $viewer->setCaption('Strategy: ' . $strategyName)
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
		return $viewer->setCaption($label . ' positions')
			->insertTextColumn('level', 'Level', ['align' => 'center'])
			->insertMoneyColumn('volume', 'Volume', ['align' => 'right'])
			->insertPercentColumn('offset', 'Price change (%)', ['align' => 'right'])
			->setData($tableData)
			->render();
	}
}
