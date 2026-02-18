<?php

namespace Izzy\Web\Viewers;

use Izzy\AbstractApplications\WebApplication;
use Izzy\Backtest\BacktestResultRecord;
use Izzy\Configuration\StrategyConfiguration;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * Renders the Backtest Results history page.
 */
class ResultsViewer extends PageViewer
{
	public function __construct(WebApplication $webApp) {
		parent::__construct($webApp);
	}

	public function render(Response $response): Response {
		$database = $this->webApp->getDatabase();
		$records = BacktestResultRecord::loadAll($database);
		$currentConfigs = $this->loadCurrentConfigs();

		$results = array_map(function (BacktestResultRecord $r) use ($currentConfigs) {
			$arr = $r->toArray();
			$arr['matchesCurrentConfig'] = $this->matchesCurrentConfig($r, $currentConfigs);
			return $arr;
		}, $records);

		$body = $this->webApp->getTwig()->render('results.htt', [
			'menu' => $this->menu,
			'results' => $results,
		]);
		$response->getBody()->write($body);
		return $response;
	}

	/**
	 * Build a lookup table of current pair strategy configurations from config.xml.
	 *
	 * @return array<string, StrategyConfiguration> Keyed by "Exchange|Ticker|MarketType|Timeframe".
	 */
	private function loadCurrentConfigs(): array {
		$config = $this->webApp->getConfiguration();
		$configs = [];
		foreach ($config->getExchangeNames() as $exchangeName) {
			$exchConfig = $config->getExchangeConfiguration($exchangeName);
			if (!$exchConfig) {
				continue;
			}
			foreach ($exchConfig->getAllPairConfigs() as $pc) {
				$key = $pc['exchangeName'] . '|' . $pc['ticker'] . '|' . $pc['marketType'] . '|' . $pc['timeframe'];
				$configs[$key] = new StrategyConfiguration($pc['strategyName'], $pc['params']);
			}
		}
		return $configs;
	}

	/**
	 * Check whether a backtest result's configuration matches the current config.xml.
	 *
	 * @param BacktestResultRecord $record Backtest result.
	 * @param array<string, StrategyConfiguration> $currentConfigs Lookup table.
	 * @return bool True if strategy and all backtest-relevant params match.
	 */
	private function matchesCurrentConfig(BacktestResultRecord $record, array $currentConfigs): bool {
		$key = $record->getExchangeName() . '|' . $record->getTicker() . '|' . $record->getMarketType() . '|' . $record->getTimeframe();
		if (!isset($currentConfigs[$key])) {
			return false;
		}
		$backtestConfig = new StrategyConfiguration($record->getStrategy(), $record->getStrategyParams());
		return $currentConfigs[$key]->equals($backtestConfig);
	}
}
