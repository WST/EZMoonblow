<?php

namespace Izzy\Indicators;

use Izzy\Financial\IndicatorResult;
use Izzy\Interfaces\IIndicator;
use Izzy\Interfaces\IMarket;

/**
 * Abstract base class for all technical indicators.
 * Provides common functionality and helper methods.
 */
abstract class AbstractIndicator implements IIndicator
{
    /**
     * Indicator parameters.
     * @var array
     */
    protected array $parameters;
    
    /**
     * Constructor for abstract indicator.
     * 
     * @param array $parameters Indicator parameters.
     */
    public function __construct(array $parameters = []) {
        $this->parameters = $parameters;
    }
    
    /**
     * Get indicator parameters.
     * 
     * @return array Indicator parameters.
     */
    public function getParameters(): array {
        return $this->parameters;
    }
    
    /**
     * Get candles for the specified period.
     * 
     * @param IMarket $market Market instance.
     * @param int $period Number of candles to get.
     * @return array Array of candles.
     */
    protected function getCandlesForPeriod(IMarket $market, int $period): array {
        $candles = $market->getCandles();
        return array_slice($candles, -$period);
    }
    
    /**
     * Get close prices from candles.
     * 
     * @param array $candles Array of candle objects.
     * @return array Array of close prices.
     */
    protected function getClosePrices(array $candles): array {
        return array_map(fn($candle) => $candle->getClosePrice(), $candles);
    }
    
    /**
     * Get high prices from candles.
     * 
     * @param array $candles Array of candle objects.
     * @return array Array of high prices.
     */
    protected function getHighPrices(array $candles): array {
        return array_map(fn($candle) => $candle->getHighPrice(), $candles);
    }
    
    /**
     * Get low prices from candles.
     * 
     * @param array $candles Array of candle objects.
     * @return array Array of low prices.
     */
    protected function getLowPrices(array $candles): array {
        return array_map(fn($candle) => $candle->getLowPrice(), $candles);
    }
    
    /**
     * Get open prices from candles.
     * 
     * @param array $candles Array of candle objects.
     * @return array Array of open prices.
     */
    protected function getOpenPrices(array $candles): array {
        return array_map(fn($candle) => $candle->getOpenPrice(), $candles);
    }
    
    /**
     * Get volumes from candles.
     * 
     * @param array $candles Array of candle objects.
     * @return array Array of volumes.
     */
    protected function getVolumes(array $candles): array {
        return array_map(fn($candle) => $candle->getVolume(), $candles);
    }
    
    /**
     * Get timestamps from candles.
     * 
     * @param array $candles Array of candle objects.
     * @return array Array of timestamps.
     */
    protected function getTimestamps(array $candles): array {
        return array_map(fn($candle) => $candle->getOpenTime(), $candles);
    }
    
    /**
     * Calculate simple moving average.
     * 
     * @param array $prices Array of prices.
     * @param int $period Period for SMA calculation.
     * @return array Array of SMA values.
     */
    protected function calculateSMA(array $prices, int $period): array {
        $sma = [];
        $count = count($prices);
        
        for ($i = $period - 1; $i < $count; $i++) {
            $sum = array_sum(array_slice($prices, $i - $period + 1, $period));
            $sma[] = $sum / $period;
        }
        
        return $sma;
    }
    
    /**
     * Calculate exponential moving average.
     * 
     * @param array $prices Array of prices.
     * @param int $period Period for EMA calculation.
     * @return array Array of EMA values.
     */
    protected function calculateEMA(array $prices, int $period): array {
        $ema = [];
        $multiplier = 2 / ($period + 1);
        $count = count($prices);
        
        if ($count < $period) {
            return $ema;
        }
        
        // First EMA value is SMA
        $firstSMA = array_sum(array_slice($prices, 0, $period)) / $period;
        $ema[] = $firstSMA;
        
        // Calculate subsequent EMA values
        for ($i = $period; $i < $count; $i++) {
            $emaValue = ($prices[$i] * $multiplier) + ($ema[count($ema) - 1] * (1 - $multiplier));
            $ema[] = $emaValue;
        }
        
        return $ema;
    }
}
