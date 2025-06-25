<?php

namespace Izzy\Indicators;

use Izzy\Financial\Candle;
use Izzy\Interfaces\IIndicator;
use Izzy\Interfaces\IPair;

/**
 * Abstract base class for all technical indicators.
 * 
 * Provides common functionality and enforces the contract for indicator implementations.
 * All indicators should extend this class and implement the calculate() method.
 */
abstract class AbstractIndicator implements IIndicator
{
    /**
     * @var array Configuration parameters for the indicator
     */
    protected array $parameters;

    /**
     * @var IPair The trading pair this indicator is calculated for
     */
    protected IPair $pair;

    /**
     * Constructor for AbstractIndicator.
	 * @param IPair $pair The trading pair to calculate the indicator for
 	 * @param array $parameters Configuration parameters for the indicator
     */
    public function __construct(IPair $pair, array $parameters) {
        $this->parameters = $parameters;
        $this->pair = $pair;
    }

    /**
     * Get the indicator parameters.
     * 
     * @return array The configuration parameters
     */
    public function getParameters(): array {
        return $this->parameters;
    }

    /**
     * Get the trading pair.
     * 
     * @return IPair The trading pair
     */
    public function getPair(): IPair {
        return $this->pair;
    }

    /**
     * Validate that enough data is available for calculation.
     * 
     * @param array $candles Array of candle data
     * @param int $requiredCount Minimum number of candles required
     * @return bool True if enough data is available
     */
    protected function validateData(array $candles, int $requiredCount): bool {
        return count($candles) >= $requiredCount;
    }

    /**
     * Get a parameter value with optional default.
     * 
     * @param string $key Parameter key
     * @param mixed $default Default value if parameter not found
     * @return mixed Parameter value or default
     */
    protected function getParameter(string $key, mixed $default = null): mixed {
        return $this->parameters[$key] ?? $default;
    }

    /**
     * Extract close prices from array of candles.
     * 
     * @param array $candles Array of candle objects.
     * @return array Array of close prices as floats.
     */
    protected function getClosePrices(array $candles): array {
        return array_map(fn($candle) => $candle->getClosePrice(), $candles);
    }

    /**
     * Extract timestamps from array of candles.
     * 
     * @param array $candles Array of candle objects.
     * @return array Array of timestamps as integers.
     */
    protected function getTimestamps(array $candles): array {
        return array_map(fn($candle) => $candle->getOpenTime(), $candles);
    }
}
