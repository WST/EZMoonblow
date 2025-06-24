<?php

namespace Izzy\RealApplications;

use Izzy\AbstractApplications\ConsoleApplication;
use Izzy\Configuration\Configuration;
use Izzy\Financial\Money;
use Izzy\System\Database;

class Analyzer extends ConsoleApplication
{
	public function __construct() {
		parent::__construct('analyzer');
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
