<?php

namespace Izzy\Web\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Server\MiddlewareInterface;
use Slim\Psr7\Response;

class AuthMiddleware implements MiddlewareInterface {
	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
		if (empty($_SESSION['authorized'])) {
			return (new Response())->withHeader('Location', '/login.jsp')->withStatus(302);
		}

		return $handler->handle($request);
	}
}
