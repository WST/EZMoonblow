<?php

namespace Izzy\Web\Viewers;

use Izzy\AbstractApplications\WebApplication;
use Psr\Http\Message\ResponseInterface as Response;

class DashboardViewer extends PageViewer {
	private array $timeRanges = [
		'day' => 'Day',
		'month' => 'Month',
		'year' => 'Year'
	];

	public function __construct(WebApplication $webApp) {
		parent::__construct($webApp);
	}

	public function render(Response $response): Response {
		$selectedRange = $_GET['range'] ?? 'day';

		// Validate selected range
		if (!array_key_exists($selectedRange, $this->timeRanges)) {
			$selectedRange = 'day';
		}

		$body = $this->webApp->getTwig()->render('dashboard.htt', [
			'menu' => $this->menu,
			'timeRanges' => $this->timeRanges,
			'selectedRange' => $selectedRange
		]);
		$response->getBody()->write($body);
		return $response;
	}
}
