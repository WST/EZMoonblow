<?php

namespace Izzy\Exchanges;

use Izzy\AbstractApplications\ConsoleApplication;
use Izzy\Configuration\ExchangeConfiguration;
use Izzy\Financial\Market;
use Izzy\Financial\Money;
use Izzy\Financial\Pair;
use Izzy\Interfaces\IExchangeDriver;
use Izzy\Interfaces\IMarket;
use Izzy\Interfaces\IPair;
use Izzy\System\Database;
use Izzy\System\Logger;

/**
 * Абстрактный класс криптобиржи.
 * Содержит общую для всех криптобирж логику.
 */
abstract class AbstractExchangeDriver implements IExchangeDriver
{
	protected ExchangeConfiguration $config;

	/**
	 * Spot pairs to trade.
	 * @var IPair[]
	 */
	protected array $spotPairs = [];

	/**
	 * Futures pairs to trade.
	 * @var IPair[]
	 */
	protected array $futuresPairs = [];

	/**
	 * Markets relative to the traded pairs.
	 * @var IMarket[]
	 */
	protected array $markets = [];
	
	protected Database $database;
	
	protected Logger $logger;

	public function __construct(ExchangeConfiguration $config, ConsoleApplication $application) {
		$this->config = $config;
		$this->logger = Logger::getLogger();
		$this->database = $application->database;
	}

	public function getName(): string {
		return $this->config->getName();
	}
	
	protected function updatePairs(): void {
		$this->spotPairs = $this->config->getSpotPairs();
		$this->futuresPairs = $this->config->getFuturesPairs();
		
		// First, create the absent markets.
		foreach ($this->spotPairs + $this->futuresPairs as $pair) {
			$pairDescription = $pair->getDescription();
			if (!isset($this->markets[$pair->ticker])) {
				$market = $this->getMarket($pair);
				if ($market) {
					$this->markets[$pair->ticker] = $market;
					$this->logger->info("Created market for pair $pairDescription");
				} else {
					$this->logger->error("Failed to create market for pair $pairDescription");
				}
			}
		}
		
		// Now, remove the markets that are no longer needed.
		foreach ($this->markets as $ticker => $market) {
			if (!isset($this->spotPairs[$ticker]) && !isset($this->futuresPairs[$ticker])) {
				unset($this->markets[$ticker]);
				$this->logger->info("Removed market for pair $ticker");
			}
		}
	}

	/**
	 * Обновить информацию с биржи / на бирже
	 * @return int на сколько секунд заснуть после обновления
	 */
	public function update(): int {
		$this->logger->info("Updating total balance information for {$this->getName()}");
		$this->updateBalance();
		
		// Updating the lists of pairs.
		$this->logger->info("Updating the list of pairs for {$this->getName()}");
		$this->updatePairs();
		
		// Update markets.
		$this->logger->info("Updating market data for {$this->getName()}");
		$this->updateMarkets();
		
		// Update charts for all markets.
		$this->logger->info("Updating charts for all markets on {$this->getName()}");
		$this->updateCharts();

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
	
	protected function setBalance(Money $balance): bool {
		return $this->database->setExchangeBalance($this->exchangeName, $balance);
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
	
	public function updateCharts(): void {
		foreach ($this->markets as $ticker => $market) {
			$market->updateChart();
		}
	}
}
