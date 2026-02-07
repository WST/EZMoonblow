<?php

namespace Izzy\Chart;

use Izzy\Financial\IndicatorResult;
use Izzy\Indicators\RSI;
use Izzy\Interfaces\IIndicator;

/**
 * RSI indicator visualizer.
 * Draws RSI as an oscillator below the main chart with overbought/oversold levels.
 */
class RSIVisualizer extends AbstractIndicatorVisualizer
{
	/**
	 * RSI-specific colors.
	 */
	private const RSI_COLORS = [
		'line' => [63, 110, 180],        // Red
		'overbought' => [255, 179, 162],      // Bright red
		'oversold' => [211, 255, 190],        // Bright green
		'overbought_dot' => [255, 0, 0],      // red
		'oversold_dot' => [0, 255, 0],        // Green
		'neutral' => [128, 128, 128],     // Gray
		'level' => [180, 180, 180],       // Medium gray
		'grid' => [230, 230, 230],        // Light gray
	];

	/**
	 * RSI grid levels.
	 */
	private const RSI_GRID_LEVELS = [0, 25, 50, 75, 100];

	/**
	 * Check if this visualizer can handle the given indicator.
	 *
	 * @param IIndicator $indicator The indicator to check.
	 * @return bool True if this visualizer can visualize the indicator.
	 */
	public function canVisualize(IIndicator $indicator): bool {
		return $indicator instanceof RSI;
	}

	/**
	 * Get the visualization type for RSI.
	 *
	 * @return string Visualization type.
	 */
	public function getVisualizationType(): string {
		return 'oscillator';
	}

	/**
	 * Visualize the RSI indicator on the chart.
	 *
	 * @param Chart $chart The chart to draw on.
	 * @param IIndicator $indicator The RSI indicator to visualize.
	 * @param IndicatorResult $result The calculated RSI result.
	 * @return void
	 */
	public function visualize(Chart $chart, IIndicator $indicator, IndicatorResult $result): void {
		if (!$result->hasValues()) {
			return;
		}

		// Create oscillator area below the main chart
		$oscillatorArea = $this->createOscillatorAreaBelowMainChart($chart);

		// Draw overbought/oversold levels
		$this->drawOverboughtOversoldLevels($chart, $oscillatorArea, $indicator);

		// Draw RSI line
		$this->drawRSILine($chart, $oscillatorArea, $result);

		// Draw signal indicators
		$this->drawSignalIndicators($chart, $oscillatorArea, $result);

		// Draw RSI label
		$this->drawRSILabel($chart, $oscillatorArea);
	}

	/**
	 * Draw RSI grid with levels 0, 25, 50, 75, 100.
	 *
	 * @param Chart $chart The chart to draw on.
	 * @param array $area Oscillator area coordinates.
	 * @return void
	 */
	private function drawRSIGrid(Chart $chart, array $area): void {
		$this->drawOscillatorGrid($chart, $area, self::RSI_GRID_LEVELS, self::RSI_COLORS['grid']);
	}

	/**
	 * Draw overbought and oversold level lines.
	 *
	 * @param Chart $chart The chart to draw on.
	 * @param array $area Oscillator area coordinates.
	 * @param IIndicator $indicator The RSI indicator.
	 * @return void
	 */
	private function drawOverboughtOversoldLevels(Chart $chart, array $area, IIndicator $indicator): void {
		$parameters = $indicator->getParameters();
		$overbought = $parameters['overbought'] ?? 70;
		$oversold = $parameters['oversold'] ?? 30;

		// Draw overbought level
		$overboughtY = $area['y'] + $area['height'] - ($overbought / 100) * $area['height'];
		$this->drawLine($chart, $area['x'], $overboughtY, $area['x'] + $area['width'], $overboughtY, self::RSI_COLORS['overbought']);

		// Draw oversold level
		$oversoldY = $area['y'] + $area['height'] - ($oversold / 100) * $area['height'];
		$this->drawLine($chart, $area['x'], $oversoldY, $area['x'] + $area['width'], $oversoldY, self::RSI_COLORS['oversold']);
	}

