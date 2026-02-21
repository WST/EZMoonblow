<?php

namespace Izzy\Web\Controllers;

use Izzy\AbstractApplications\WebApplication;
use Izzy\Backtest\BacktestResultRecord;
use Izzy\Backtest\OptimizationSuggestionRecord;
use Izzy\Configuration\StrategyConfiguration;
use Izzy\Enums\CandleStorageEnum;
use Izzy\Enums\TaskStatusEnum;
use Izzy\Enums\TaskTypeEnum;
use Izzy\Financial\AbstractDCAStrategy;
use Izzy\Financial\CandleRepository;
use Izzy\RealApplications\Backtester;
use Izzy\Financial\StrategyFactory;
use Izzy\System\QueueTask;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * API controller for the web-based visual backtester.
 * All endpoints are dispatched through a single URL (/cgi-bin/api.pl)
 * with the action determined by the "action" query parameter.
 */
class BacktestApiController
{
	private WebApplication $app;

	public function __construct(WebApplication $app) {
		$this->app = $app;
	}

	/**
	 * Dispatch GET requests based on the "action" query parameter.
	 * Note: the SSE "stream" action is handled by web/backtest-stream.php
	 * which is served directly by Nginx, bypassing Slim entirely.
	 */
	public function handleGet(Request $request, Response $response): Response {
		$action = $request->getQueryParams()['action'] ?? '';

		return match ($action) {
			'get_strategies' => $this->getStrategies($response),
			'get_pairs' => $this->getPairs($response),
			'get_pair_configs' => $this->getPairConfigs($response),
			'get_candle_sets' => $this->getCandleSets($response),
			'get_candle_tasks' => $this->getCandleTasks($response),
			'get_exchanges' => $this->getExchanges($response),
			'backtest_chart' => $this->getBacktestChart($request, $response),
			default => $this->jsonResponse($response, ['error' => 'Unknown action'], 400),
		};
	}

	/**
	 * Dispatch POST requests based on the "action" query parameter.
	 */
	public function handlePost(Request $request, Response $response): Response {
		$action = $request->getQueryParams()['action'] ?? '';

		return match ($action) {
			'run_backtest' => $this->runBacktest($request, $response),
			'request_candles' => $this->requestCandles($request, $response),
			'delete_candle_set' => $this->deleteCandleSet($request, $response),
			'clear_all_candles' => $this->clearAllCandles($response),
			'delete_result' => $this->deleteResult($request, $response),
			'update_suggestion_status' => $this->updateSuggestionStatus($request, $response),
			'abort_backtest' => $this->abortBacktest($request, $response),
			default => $this->jsonResponse($response, ['error' => 'Unknown action'], 400),
		};
	}

	/**
	 * GET /cgi-bin/api.pl?action=get_strategies
	 * Returns a list of available strategies with their parameters and defaults.
	 */
	private function getStrategies(Response $response): Response {
		$strategies = [];
		foreach (StrategyFactory::getAvailableStrategies() as $name) {
			$class = StrategyFactory::getStrategyClass($name);
			if ($class === null) {
				continue;
			}
			$parameters = method_exists($class, 'getParameters')
				? $class::getParameters()
				: [];
			$params = [];
			foreach ($parameters as $parameter) {
				$params[] = $parameter->toArray();
			}
			$isDCA = is_subclass_of($class, AbstractDCAStrategy::class);
			$displayName = method_exists($class, 'getDisplayName')
				? $class::getDisplayName()
				: $name;
			$strategies[] = [
				'name' => $name,
				'displayName' => $displayName,
				'type' => $isDCA ? 'DCA' : 'SE',
				'params' => $params,
			];
		}
		return $this->jsonResponse($response, $strategies);
	}

	/**
	 * GET /cgi-bin/api.pl?action=get_pairs
	 * Returns pairs that have historical candle data in the database.
	 */
	private function getPairs(Response $response): Response {
		$repo = new CandleRepository($this->app->getDatabase());
		$pairs = $repo->getAvailablePairs();
		return $this->jsonResponse($response, $pairs);
	}

