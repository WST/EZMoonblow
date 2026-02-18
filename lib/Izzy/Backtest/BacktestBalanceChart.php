<?php

namespace Izzy\Backtest;

/**
 * Generates an RRD-based balance chart PNG from backtest balance snapshots.
 *
 * Creates a temporary RRD database, bulk-writes snapshots, renders a PNG
 * graph with the same visual style as the live balance charts, then cleans
 * up all temporary files and returns the PNG binary data.
 */
class BacktestBalanceChart
{
	/** Maximum data points per single rrdtool update call. */
	private const int BATCH_SIZE = 500;

	/**
	 * Generate a balance chart PNG from an array of snapshots.
	 *
	 * @param array $snapshots Array of [timestamp, balance] pairs, sorted by timestamp ascending.
	 * @param int $simStart Simulation start timestamp.
	 * @param int $simEnd Simulation end timestamp.
	 * @param int $stepSeconds Candle interval in seconds (e.g. 3600 for 1h).
	 * @return string|null PNG binary data, or null on failure.
	 */
	public static function generate(
		array $snapshots,
		int $simStart,
		int $simEnd,
		int $stepSeconds,
	): ?string {
		if (empty($snapshots) || $stepSeconds <= 0) {
			return null;
		}

		// Pre-compute aligned data points so we know the exact time range.
		$aligned = self::alignSnapshots($snapshots, $stepSeconds);
		if (empty($aligned)) {
			return null;
		}

		$firstTs = array_key_first($aligned);
		$lastTs = array_key_last($aligned);
		$chartEnd = max($simEnd, $lastTs + $stepSeconds);

		$rrdFile = tempnam(sys_get_temp_dir(), 'bt-rrd-') . '.rrd';
		$pngFile = tempnam(sys_get_temp_dir(), 'bt-chart-') . '.png';

		try {
			// 1. Create the RRD database with --start before the first data point.
			if (!self::createRrd($rrdFile, $firstTs, $chartEnd, $stepSeconds)) {
				return null;
			}

			// 2. Bulk-write pre-aligned balance snapshots.
			if (!self::writeAligned($rrdFile, $aligned)) {
				return null;
			}

			// 3. Generate the PNG chart.
			if (!self::renderChart($rrdFile, $pngFile, $firstTs, $chartEnd)) {
				return null;
			}

			// 4. Read the PNG data.
			$png = file_get_contents($pngFile);
			return $png !== false ? $png : null;
		} finally {
			// Always clean up temporary files.
			@unlink($rrdFile);
			@unlink($pngFile);
			// tempnam creates the base file without extension too.
			$rrdBase = substr($rrdFile, 0, -4);
			if (file_exists($rrdBase)) {
				@unlink($rrdBase);
			}
			$pngBase = substr($pngFile, 0, -4);
			if (file_exists($pngBase)) {
				@unlink($pngBase);
			}
		}
	}

	/**
	 * Create a temporary RRD database.
	 *
	 * @param int $firstAlignedTs The first step-aligned timestamp that will be written.
	 */
	private static function createRrd(string $rrdFile, int $firstAlignedTs, int $simEnd, int $stepSeconds): bool {
		$heartbeat = $stepSeconds * 2;
		$totalSteps = (int) ceil(($simEnd - $firstAlignedTs) / $stepSeconds) + 100;
		$rrdEsc = escapeshellarg($rrdFile);

		// RRD --start must be strictly before the first update timestamp.
		$startArg = $firstAlignedTs - 1;

		$command = "rrdtool create $rrdEsc "
			. "--start $startArg "
			. "--step $stepSeconds "
			. "DS:balance:GAUGE:$heartbeat:0:U "
			. "RRA:LAST:0.5:1:$totalSteps";

		exec($command, $output, $exitCode);
		return $exitCode === 0;
	}

	/**
	 * Align snapshots to step boundaries and deduplicate.
	 *
	 * @return array<int, float> Map of aligned timestamp => balance, sorted ascending.
	 */
	private static function alignSnapshots(array $snapshots, int $stepSeconds): array {
		$aligned = [];
		foreach ($snapshots as [$ts, $balance]) {
			$alignedTs = (int) (floor($ts / $stepSeconds) * $stepSeconds);
			$aligned[$alignedTs] = $balance;
		}
		ksort($aligned);
		return $aligned;
	}

	/**
	 * Write pre-aligned data points to the RRD in batches.
	 *
	 * @param array<int, float> $aligned Map of timestamp => balance.
	 */
	private static function writeAligned(string $rrdFile, array $aligned): bool {
		$rrdEsc = escapeshellarg($rrdFile);

		$batch = [];
		foreach ($aligned as $ts => $balance) {
			$batch[] = "$ts:" . sprintf('%.8f', $balance);

			if (count($batch) >= self::BATCH_SIZE) {
				$dataPoints = implode(' ', $batch);
				exec("rrdtool update $rrdEsc $dataPoints", $output, $exitCode);
				if ($exitCode !== 0) {
					return false;
				}
				$batch = [];
			}
		}

		// Write remaining points.
		if (!empty($batch)) {
			$dataPoints = implode(' ', $batch);
			exec("rrdtool update $rrdEsc $dataPoints", $output, $exitCode);
			if ($exitCode !== 0) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Render the chart PNG using rrdtool graph.
	 * Uses the same visual style as the live balance charts in Analyzer.
	 */
	private static function renderChart(string $rrdFile, string $pngFile, int $simStart, int $simEnd): bool {
		$rrdEsc = escapeshellarg($rrdFile);
		$pngEsc = escapeshellarg($pngFile);

		$command = "rrdtool graph $pngEsc "
			. "--start $simStart "
			. "--end $simEnd "
			. "--title 'Backtest Balance' "
			. "--vertical-label 'Balance (USDT)' "
			. "--width 640 "
			. "--height 320 "
			. "--color CANVAS#FFFFFF "
			. "--color BACK#FFFFFF "
			. "--color SHADEA#FFFFFF "
			. "--color SHADEB#FFFFFF "
			. "--color GRID#CCCCCC "
			. "--color MGRID#999999 "
			. "--color FONT#000000 "
			. "--color AXIS#000000 "
			. "--color ARROW#000000 "
			. "--color FRAME#000000 "
			. "DEF:balance=$rrdEsc:balance:LAST "
			. "AREA:balance#0066CC:'Balance' "
			. "GPRINT:balance:LAST:'Final\\: %8.2lf USDT' "
			. "GPRINT:balance:AVERAGE:'Average\\: %8.2lf USDT' "
			. "GPRINT:balance:MAX:'Maximum\\: %8.2lf USDT' "
			. "GPRINT:balance:MIN:'Minimum\\: %8.2lf USDT'";

		exec($command, $output, $exitCode);
		return $exitCode === 0 && file_exists($pngFile);
	}
}
