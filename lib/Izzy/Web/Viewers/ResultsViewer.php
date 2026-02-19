<?php

namespace Izzy\Web\Viewers;

use Izzy\AbstractApplications\WebApplication;
use Izzy\Backtest\BacktestResultRecord;
use Izzy\Configuration\StrategyConfiguration;
use Izzy\Web\Filters\BacktestResultsFilter;
use Izzy\Web\Table\DeleteAction;
use Izzy\Web\Table\TableFilter;
use Izzy\Web\Table\TablePagination;
use Izzy\Web\Tables\BacktestResultsTable;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ResultsViewer extends PageViewer
{
	public function __construct(WebApplication $webApp) {
		parent::__construct($webApp);
	}

	public function render(Response $response, ?Request $request = null): Response {
		$database = $this->webApp->getDatabase();

		$filterTemplate = BacktestResultsFilter::create($database);

		if ($request !== null) {
			$filter = TableFilter::fromRequest($request, $filterTemplate);
			$paginationRequest = TablePagination::fromRequest($request, 25);
		} else {
			$filter = $filterTemplate;
			$paginationRequest = new TablePagination(1, 25);
		}

		[$where, $orderBy] = $this->buildQuery($filter);
		$groupBy = $filter->getValue('groupBy');
		$isGrouped = !empty($groupBy);

		if ($isGrouped) {
			// Grouping requires all matching rows loaded first, then
			// grouped in PHP, and only then paginated.
			$allRecords = BacktestResultRecord::loadFiltered($database, $where, $orderBy);
			$allResults = $this->prepareResults($allRecords, $filter);
			$total = count($allResults);
			$pagination = $paginationRequest->withTotal($total);
			$results = array_slice($allResults, $pagination->getOffset(), $pagination->getPerPage());
		} else {
			$total = BacktestResultRecord::countFiltered($database, $where);
			$pagination = $paginationRequest->withTotal($total);
			$records = BacktestResultRecord::loadFiltered(
				$database, $where, $orderBy,
				$pagination->getPerPage(), $pagination->getOffset()
			);
			$results = $this->prepareResults($records, $filter);
		}

		$currentConfigs = $this->loadCurrentConfigs();

		$table = BacktestResultsTable::create();
		$table->setPagination($pagination);
		$table->addAction(new DeleteAction('/cgi-bin/api.pl?action=delete_result'));
		$table->setRowClassCallback(fn($row) => ($row['matchesCurrentConfig'] ?? false) ? 'config-match' : '');
		$table->setRowDataAttributes(fn($row, $i) => ['data-idx' => $i]);

		foreach ($results as &$r) {
			$r['matchesCurrentConfig'] = $this->matchesCurrentConfigFromArray($r, $currentConfigs);
		}
		unset($r);

		$table->setData($results);

		$baseUrl = '/results.jsp?' . http_build_query($filter->getQueryParams());

		$body = $this->webApp->getTwig()->render('results.htt', [
			'menu' => $this->menu,
			'filterHtml' => $filter->render(),
			'tableHtml' => $table->render(),
			'paginationHtml' => $pagination->render($baseUrl),
			'results' => $results,
		]);
		$response->getBody()->write($body);
		return $response;
	}

	/**
	 * Translate filter values into a WHERE array and ORDER BY string.
	 *
	 * @return array{0: array, 1: string}
	 */
	private function buildQuery(TableFilter $filter): array {
		$where = [];

		$exchange = $filter->getValue('exchange');
		if (!empty($exchange)) {
			$where[BacktestResultRecord::FExchangeName] = $exchange;
		}

		$ticker = $filter->getValue('ticker');
		if (!empty($ticker)) {
			$where[BacktestResultRecord::FTicker] = $ticker;
		}

		$marketType = $filter->getValue('marketType');
		if (!empty($marketType)) {
			$where[BacktestResultRecord::FMarketType] = $marketType;
		}

		$timeframe = $filter->getValue('timeframe');
		if (!empty($timeframe)) {
			$where[BacktestResultRecord::FTimeframe] = $timeframe;
		}

		$strategy = $filter->getValue('strategy');
		if (!empty($strategy)) {
			$where[BacktestResultRecord::FStrategy] = $strategy;
		}

		$orderBy = match ($filter->getValue('sortBy')) {
			'pnl' => BacktestResultRecord::FPnl . ' DESC',
			'winRate' => '(' . BacktestResultRecord::FTradesWins . ' / GREATEST(' . BacktestResultRecord::FTradesFinished . ', 1)) DESC',
			'trades' => BacktestResultRecord::FTradesFinished . ' DESC',
			'sharpe' => BacktestResultRecord::FSharpe . ' DESC',
			default => BacktestResultRecord::FCreatedAt . ' DESC',
		};

		return [$where, $orderBy];
	}

	/**
	 * Convert records to arrays and apply grouping if requested.
	 */
	private function prepareResults(array $records, TableFilter $filter): array {
		$results = array_map(fn(BacktestResultRecord $r) => $r->toArray(), $records);

		$minDuration = $filter->getValue('minDuration');
		if ($minDuration !== null && $minDuration > 0) {
			$results = array_values(array_filter($results, fn($r) => ($r['simDurationDays'] ?? 0) >= $minDuration));
		}

		$groupBy = $filter->getValue('groupBy');
		if (!empty($groupBy)) {
			$results = $this->applyGrouping($results, $groupBy, $filter->getValue('groupShow') ?? 'bestPnl');
		}

		return $results;
	}

	/**
	 * Group results and pick the "best" row per group.
	 */
	private function applyGrouping(array $results, string $groupBy, string $groupShow): array {
		$fieldMap = [
			'ticker' => 'ticker',
			'strategy' => 'strategy',
			'timeframe' => 'timeframe',
		];
		$field = $fieldMap[$groupBy] ?? null;
		if ($field === null) {
			return $results;
		}

		$groups = [];
		foreach ($results as $r) {
			$key = $r[$field] ?? '';
			$groups[$key][] = $r;
		}

		$output = [];
		foreach ($groups as $rows) {
			$best = match ($groupShow) {
				'bestWinRate' => $this->pickBest($rows, 'winRate'),
				'maxTrades' => $this->pickBest($rows, 'tradesFinished'),
				'maxSharpe' => $this->pickBest($rows, 'sharpe'),
				default => $this->pickBest($rows, 'pnl'),
			};
			$output[] = $best;
		}

		return $output;
	}

	private function pickBest(array $rows, string $key): array {
		usort($rows, fn($a, $b) => ($b[$key] ?? 0) <=> ($a[$key] ?? 0));
		return $rows[0];
	}

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

	private function matchesCurrentConfigFromArray(array $row, array $currentConfigs): bool {
		$key = ($row['exchangeName'] ?? '') . '|' . ($row['ticker'] ?? '') . '|' . ($row['marketType'] ?? '') . '|' . ($row['timeframe'] ?? '');
		if (!isset($currentConfigs[$key])) {
			return false;
		}
		$strategyName = $row['strategyName'] ?? $row['strategy'] ?? '';
		$params = [];
		foreach ($row['strategyParams'] ?? [] as $p) {
			if (is_array($p) && isset($p['key'])) {
				$params[$p['key']] = $p['value'] ?? '';
			}
		}
		$backtestConfig = new StrategyConfiguration($strategyName, $params);
		return $currentConfigs[$key]->equals($backtestConfig);
	}
}