	/**
	 * POST /cgi-bin/api.pl?action=run_backtest
	 * Starts a backtest in the background and returns the session ID.
	 *
	 * Expected JSON body:
	 * {
	 *   "pair": "HYPE/USDT",
	 *   "exchangeName": "Bybit",
	 *   "marketType": "futures",
	 *   "timeframe": "1h",
	 *   "strategy": "EZMoonblowSEBoll",
	 *   "params": {...},
	 *   "days": 30,
	 *   "initialBalance": 10000
	 * }
	 */
	private function runBacktest(Request $request, Response $response): Response {
		$body = json_decode((string) $request->getBody(), true);
		if (!is_array($body)) {
			return $this->jsonResponse($response, ['error' => 'Invalid JSON body'], 400);
		}

		$required = ['pair', 'exchangeName', 'marketType', 'timeframe', 'strategy', 'days', 'initialBalance'];
		foreach ($required as $field) {
			if (!isset($body[$field])) {
				return $this->jsonResponse($response, ['error' => "Missing field: $field"], 400);
			}
		}

		if (!StrategyFactory::isAvailable($body['strategy'])) {
			return $this->jsonResponse($response, ['error' => 'Unknown strategy: ' . $body['strategy']], 400);
		}

		$sessionId = bin2hex(random_bytes(12));
		$configFile = Backtester::getConfigFilePath($sessionId);
		file_put_contents($configFile, json_encode($body, JSON_UNESCAPED_UNICODE));

		// Launch the backtest process in the background.
		$script = IZZY_ROOT . '/tasks/backtesting/run-web';
		$cmd = sprintf(
			'php %s --session=%s > /dev/null 2>&1 &',
			escapeshellarg($script),
			escapeshellarg($sessionId),
		);
		exec($cmd);

		return $this->jsonResponse($response, ['sessionId' => $sessionId]);
	}

	/**
	 * GET /cgi-bin/api.pl?action=get_pair_configs
	 * Returns strategy configurations for all pairs from config.xml.
	 * Only safe data is exposed (strategy name + params); no exchange credentials.
	 */
	private function getPairConfigs(Response $response): Response {
		$config = $this->app->getConfiguration();
		$result = [];
		foreach ($config->getExchangeNames() as $exchangeName) {
			$exchConfig = $config->getExchangeConfiguration($exchangeName);
			if (!$exchConfig) {
				continue;
			}
			foreach ($exchConfig->getAllPairConfigs() as $pc) {
				$sc = new StrategyConfiguration($pc['strategyName'], $pc['params']);
				$result[] = [
					'exchangeName' => $pc['exchangeName'],
					'ticker' => $pc['ticker'],
					'marketType' => $pc['marketType'],
					'timeframe' => $pc['timeframe'],
					'strategy' => $pc['strategyName'],
					'params' => $sc->toFullParams(),
					'backtestDays' => $pc['backtestDays'],
					'backtestInitialBalance' => $pc['backtestInitialBalance'],
					'backtestTicksPerCandle' => $pc['backtestTicksPerCandle'],
				];
			}
		}
		return $this->jsonResponse($response, $result);
	}

	/**
	 * GET /cgi-bin/api.pl?action=get_candle_sets
	 * Returns candle sets grouped by (exchange, ticker, marketType, timeframe) with time ranges and counts.
	 */
	private function getCandleSets(Response $response): Response {
		$repo = new CandleRepository($this->app->getDatabase());
		return $this->jsonResponse($response, $repo->getGroupedSets());
	}

