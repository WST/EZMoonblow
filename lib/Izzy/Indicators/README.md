# Technical Indicators

This directory contains technical indicators for the EZMoonblow trading system.

## Overview

Technical indicators are mathematical calculations based on price, volume, or other market data that help traders identify potential trading opportunities. The indicators system is designed to be extensible and easy to use.

## Architecture

### Core Components

- **IIndicator Interface** (`lib/Izzy/Interfaces/IIndicator.php`) - Base interface for all indicators
- **AbstractIndicator** (`lib/Izzy/Indicators/AbstractIndicator.php`) - Base class with common functionality
- **IndicatorResult** (`lib/Izzy/Financial/IndicatorResult.php`) - Container for indicator calculation results
- **IndicatorFactory** (`lib/Izzy/Indicators/IndicatorFactory.php`) - Factory for creating indicators

### Available Indicators

- **RSI** (`lib/Izzy/Indicators/RSI.php`) - Relative Strength Index

## Usage

### Basic Usage

```php
use Izzy\Indicators\RSI;
use Izzy\Indicators\IndicatorFactory;

// Create RSI indicator with custom parameters
$rsi = new RSI(['period' => 14, 'overbought' => 70, 'oversold' => 30]);

// Or use factory
$rsi = IndicatorFactory::create('RSI', ['period' => 14]);

// Calculate indicator for a market
$result = $rsi->calculate($market);

// Get latest value
$latestValue = $result->getLatestValue();

// Get latest signal
$signal = $result->getLatestSignal(); // 'overbought', 'oversold', or 'neutral'
```

### Integration with Market

```php
// Add indicator to market
$market->addIndicator($rsi);

// Calculate all indicators
$market->calculateIndicators();

// Get indicator result
$rsiResult = $market->getIndicatorResult('RSI');
$latestValue = $market->getLatestIndicatorValue('RSI');
$latestSignal = $market->getLatestIndicatorSignal('RSI');
```

### Configuration

Indicators can be configured in the XML configuration file:

```xml
<pair ticker="BTCUSDT" timeframe="15m" monitor="yes" trade="no" strategy="EZMoonblowDCA">
    <indicators>
        <indicator type="RSI" period="14" overbought="70" oversold="30" />
        <!-- Add more indicators here as they are implemented -->
        <!-- <indicator type="MACD" fast="12" slow="26" signal="9" /> -->
        <!-- <indicator type="BB" period="20" std_dev="2" /> -->
    </indicators>
</pair>
```

## Creating New Indicators

To create a new indicator:

1. Create a new class extending `AbstractIndicator`
2. Implement the required methods:
   - `getName()` - Return indicator name
   - `calculate(IMarket $market)` - Calculate indicator values
3. Register the indicator in `IndicatorFactory`

### Example: Simple Moving Average

```php
<?php

namespace Izzy\Indicators;

use Izzy\Financial\IndicatorResult;
use Izzy\Interfaces\IMarket;

class SMA extends AbstractIndicator
{
    public function getName(): string
    {
        return 'SMA';
    }
    
    public function calculate(IMarket $market): IndicatorResult
    {
        $period = $this->parameters['period'] ?? 20;
        $candles = $market->getCandles();
        
        if (count($candles) < $period) {
            return new IndicatorResult([], [], []);
        }
        
        $closePrices = $this->getClosePrices($candles);
        $smaValues = $this->calculateSMA($closePrices, $period);
        $timestamps = array_slice($this->getTimestamps($candles), $period - 1);
        
        return new IndicatorResult($smaValues, $timestamps);
    }
}
```

Then register it in `IndicatorFactory`:

```php
private static array $indicators = [
    'RSI' => RSI::class,
    'SMA' => SMA::class,
];
```

## Available Helper Methods

The `AbstractIndicator` class provides several helper methods:

- `getCandlesForPeriod(IMarket $market, int $period)` - Get candles for specific period
- `getClosePrices(array $candles)` - Extract close prices from candles
- `getHighPrices(array $candles)` - Extract high prices from candles
- `getLowPrices(array $candles)` - Extract low prices from candles
- `getOpenPrices(array $candles)` - Extract open prices from candles
- `getVolumes(array $candles)` - Extract volumes from candles
- `getTimestamps(array $candles)` - Extract timestamps from candles
- `calculateSMA(array $prices, int $period)` - Calculate Simple Moving Average
- `calculateEMA(array $prices, int $period)` - Calculate Exponential Moving Average

## Best Practices

1. **Parameter Validation** - Always validate indicator parameters in the constructor
2. **Error Handling** - Handle cases where insufficient data is available
3. **Performance** - Use efficient algorithms for calculations
4. **Documentation** - Document all parameters and their expected ranges
5. **Testing** - Test indicators with various market conditions

## Future Indicators

Planned indicators to implement:

- **MACD** - Moving Average Convergence Divergence
- **Bollinger Bands** - Volatility indicator
- **Stochastic Oscillator** - Momentum indicator
- **ATR** - Average True Range
- **Williams %R** - Momentum oscillator 