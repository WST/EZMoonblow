<?php

namespace Izzy;

use Izzy\Configuration\Configuration;

class Analyzer extends ConsoleApplication
{
    private Database $database;
	private Configuration $configuration;

	public function __construct() {
		parent::__construct('analyzer');
		
		// Загружаем конфигурацию
		$this->configuration = new Configuration(IZZY_CONFIG . "/config.xml");

		// Устанавливаем соединение с БД
		$this->database = $this->configuration->openDatabase();
		$this->database->connect();
	}

	public function updateBalanceLog(Money $balance): void {
		if(!file_exists(IZZY_RRD)) {
			$minutesInYear = 525960;
			$fiveYears = 5 * $minutesInYear;
			exec("rrdtool create " . IZZY_RRD . " --step 60 DS:balance:GAUGE:120:0:10000000 RRA:MAX:0.5:1:$fiveYears");
		}

		$balanceFloat = $balance->getAmount();
		exec("rrdtool update " . IZZY_RRD . " --template balance N:$balanceFloat");

		echo "Total balance: $balance\n";
	}

	public function run() {
		$iteration = 0;
		while(true) {
			// Обновим информацию о балансе
			$balance = $this->database->getTotalBalance();
			$this->updateBalanceLog($balance);

			// Раз в 10 минут строим графики
			if($iteration % 10 == 0) {
				chdir(IZZY_ROOT);
				exec("./graph.sh");
			}

			$iteration ++;
			sleep(60);
		}
	}
}
