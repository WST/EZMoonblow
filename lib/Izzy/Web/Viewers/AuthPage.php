<?php

namespace Izzy\Web\Viewers;

use Izzy\AbstractApplications\WebApplication;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AuthPage
{
	protected WebApplication $webApp;

	public function __construct(WebApplication $webApp) {
		$this->webApp = $webApp;
	}

	public function render(Response $response, ?Request $request = null): Response {
		$body = $this->webApp->getTwig()->render('auth-page.htt', []);
		$response->getBody()->write($body);
		return $response;
	}
}
