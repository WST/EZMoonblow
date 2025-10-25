<?php

namespace Izzy\Chart;

use Izzy\Interfaces\IIndicatorVisualizer;

/**
 * Abstract base class for indicator visualizers.
 * Provides common functionality and helper methods for drawing indicators on charts.
 */
abstract class AbstractIndicatorVisualizer implements IIndicatorVisualizer {
	/**
	 * Default colors for indicator visualization.
	 */
	protected const DEFAULT_COLORS = [
		'line' => [100, 100, 100],        // Gray
		'grid' => [230, 230, 230],        // Light gray
		'overbought' => [255, 0, 0],      // Red
		'oversold' => [0, 255, 0],        // Green
		'neutral' => [128, 128, 128],     // Gray
		'level' => [180, 180, 180],       // Medium gray
	];

	/**
	 * Draw a line between two points.
	 *
	 * @param Chart $chart The chart to draw on.
	 * @param float $x1 Start X coordinate.
	 * @param float $y1 Start Y coordinate.
	 * @param float $x2 End X coordinate.
	 * @param float $y2 End Y coordinate.
	 * @param array $color RGB color array.
	 * @return void
	 */
	protected function drawLine(Chart $chart, float $x1, float $y1, float $x2, float $y2, array $color): void {
		$chart->setForegroundColor($color[0], $color[1], $color[2]);
		$chart->drawLine($x1, $y1, $x2, $y2);
	}

	/**
	 * Draw horizontal text.
	 *
	 * @param Chart $chart The chart to draw on.
	 * @param float $x X coordinate.
	 * @param float $y Y coordinate.
	 * @param string $text Text to draw.
	 * @param int $fontSize Font size.
	 * @param array $color RGB color array.
	 * @return void
	 */
	protected function drawText(Chart $chart, float $x, float $y, string $text, int $fontSize, array $color): void {
		$chart->setForegroundColor($color[0], $color[1], $color[2]);
		$chart->drawHorizontalText($x, $y, $text, $fontSize);
	}

	/**
	 * Draw a filled rectangle.
	 *
	 * @param Chart $chart The chart to draw on.
	 * @param float $x1 Top-left X coordinate.
	 * @param float $y1 Top-left Y coordinate.
	 * @param float $x2 Bottom-right X coordinate.
	 * @param float $y2 Bottom-right Y coordinate.
	 * @param array $color RGB color array.
	 * @return void
	 */
	protected function drawFilledRectangle(Chart $chart, float $x1, float $y1, float $x2, float $y2, array $color): void {
		$chart->setForegroundColor($color[0], $color[1], $color[2]);
		$chart->fillRectangle($x1, $y1, $x2, $y2);
	}

	/**
	 * Map a value from one range to another.
	 *
	 * @param float $value Value to map.
	 * @param float $fromMin Source range minimum.
	 * @param float $fromMax Source range maximum.
	 * @param float $toMin Target range minimum.
	 * @param float $toMax Target range maximum.
	 * @return float Mapped value.
	 */
	protected function mapValue(float $value, float $fromMin, float $fromMax, float $toMin, float $toMax): float {
		if ($fromMax === $fromMin) {
			return $toMin;
		}

		return $toMin + (($value - $fromMin) / ($fromMax - $fromMin)) * ($toMax - $toMin);
	}

	/**
	 * Get the minimum and maximum values from an array.
	 *
	 * @param array $values Array of numeric values.
	 * @return array Array with 'min' and 'max' keys.
	 */
	protected function getValueRange(array $values): array {
		if (empty($values)) {
			return ['min' => 0, 'max' => 100];
		}

		return [
			'min' => min($values),
			'max' => max($values)
		];
	}

	/**
	 * Create an oscillator area below the main chart.
	 *
	 * @param Chart $chart The chart to create area for.
	 * @param float $heightRatio Height ratio of oscillator (0.0 to 1.0).
	 * @return array Array with oscillator area coordinates.
	 */
	protected function createOscillatorArea(Chart $chart, float $heightRatio = 0.2): array {
		$chartArea = $chart->getChartArea();
		$totalHeight = $chart->getHeight();
		$padding = $chart->getPadding();

		// Calculate available height (total height minus padding)
		$availableHeight = $totalHeight - $padding['top'] - $padding['bottom'];

		// Calculate main chart and oscillator heights
		$mainChartHeight = $availableHeight * (1 - $heightRatio);
		$oscillatorHeight = $availableHeight * $heightRatio;

		return [
			'x' => $chartArea['x'],
			'y' => $chartArea['y'] + $mainChartHeight,
			'width' => $chartArea['width'],
			'height' => $oscillatorHeight
		];
	}

	/**
	 * Draw a grid for oscillator indicators.
	 *
	 * @param Chart $chart The chart to draw on.
	 * @param array $area Oscillator area coordinates.
	 * @param array $levels Array of grid levels.
	 * @param array $color RGB color array.
	 * @return void
	 */
	protected function drawOscillatorGrid(Chart $chart, array $area, array $levels, array $color): void {
		foreach ($levels as $level) {
			$y = $area['y'] + $area['height'] - ($level / 100) * $area['height'];
			$this->drawLine($chart, $area['x'], $y, $area['x'] + $area['width'], $y, $color);

			// Draw level label with more spacing to avoid overlap with main chart scale
			$label = (string)$level;
			$this->drawText($chart, $area['x'] + $area['width'] + 15, $y + 5, $label, 8, $color);
		}
	}
}