	/**
	 * GET /cgi-bin/api.pl?action=get_candle_tasks
	 * Returns pending and in-progress LOAD_CANDLES tasks targeting the backtest storage.
	 */
	private function getCandleTasks(Response $response): Response {
		$db = $this->app->getDatabase();
		$rows = $db->selectAllRows(
			QueueTask::getTableName(),
			'*',
			[QueueTask::FType => TaskTypeEnum::LOAD_CANDLES->value],
		);

		$tasks = [];
		foreach ($rows as $row) {
			$status = $row[QueueTask::FStatus];
			if ($status !== TaskStatusEnum::PENDING->value && $status !== TaskStatusEnum::INPROGRESS->value) {
				continue;
			}
			$attrs = json_decode($row[QueueTask::FAttributes], true);
			if (($attrs['storage'] ?? '') !== CandleStorageEnum::BACKTEST->value) {
				continue;
			}
			$tasks[] = [
				'exchange' => $attrs['exchange'] ?? '',
				'ticker' => $attrs['pair'] ?? '',
				'marketType' => $attrs['marketType'] ?? '',
				'timeframe' => $attrs['timeframe'] ?? '',
				'startTime' => $attrs['startTime'] ?? 0,
				'endTime' => $attrs['endTime'] ?? 0,
				'status' => $status,
				'createdAt' => (int)$row[QueueTask::FCreatedAt],
			];
		}
		return $this->jsonResponse($response, $tasks);
	}

	/**
	 * GET /cgi-bin/api.pl?action=get_exchanges
	 * Returns names of all configured exchanges.
	 */
	private function getExchanges(Response $response): Response {
		$names = $this->app->getConfiguration()->getExchangeNames();
		return $this->jsonResponse($response, $names);
	}

	/**
	 * POST /cgi-bin/api.pl?action=request_candles
	 * Creates a candle loading task for the Analyzer.
	 *
	 * Expected JSON body:
	 * {
	 *   "exchange": "Bybit",
	 *   "ticker": "BTC/USDT",
	 *   "marketType": "futures",
	 *   "timeframe": "1h",
	 *   "days": 365
	 * }
	 */
	private function requestCandles(Request $request, Response $response): Response {
		$body = json_decode((string) $request->getBody(), true);
		if (!is_array($body)) {
			return $this->jsonResponse($response, ['error' => 'Invalid JSON body'], 400);
		}

		$required = ['exchange', 'ticker', 'marketType', 'timeframe', 'days'];
		foreach ($required as $field) {
			if (!isset($body[$field])) {
				return $this->jsonResponse($response, ['error' => "Missing field: $field"], 400);
			}
		}

		$days = (int)$body['days'];
		if ($days < 1) {
			return $this->jsonResponse($response, ['error' => 'days must be >= 1'], 400);
		}

		$endTime = time();
		$startTime = $endTime - $days * 86400;

		QueueTask::loadCandles(
			$this->app->getDatabase(),
			$body['exchange'],
			$body['ticker'],
			$body['marketType'],
			$body['timeframe'],
			$startTime,
			$endTime,
			CandleStorageEnum::BACKTEST,
		);

		return $this->jsonResponse($response, ['ok' => true]);
	}

	/**
	 * POST /cgi-bin/api.pl?action=delete_candle_set
	 * Deletes candles matching a specific series key.
	 *
	 * Expected JSON body:
	 * {
	 *   "exchange": "Bybit",
	 *   "ticker": "BTC/USDT",
	 *   "marketType": "futures",
	 *   "timeframe": "1h"
	 * }
	 */
	private function deleteCandleSet(Request $request, Response $response): Response {
		$body = json_decode((string) $request->getBody(), true);
		if (!is_array($body)) {
			return $this->jsonResponse($response, ['error' => 'Invalid JSON body'], 400);
		}

		$required = ['exchange', 'ticker', 'marketType', 'timeframe'];
		foreach ($required as $field) {
			if (!isset($body[$field])) {
				return $this->jsonResponse($response, ['error' => "Missing field: $field"], 400);
			}
		}

		$repo = new CandleRepository($this->app->getDatabase());
		$repo->deleteBySeriesKey($body['exchange'], $body['ticker'], $body['marketType'], $body['timeframe']);

		return $this->jsonResponse($response, ['ok' => true]);
	}

