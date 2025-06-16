<?php

namespace Izzy;

use Izzy\Configuration\Configuration;
use Izzy\Interfaces\IExchangeDriver;

class Trader extends ConsoleApplication
{
	private $exchanges;

	private Database $database;
	private Configuration $configuration;

	/**
	 * Конструирует объект трейдера
	 */
	public function __construct() {
		// Конструируем родителя
		parent::__construct('trader');
		
		// Загружаем конфигурацию
		$this->configuration = new Configuration(IZZY_CONFIG . "/config.xml");
		
		// Устанавливаем соединение с БД
		$this->database = $this->configuration->openDatabase();
		$this->database->connect();
		
		// Получаем список бирж
		$this->exchanges = $this->configuration->connectExchanges();
	}

	public function run() {
		// Отключимся от базы данных перед разделением
		$this->database->close();
		unset($this->database);

		// Запускаем обновляторы бирж
		$status = $this->runExchangeUpdaters();
		die($status);
	}

	private function runExchangeUpdaters(): int {
		$updaters = [];

		/** @var IExchangeDriver $exchange */
		foreach($this->exchanges as $exchangeName => $exchange) {
			$updaters[$exchangeName] = $exchange->run();
		}

		foreach ($updaters as $updater) {
			$status = NULL;
			pcntl_waitpid($updater, $status);
		}

		return 0;
	}

	public static function getInstance(): Trader {
		static $instance = null;
		if(is_null($instance)) {
			$instance = new self;
		}
		return $instance;
	}
}
