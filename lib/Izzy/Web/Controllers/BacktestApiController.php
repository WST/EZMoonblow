<?php

namespace Izzy\Web\Controllers;

use Izzy\AbstractApplications\WebApplication;
use Izzy\Financial\AbstractDCAStrategy;
use Izzy\Financial\CandleRepository;
use Izzy\RealApplications\Backtester;
use Izzy\Financial\StrategyFactory;
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
			$strategies[] = [
				'name' => $name,
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
	 *   "initialBalance": 10000,
	 *   "leverage": 5
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
	 * Write a JSON response.
	 */
	private function jsonResponse(Response $response, mixed $data, int $status = 200): Response {
		$response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
		return $response
			->withHeader('Content-Type', 'application/json')
			->withStatus($status);
	}
}
