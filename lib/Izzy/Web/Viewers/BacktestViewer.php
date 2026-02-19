<?php

namespace Izzy\Web\Viewers;

use Izzy\AbstractApplications\WebApplication;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Renders the visual backtesting page with configuration form and chart area.
 */
class BacktestViewer extends PageViewer
{
	public function __construct(WebApplication $webApp) {
		parent::__construct($webApp);
	}

	public function render(Response $response, ?Request $request = null): Response {
		$body = $this->webApp->getTwig()->render('backtest.htt', [
			'menu' => $this->menu,
		]);
		$response->getBody()->write($body);
		return $response;
	}
}
