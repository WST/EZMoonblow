<?php

namespace Izzy\RealApplications;

use Izzy\AbstractApplications\ConsoleApplication;
use Izzy\Enums\MarketTypeEnum;
use Izzy\Enums\TimeFrameEnum;
use Izzy\Financial\Money;
use Izzy\Financial\Pair;
use Izzy\System\QueueTask;

/**
 * Analyzer application for monitoring and analyzing exchange balances.
 * Provides balance tracking, logging, and chart generation capabilities.
 */
class Analyzer extends ConsoleApplication
{
	/** @var string RRD database file path for balance tracking. */
	private string $balanceRrdFile;

	/**
	 * Constructor for the Analyzer application.
	 */
	public function __construct() {
		parent::__construct();
		$this->balanceRrdFile = IZZY_RRD.'/balance.rrd';

		// Ensure charts directory exists.
		if (!is_dir(IZZY_CHARTS)) {
			mkdir(IZZY_CHARTS, 0755, true);
		}
	}

	/**
	 * Create RRD database for balance tracking if it doesn't exist.
	 * Sets up a Round Robin Database with appropriate data sources and archives.
	 */
	private function createBalanceRrdDatabase(): void {
		$filenameEscaped = escapeshellarg($this->balanceRrdFile);

		if (!file_exists($this->balanceRrdFile)) {
			$minutesInYear = 525960;
			$fiveYears = 5 * $minutesInYear;

			$command = "rrdtool create $filenameEscaped --step 60 ".
				"DS:balance:GAUGE:120:0:10000000 ".
				"RRA:MAX:0.5:1:$fiveYears";

			exec($command);
			$this->logger->info("Created RRD database: $this->balanceRrdFile");
		}
	}

	/**
	 * Update balance log in RRD database.
	 *
	 * @param Money $balance Current total balance to log.
	 */
	public function updateBalanceLog(Money $balance): void {
		// Ensure RRD database exists.
		$this->createBalanceRrdDatabase();

		$filenameEscaped = escapeshellarg($this->balanceRrdFile);
		$balanceFloat = $balance->getAmount();

		$command = "rrdtool update $filenameEscaped --template balance N:$balanceFloat";
		exec($command);

		$this->logger->info("Total balance: $balance");
	}

	/**
	 * Generate balance chart for the specified time period.
	 *
	 * @param string $period Time period ('day', 'month', 'year').
	 * @param string $title Chart title.
	 * @param string $startTime RRD start time parameter.
	 * @param string $endTime RRD end time parameter (default: 'now').
	 */
	private function generateBalanceChart(string $period, string $title, string $startTime, string $endTime = 'now'): void {
		$filenameEscaped = escapeshellarg($this->balanceRrdFile);
		$outputFile = IZZY_CHARTS."/balance_{$period}.png";
		$outputFileEscaped = escapeshellarg($outputFile);

		$command = "rrdtool graph $outputFileEscaped ".
			"--start $startTime ".
			"--end $endTime ".
			"--title '$title' ".
			"--vertical-label 'Balance (USDT)' ".
			"--width 640 ".
			"--height 320 ".
			"--color CANVAS#FFFFFF ".
			"--color BACK#FFFFFF ".
			"--color SHADEA#FFFFFF ".
			"--color SHADEB#FFFFFF ".
			"--color GRID#CCCCCC ".
			"--color MGRID#999999 ".
			"--color FONT#000000 ".
			"--color AXIS#000000 ".
			"--color ARROW#000000 ".
			"--color FRAME#000000 ".
			"DEF:balance=$filenameEscaped:balance:MAX ".
			"AREA:balance#0066CC:'Balance' ".
			"GPRINT:balance:LAST:'Current\\: %8.2lf USDT' ".
			"GPRINT:balance:AVERAGE:'Average\\: %8.2lf USDT' ".
			"GPRINT:balance:MAX:'Maximum\\: %8.2lf USDT' ".
			"GPRINT:balance:MIN:'Minimum\\: %8.2lf USDT'";

		exec($command);
		$this->logger->info("Generated $period chart: $outputFile");
	}

	/**
	 * Generate daily balance chart (last 24 hours).
	 */
	public function generateDailyChart(): void {
		$this->generateBalanceChart(
			'day',
			'Balance — Last 24 Hours',
			'-1d'
		);
	}

	/**
	 * Generate monthly balance chart (last 30 days).
	 */
	public function generateMonthlyChart(): void {
		$this->generateBalanceChart(
			'month',
			'Balance — Last 30 Days',
			'-30d'
		);
	}

	/**
	 * Generate yearly balance chart (last 12 months).
	 */
	public function generateYearlyChart(): void {
		$this->generateBalanceChart(
			'year',
			'Balance — Last 12 Months',
			'-1y'
		);
	}

	/**
	 * Generate all balance charts (daily, monthly, yearly).
	 */
	public function generateBalanceCharts(): void {
		$this->logger->info("Generating balance charts...");
		$this->generateDailyChart();
		$this->generateMonthlyChart();
		$this->generateYearlyChart();
		$this->logger->info("All charts generated successfully.");
	}

	/**
	 * Main application loop.
	 * Continuously monitors balance and generates charts periodically.
	 */
	public function run(): void {
		$iteration = 0;

		$this->logger->info("Starting Analyzer application...");
		$this->logger->info("Balance RRD file: $this->balanceRrdFile");
		$this->logger->info("Charts directory: ".IZZY_CHARTS);

		// Start heartbeat monitoring.
		$this->startHeartbeat();

		while (true) {
			// Update heartbeat.
			$this->beat();

			// Update balance information.
			$balance = $this->database->getTotalBalance();
			$this->updateBalanceLog($balance);

			// Process scheduled tasks.
			$this->processTasks();

			// Generate balance charts every 10 minutes.
			if ($iteration % 10 == 0) {
				$this->cleanup();
				$this->generateBalanceCharts();
			}

			$iteration++;
			$this->interruptibleSleep(60);
		}
	}

	private function cleanup(): void {
		$files = glob(IZZY_CHARTS."/*.png");
		foreach ($files as $file) {
			$mtime = filemtime($file);
			if ($mtime < time() - 3600) {
				unlink($file);
			}
		}
	}

	protected function processTask(QueueTask $task): void {
		$taskType = $task->getType();
		$taskStatus = $task->getStatus();

		// Task is to draw a candlestick chart.
		if ($taskType->isDrawCandlestickChart()) {
			$this->handleDrawCandlestickChartTask($task->getAttributes());
			$task->remove();
			return;
		}

		$this->logger->warning("Got an unknown task type: $taskType->value");
	}

	private function handleDrawCandlestickChartTask($attributes): void {
		// Important task attributes
		$ticker = $attributes['pair'];
		$timeframe = TimeFrameEnum::from($attributes['timeframe']);
		$exchangeName = $attributes['exchange'];
		$marketType = MarketTypeEnum::from($attributes['marketType']);
		$pair = new Pair($ticker, $timeframe, $exchangeName, $marketType);
		$this->logger->info("Got a task for drawing a candlestick chart for $pair ($marketType->value, $timeframe->value) on $exchangeName");

		$exchange = $this->configuration->connectExchange($this, $exchangeName);
		if (!$exchange)
			return;

		$market = $exchange->createMarket($pair);
		if (!$market)
			return;

		// Initialize and calculate indicators before drawing chart
		$market->initializeIndicators();
		$market->calculateIndicators();
		$market->drawChart();
	}
}
