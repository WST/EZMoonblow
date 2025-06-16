<?php

namespace Izzy\Exchanges;

use Izzy\Chart\Chart;
use Izzy\Configuration\ExchangeConfiguration;
use Izzy\Interfaces\IExchangeDriver;
use Izzy\Market;
use Psr\Log\LoggerInterface;

/**
 * Абстрактный класс криптобиржи.
 * Содержит общую для всех криптобирж логику.
 */
abstract class AbstractExchangeDriver implements IExchangeDriver
{
	protected ExchangeConfiguration $config;
	
	protected array $spotPairs = [];
	protected array $futuresPairs = [];
	
	protected LoggerInterface $logger;

	public function __construct(ExchangeConfiguration $config, LoggerInterface $logger) {
		$this->config = $config;
		$this->logger = $logger;
	}

	protected function log(string $message, string $level = 'info'): void {
		$this->logger->$level($message);
	}

	public function getName(): string {
		return $this->config->getName();
	}

	/**
	 * Обновить информацию с биржи / на бирже
	 * @return int на сколько секунд заснуть после обновления
	 */
	public function update(): int
	{
		$this->log("Обновляем баланс на {$this->getName()}");
		$this->updateBalance();

		if ($this->shouldUpdateOrders()) {
			$this->log("Обновляем лимитные спотовые ордеры на {$this->getName()}");
			$this->updateSpotLimitOrders();
		}

		// Обновляем графики для всех пар
		foreach ($this->config->getAllPairs() as $pair) {
			$this->updateChart($pair);
		}
		// По умолчанию просим запустить себя через 60 секунд
		return 60;
	}

	protected function updateChart(Pair $pair): void
	{
		$this->log("updateChart called for {$pair->getTicker()}");
		$now = time();

		$this->log("Checking chart update condition for {$pair->getTicker()}: lastChartDrawTime = " . ($this->lastChartDrawTime ?? 'null') . ", now = {$now}");
		if ($this->lastChartDrawTime === null || ($now - $this->lastChartDrawTime) >= 300) { // Возвращаем 300 секунд
			$this->lastChartDrawTime = $now;
			$this->log("Chart update condition met for {$pair->getTicker()}. Drawing chart.");

			$market = $this->getMarket($pair);
			$this->log("getMarketCandles returned for {$pair->getTicker()}: " . ($market === null ? 'null' : 'Market object'));
			if ($market === null) {
				$this->log("Market is null for {$pair->getTicker()}, cannot draw chart.");
				return;
			}

			$chart = new Chart($market, $pair->getTimeframe());
			$chartId = str_replace('/', '_', $pair->getTicker());
			$chartFileName = "charts/{$this->getName()}_{$chartId}.png";
			$chart->draw();
			$chart->save($chartFileName);
		}
	}

	public function connect(): bool {
		// Implementation of connect method
		return true; // Placeholder return, actual implementation needed
	}

	protected function getMarket(Pair $pair): ?Market {
		// Implementation of getMarketCandles method
		return null; // Placeholder return, actual implementation needed
	}

	protected function updateBalance(): void {
		// Implementation of updateBalance method
	}

	protected function getBalance(): float {
		// Implementation of getBalance method
		return 0.0; // Placeholder return, actual implementation needed
	}

	protected function shouldUpdateOrders(): bool {
		// Implementation of shouldUpdateOrders method
		return false; // Placeholder return, actual implementation needed
	}

	protected function updateSpotLimitOrders(): void {
		// Implementation of updateSpotLimitOrders method
	}
}
