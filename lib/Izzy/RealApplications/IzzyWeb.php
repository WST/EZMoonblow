<?php

namespace Izzy\RealApplications;

use Izzy\AbstractApplications\WebApplication;
use Izzy\Traits\SingletonTrait;
use Izzy\Web\Middleware\AuthMiddleware;
use Izzy\Web\Viewers\AuthPage;
use Izzy\Web\Viewers\PageViewer;
use Izzy\Web\Viewers\TradedPairsViewer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class IzzyWeb extends WebApplication
{
	use SingletonTrait;
	
	public function __construct() {
		parent::__construct();
		$this->slimApp->get('/', [$this, 'indexPage'])->add(AuthMiddleware::class);
		$this->slimApp->get('/pairs.jsp', [$this, 'pairsPage'])->add(AuthMiddleware::class);
		$this->slimApp->get('/chart/{exchange}/{ticker:.+}/{timeframe}', [$this, 'generateChart'])->add(AuthMiddleware::class);
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

	public function pairsPage(Request $request, Response $response): Response {
		$pageViewer = new TradedPairsViewer($this);
		return $pageViewer->render($response);
	}

	public function generateChart(Request $request, Response $response, array $args): Response {
		$exchange = $args['exchange'];
		$ticker = $args['ticker'];
		$timeframe = $args['timeframe'];
		
		try {
			// Создать Market и нарисовать график
			$market = $this->createMarket($exchange, $ticker, $timeframe);
			$chartFilename = $market->drawChart();
			
			// Проверяем, что файл существует
			if (!file_exists($chartFilename)) {
				throw new \Exception("Chart file not found: $chartFilename");
			}
			
			// Читаем содержимое файла
			$imageData = file_get_contents($chartFilename);
			if ($imageData === false) {
				throw new \Exception("Failed to read chart file: $chartFilename");
			}
			
			$response->getBody()->write($imageData);
			return $response->withHeader('Content-Type', 'image/png');
		} catch (\Exception $e) {
			// В случае ошибки возвращаем пустое изображение или заглушку
			$response->getBody()->write('');
			return $response->withHeader('Content-Type', 'image/png');
		}
	}
	
	private function createMarket(string $exchangeName, string $ticker, string $timeframe): \Izzy\Interfaces\IMarket {
		$config = \Izzy\Configuration\Configuration::getInstance();
		$exchange = $config->connectExchange($this, $exchangeName);
		
		if (!$exchange) {
			throw new \Exception("Exchange $exchangeName not found or disabled");
		}
		
		// Определяем тип рынка (spot или futures) из конфигурации
		$exchangeConfig = $this->getExchangeConfig($exchangeName);
		if (!$exchangeConfig) {
			throw new \Exception("Exchange configuration not found");
		}
		
		$timeframeEnum = \Izzy\Enums\TimeFrameEnum::from($timeframe);
		
		// Ищем пару в spot и futures
		$spotPairs = $exchangeConfig->getSpotPairs($exchange);
		$futuresPairs = $exchangeConfig->getFuturesPairs($exchange);
		
		$pair = null;
		foreach ($spotPairs as $spotPair) {
			if ($spotPair->getTicker() === $ticker && $spotPair->getTimeframe()->value === $timeframe) {
				$pair = $spotPair;
				break;
			}
		}
		
		if (!$pair) {
			foreach ($futuresPairs as $futuresPair) {
				if ($futuresPair->getTicker() === $ticker && $futuresPair->getTimeframe()->value === $timeframe) {
					$pair = $futuresPair;
					break;
				}
			}
		}
		
		if (!$pair) {
			throw new \Exception("Pair $ticker with timeframe $timeframe not found");
		}
		
		// Создаем рынок используя createMarket
		$market = $exchange->createMarket($pair);
		
		if (!$market) {
			throw new \Exception("Failed to create market for $ticker");
		}
		
		return $market;
	}
	
	private function getExchangeConfig(string $exchangeName): ?\Izzy\Configuration\ExchangeConfiguration {
		$document = new \DOMDocument();
		$document->load(IZZY_CONFIG . "/config.xml");
		$xpath = new \DOMXPath($document);
		
		$exchangeElement = $xpath->query("//exchanges/exchange[@name='$exchangeName']")->item(0);
		if ($exchangeElement) {
			return new \Izzy\Configuration\ExchangeConfiguration($exchangeElement);
		}
		
		return null;
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