	/**
	 * POST /cgi-bin/api.pl?action=clear_all_candles
	 * Deletes all candles from the backtest candle storage.
	 */
	private function clearAllCandles(Response $response): Response {
		$repo = new CandleRepository($this->app->getDatabase());
		$repo->truncateAll();
		return $this->jsonResponse($response, ['ok' => true]);
	}

	/**
	 * GET /cgi-bin/api.pl?action=backtest_chart&id=123
	 * Returns the balance chart PNG for a specific backtest result.
	 */
	private function getBacktestChart(Request $request, Response $response): Response {
		$id = (int) ($request->getQueryParams()['id'] ?? 0);
		if ($id <= 0) {
			return $response->withStatus(400);
		}

		$record = BacktestResultRecord::loadById($this->app->getDatabase(), $id);
		if ($record === null) {
			return $response->withStatus(404);
		}

		$chartPng = $record->getBalanceChart();
		if ($chartPng === null || $chartPng === '') {
			return $response->withStatus(404);
		}

		$response->getBody()->write($chartPng);
		return $response
			->withHeader('Content-Type', 'image/png')
			->withHeader('Cache-Control', 'public, max-age=86400');
	}

	/**
	 * POST /cgi-bin/api.pl?action=delete_result
	 * Deletes a single backtest result by ID.
	 *
	 * Expected JSON body: { "id": 123 }
	 */
	private function deleteResult(Request $request, Response $response): Response {
		$body = json_decode((string) $request->getBody(), true);
		$id = (int)($body['id'] ?? 0);
		if ($id <= 0) {
			return $this->jsonResponse($response, ['error' => 'Missing or invalid id'], 400);
		}

		$record = BacktestResultRecord::loadById($this->app->getDatabase(), $id);
		if ($record === null) {
			return $this->jsonResponse($response, ['error' => 'Record not found'], 404);
		}

		$record->remove();
		return $this->jsonResponse($response, ['ok' => true]);
	}

	/**
	 * POST /cgi-bin/api.pl?action=update_suggestion_status
	 * Update the status of an optimization suggestion (Applied / Dismissed).
	 */
	private function updateSuggestionStatus(Request $request, Response $response): Response {
		$body = json_decode((string) $request->getBody(), true);
		$id = (int)($body['id'] ?? 0);
		$status = $body['status'] ?? '';

		if ($id <= 0) {
			return $this->jsonResponse($response, ['error' => 'Missing or invalid id'], 400);
		}
		$allowed = [
			OptimizationSuggestionRecord::STATUS_APPLIED,
			OptimizationSuggestionRecord::STATUS_DISMISSED,
		];
		if (!in_array($status, $allowed, true)) {
			return $this->jsonResponse($response, ['error' => 'Invalid status'], 400);
		}

		$record = OptimizationSuggestionRecord::loadById($this->app->getDatabase(), $id);
		if ($record === null) {
			return $this->jsonResponse($response, ['error' => 'Suggestion not found'], 404);
		}

		$record->setStatus($status);
		$record->save();
		return $this->jsonResponse($response, ['ok' => true]);
	}

	/**
	 * POST /cgi-bin/api.pl?action=abort_backtest
	 * Signals a running web backtest to stop by creating a stop file.
	 *
	 * Expected JSON body: { "sessionId": "abc123..." }
	 */
	private function abortBacktest(Request $request, Response $response): Response {
		$body = json_decode((string) $request->getBody(), true);
		$sessionId = $body['sessionId'] ?? '';
		if (!preg_match('/^[a-zA-Z0-9\-]+$/', $sessionId)) {
			return $this->jsonResponse($response, ['error' => 'Invalid session ID'], 400);
		}
		$stopFile = Backtester::getStopFilePath($sessionId);
		file_put_contents($stopFile, '1');
		return $this->jsonResponse($response, ['ok' => true]);
	}

	/**
	 * Write a JSON response.
	 */
	private function jsonResponse(Response $response, mixed $data, int $status = 200): Response {
		$response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
		return $response
			->withHeader('Content-Type', 'application/json')
			->withStatus($status);
	}
}
