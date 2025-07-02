<?php

namespace Izzy\Chart;

use Izzy\Financial\IndicatorResult;
use Izzy\Interfaces\IIndicator;

/**
 * Interface for indicator visualizers.
 * Defines the contract for all indicator visualization implementations.
 */
interface IIndicatorVisualizer
{
	/**
	 * Check if this visualizer can handle the given indicator.
	 *
	 * @param IIndicator $indicator The indicator to check.
	 * @return bool True if this visualizer can visualize the indicator.
	 */
	public function canVisualize(IIndicator $indicator): bool;

	/**
	 * Visualize the indicator on the chart.
	 *
	 * @param Chart $chart The chart to draw on.
	 * @param IIndicator $indicator The indicator to visualize.
	 * @param IndicatorResult $result The calculated indicator result.
	 * @return void
	 */
	public function visualize(Chart $chart, IIndicator $indicator, IndicatorResult $result): void;

	/**
	 * Get the visualization type for this indicator.
	 *
	 * @return string Visualization type ('overlay', 'subplot', 'oscillator').
	 */
	public function getVisualizationType(): string;
}
