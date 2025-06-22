<?php

namespace Izzy\Exchanges;

use Izzy\Chart\Chart;
use Izzy\Configuration\ExchangeConfiguration;
use Izzy\ConsoleApplication;
use Izzy\Database;
use Izzy\Enums\TimeFrameEnum;
use Izzy\Interfaces\IExchangeDriver;
use Izzy\Logger;
use Izzy\Market;
use Izzy\Pair;

/**
 * Абстрактный класс криптобиржи.
 * Содержит общую для всех криптобирж логику.
 */
abstract class AbstractExchangeDriver implements IExchangeDriver
{
	protected ExchangeConfiguration $config;
	
	protected array $spotPairs = [];
	protected array $futuresPairs = [];
	
	protected Database $database;
	
	protected Logger $logger;

	public function __construct(ExchangeConfiguration $config, ConsoleApplication $application) {
		$this->config = $config;
		$this->logger = Logger::getLogger();
		$this->database = $application->getDatabase();
	}

	public function getName(): string {
		return $this->config->getName();
	}

	/**
	 * Обновить информацию с биржи / на бирже
	 * @return int на сколько секунд заснуть после обновления
	 */
	public function update(): int {
		$this->logger->info("Обновляем баланс на {$this->getName()}");
		$this->updateBalance();
		
		// Update markets.
		$this->updateMarkets();

		if ($this->shouldUpdateOrders()) {
			$this->logger->info("Обновляем лимитные спотовые ордеры на {$this->getName()}");
			$this->updateSpotLimitOrders();
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
		$this->logger->info("Драйвер для биржи {$this->exchangeName} загружен");

		// Основной цикл
		while(true) {
			$timeout = $this->update();
			sleep($timeout);
		}
	}
}
