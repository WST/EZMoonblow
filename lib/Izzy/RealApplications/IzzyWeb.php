<?php

namespace Izzy\RealApplications;

use Izzy\AbstractApplications\WebApplication;
use Izzy\Traits\SingletonTrait;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class IzzyWeb extends WebApplication
{
	public function __construct() {
		parent::__construct();
		$this->slimApp->get('/', function (Request $request, Response $response, $args) {
			$response->getBody()->write("TODO");
			return $response;
		});
	}
	use SingletonTrait;
}
