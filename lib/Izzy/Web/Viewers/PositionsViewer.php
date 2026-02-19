<?php

namespace Izzy\Web\Viewers;

use Izzy\AbstractApplications\WebApplication;
use Izzy\Financial\StoredPosition;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Viewer for the Open Positions page.
 * Displays trading positions from the database.
 */
class PositionsViewer extends PageViewer
{
	public function __construct(WebApplication $webApp) {
		parent::__construct($webApp);
	}

	public function render(Response $response, ?Request $request = null): Response {
		$database = $this->webApp->getDatabase();
		$positions = StoredPosition::loadAll($database);

		$body = $this->webApp->getTwig()->render('positions.htt', [
			'menu' => $this->menu,
			'stats' => StoredPosition::getStatistics($database),
			'positions' => $positions,
		]);

		$response->getBody()->write($body);
		return $response;
	}
}
