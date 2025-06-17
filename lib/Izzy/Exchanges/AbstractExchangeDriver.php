<?php

namespace Izzy\Exchanges;

use Izzy\Chart\Chart;
use Izzy\Configuration\ExchangeConfiguration;
use Izzy\Database;
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
	private Database $database;

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

	/**
	 * Fork into a child process;
	 * @return int
	 */
	public function run(): int {
		$pid = pcntl_fork();
		if($pid) {
			return $pid;
		}

		if(!$this->connect()) {
			return -1;
		}

		// Подключаемся к БД в новом процессе
		$this->database->connect();

		// Сообщим, что драйвер успешно загружен
		$this->log("Драйвер для биржи {$this->exchangeName} загружен");

		// Основной цикл
		while(true) {
			$timeout = $this->update();
			sleep($timeout);
		}
	}
}
