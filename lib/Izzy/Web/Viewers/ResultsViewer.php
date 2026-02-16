<?php

namespace Izzy\Web\Viewers;

use Izzy\AbstractApplications\WebApplication;
use Izzy\Backtest\BacktestResultRecord;
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

		$results = array_map(fn(BacktestResultRecord $r) => $r->toArray(), $records);

		$body = $this->webApp->getTwig()->render('results.htt', [
			'menu' => $this->menu,
			'results' => $results,
		]);
		$response->getBody()->write($body);
		return $response;
	}
}
