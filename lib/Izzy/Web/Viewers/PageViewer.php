<?php

namespace Izzy\Web\Viewers;

use Izzy\AbstractApplications\WebApplication;
use Psr\Http\Message\ResponseInterface as Response;

class PageViewer {
	protected WebApplication $webApp;

	protected array $menu = [
		['title' => 'Balance', 'url' => '/'],
		['title' => 'Traded Pairs', 'url' => '/pairs.jsp'],
		['title' => 'Watched Pairs', 'url' => '/watched.jsp'],
		['title' => 'Open Positions', 'url' => '/positions.jsp'],
		['title' => 'System Status', 'url' => '/status.jsp'],
		['title' => 'Log Out', 'url' => '/logout.jsp'],
	];

	public function __construct(WebApplication $webApp) {
		$this->webApp = $webApp;
	}

	public function render(Response $response): Response {
		$body = $this->webApp->getTwig()->render('page.htt', ['menu' => $this->menu]);
		$response->getBody()->write($body);
		return $response;
	}
}