	/**
	 * Draw the RSI line.
	 *
	 * @param Chart $chart The chart to draw on.
	 * @param array $area Oscillator area coordinates.
	 * @param IndicatorResult $result The RSI result.
	 * @return void
	 */
	private function drawRSILine(Chart $chart, array $area, IndicatorResult $result): void {
		$values = $result->getValues();
		$timestamps = $result->getTimestamps();

		if (empty($values) || empty($timestamps)) {
			return;
		}

		$candles = $chart->getMarket()->getCandles();
		$candleCount = count($candles);

		if ($candleCount === 0) {
			return;
		}

		// Calculate x-coordinate mapping
		$candleWidth = $chart->getCandleWidth();
		$candleSpacing = $chart->getCandleSpacing();
		$startX = $area['x'];

		// Create timestamp to index mapping
		$timestampToIndex = [];
		foreach ($candles as $index => $candle) {
			$timestampToIndex[$candle->getOpenTime()] = $index;
		}

		// Draw RSI line
		for ($i = 0; $i < count($values) - 1; $i++) {
			$currentValue = $values[$i];
			$nextValue = $values[$i + 1];
			$currentTimestamp = $timestamps[$i];
			$nextTimestamp = $timestamps[$i + 1];

			// Find corresponding candle indices
			$currentIndex = $timestampToIndex[$currentTimestamp] ?? null;
			$nextIndex = $timestampToIndex[$nextTimestamp] ?? null;

			if ($currentIndex === null || $nextIndex === null) {
				continue;
			}

			// Map RSI values to y-coordinates (0-100 to area height)
			$currentY = $area['y'] + $area['height'] - ($currentValue / 100) * $area['height'];
			$nextY = $area['y'] + $area['height'] - ($nextValue / 100) * $area['height'];

			// Calculate x-coordinates based on candle indices
			$currentX = $startX + $currentIndex * ($candleWidth + $candleSpacing);
			$nextX = $startX + $nextIndex * ($candleWidth + $candleSpacing);

			// Draw line segment
			$this->drawLine($chart, $currentX, $currentY, $nextX, $nextY, self::RSI_COLORS['line']);
		}
	}

	/**
	 * Draw signal indicators (overbought/oversold areas).
	 *
	 * @param Chart $chart The chart to draw on.
	 * @param array $area Oscillator area coordinates.
	 * @param IndicatorResult $result The RSI result.
	 * @return void
	 */
	private function drawSignalIndicators(Chart $chart, array $area, IndicatorResult $result): void {
		$values = $result->getValues();
		$signals = $result->getSignals();
		$timestamps = $result->getTimestamps();

		if (empty($values) || empty($signals) || empty($timestamps)) {
			return;
		}

		$candles = $chart->getMarket()->getCandles();
		$candleWidth = $chart->getCandleWidth();
		$candleSpacing = $chart->getCandleSpacing();
		$startX = $area['x'];

		// Create timestamp to index mapping
		$timestampToIndex = [];
		foreach ($candles as $index => $candle) {
			$timestampToIndex[$candle->getOpenTime()] = $index;
		}

		for ($i = 0; $i < count($values); $i++) {
			$signal = $signals[$i] ?? 'neutral';
			$value = $values[$i];
			$timestamp = $timestamps[$i];

			// Find corresponding candle index
			$candleIndex = $timestampToIndex[$timestamp] ?? null;
			if ($candleIndex === null) {
				continue;
			}

			// Calculate position
			$x = $startX + $candleIndex * ($candleWidth + $candleSpacing);
			$y = $area['y'] + $area['height'] - ($value / 100) * $area['height'];

			// Draw signal indicator based on signal type
			switch ($signal) {
				case 'overbought':
					$this->drawSignalDot($chart, $x, $y, self::RSI_COLORS['overbought_dot']);
					break;
				case 'oversold':
					$this->drawSignalDot($chart, $x, $y, self::RSI_COLORS['oversold_dot']);
					break;
				default:
					// No special indicator for neutral
					break;
			}
		}
	}

	/**
	 * Draw a signal dot at the specified position.
	 *
	 * @param Chart $chart The chart to draw on.
	 * @param float $x X coordinate.
	 * @param float $y Y coordinate.
	 * @param array $color RGB color array.
	 * @return void
	 */
	private function drawSignalDot(Chart $chart, float $x, float $y, array $color): void {
		$dotSize = 3;
		$this->drawFilledRectangle(
			$chart,
			$x - $dotSize,
			$y - $dotSize,
			$x + $dotSize,
			$y + $dotSize,
			$color
		);
	}

	/**
	 * Create oscillator area below the main chart.
	 *
	 * @param Chart $chart The chart to draw on.
	 * @return array Array with oscillator area coordinates.
	 */
	private function createOscillatorAreaBelowMainChart(Chart $chart): array {
		$chartArea = $chart->getChartArea();
		$totalHeight = $chart->getHeight();
		$padding = $chart->getPadding();

		// Calculate available height (total height minus padding)
		$availableHeight = $totalHeight - $padding['top'] - $padding['bottom'];

		// Main chart takes 75%, gap takes 5%, oscillator takes 20%
		$mainChartHeight = $availableHeight * 0.75;
		$gapHeight = $availableHeight * 0.05;
		$oscillatorHeight = $availableHeight * 0.20;

		return [
			'x' => $chartArea['x'],
			'y' => $chartArea['y'] + $mainChartHeight + $gapHeight,
			'width' => $chartArea['width'],
			'height' => $oscillatorHeight
		];
	}

	/**
	 * Draw RSI label on the oscillator area.
	 *
	 * @param Chart $chart The chart to draw on.
	 * @param array $area Oscillator area coordinates.
	 * @return void
	 */
	private function drawRSILabel(Chart $chart, array $area): void {
		$this->drawText($chart, $area['x'] + 5, $area['y'] + 15, 'RSI', 10, self::RSI_COLORS['line']);
	}
}
