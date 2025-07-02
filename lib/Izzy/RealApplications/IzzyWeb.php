<?php

namespace Izzy\RealApplications;

use Izzy\AbstractApplications\WebApplication;
use Izzy\Traits\SingletonTrait;
use Izzy\Web\Middleware\AuthMiddleware;
use Izzy\Web\Viewers\AuthPage;
use Izzy\Web\Viewers\PageViewer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class IzzyWeb extends WebApplication
{
	use SingletonTrait;
	
	public function __construct() {
		parent::__construct();
		$this->slimApp->get('/', [$this, 'indexPage'])->add(AuthMiddleware::class);
		$this->slimApp->get('/login.jsp', [$this, 'authPage']);
		$this->slimApp->post('/login.jsp', [$this, 'authHandler']);
		$this->slimApp->get('/logout.jsp', [$this, 'logoutPage']);
	}

	public function indexPage(Request $request, Response $response): Response {
		$pageViewer = new PageViewer($this);
		return $pageViewer->render($response);
	}

	public function authPage(Request $request, Response $response): Response {
		$pageViewer = new AuthPage($this);
		return $pageViewer->render($response);
	}

	public function logoutPage(Request $request, Response $response): Response {
		$_SESSION = [];
		session_destroy();
		return $response->withHeader('Location', '/login.jsp')->withStatus(302);
	}

	public function authHandler(Request $request, Response $response): Response {
		$parsed = $request->getParsedBody();
		$password = $parsed['password'] ?? '';

		if ($password === '123456') {
			$_SESSION['authorized'] = true;
			return $response->withHeader('Location', '/')->withStatus(302);
		}

		return $response->withHeader('Location', '/login.jsp')->withStatus(302);
	}
}
