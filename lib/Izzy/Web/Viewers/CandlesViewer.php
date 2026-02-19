<?php

namespace Izzy\Web\Viewers;

use Izzy\AbstractApplications\WebApplication;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Renders the candle management page for backtest data.
 */
class CandlesViewer extends PageViewer
{
	public function __construct(WebApplication $webApp) {
		parent::__construct($webApp);
	}

	public function render(Response $response, ?Request $request = null): Response {
		$body = $this->webApp->getTwig()->render('candles.htt', [
			'menu' => $this->menu,
		]);
		$response->getBody()->write($body);
		return $response;
	}
}
