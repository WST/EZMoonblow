<?php

namespace Izzy\Web\Viewers;

use Izzy\AbstractApplications\WebApplication;
use Izzy\Configuration\Configuration;
use Izzy\Interfaces\IExchangeDriver;
use Izzy\Financial\Market;
use Izzy\Financial\MarketCandleRepository;
use Izzy\Financial\Money;
use Izzy\Financial\Pair;
use Izzy\Financial\AbstractDCAStrategy;
use Izzy\Financial\AbstractSingleEntryStrategy;
use Izzy\Financial\DCAOrderGrid;
use Izzy\Financial\StrategyFactory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class PairsViewer extends PageViewer
{
	public function __construct(WebApplication $webApp) {
		parent::__construct($webApp);
	}

	public function render(Response $response, ?Request $request = null): Response {
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
			$exchangeConfig = $config->getExchangeConfiguration($exchange->getName());
			if (!$exchangeConfig) {
				continue;
			}

			$spotPairs = $exchangeConfig->getSpotPairs($exchange);
			foreach ($spotPairs as $pair) {
				$pairs[] = $this->buildPairData($pair, $exchange);
			}

			$futuresPairs = $exchangeConfig->getFuturesPairs($exchange);
			foreach ($futuresPairs as $pair) {
				$pairs[] = $this->buildPairData($pair, $exchange);
			}
		}

		return $pairs;
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

		if ($pair->getStrategyName()) {
			$market = new Market($exchange, $pair);

			$candleRepo = new MarketCandleRepository($this->webApp->getDatabase());
			$now = time();
			$lookback = $pair->getTimeframe()->toSeconds() * 200;
			$candles = $candleRepo->getCandles($pair, $now - $lookback, $now);
			if (!empty($candles)) {
				$market->setCandles($candles);
				$lastCandle = end($candles);
				$market->setCurrentPrice(Money::from($lastCandle->getClosePrice()));
			}

			$strategy = StrategyFactory::create($market, $pair->getStrategyName(), $pair->getStrategyParams());

			$validation = $strategy->validateExchangeSettings($market);
			if (!$validation->isValid()) {
				$data['validationErrors'] = $validation->getErrors();
			}
			if (!empty($validation->getWarnings())) {
				$data['validationWarnings'] = $validation->getWarnings();
			}

			if ($strategy instanceof AbstractDCAStrategy) {
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
			} elseif ($strategy instanceof AbstractSingleEntryStrategy) {
				$data['strategyParams'] = $strategy->getDisplayParameters();
				$data['doesLong'] = $strategy->doesLong();
				$data['doesShort'] = $strategy->doesShort();
			}
		}

		return $data;
	}

	private function renderStrategyParamsTable(array $strategyParams, string $strategyName): string {
		$viewer = new DetailViewer(['showHeader' => false]);

		// Build a key => label map from typed parameter objects.
		$strategyClass = StrategyFactory::getStrategyClass($strategyName);
		$labelMap = [];
		if ($strategyClass !== null && method_exists($strategyClass, 'getParameters')) {
			foreach ($strategyClass::getParameters() as $param) {
				$labelMap[$param->getName()] = $param->getLabel();
			}
		}

		if (!empty($labelMap)) {
			$viewer->insertKeyColumn('key', 'Parameter', fn(string $key) => $labelMap[$key] ?? $key, [
				'align' => 'left',
				'width' => '40%',
				'class' => 'param-name'
			]);
		}

		// Format boolean values for readability.
		foreach ($strategyParams as $key => $value) {
			$strValue = strtolower((string)$value);
			if (in_array($strValue, ['true', '1', 'yes'], true)) {
				$strategyParams[$key] = 'Yes';
			} elseif (in_array($strValue, ['false', '0', 'no'], true)) {
				$strategyParams[$key] = 'No';
			}
		}

		return $viewer->setCaption('Strategy: ' . $strategyName)
			->setDataFromArray($strategyParams)
			->render();
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
