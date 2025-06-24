#!/usr/bin/env php
<?php

require_once 'lib/common.php';

use Izzy\Configuration\Configuration;
use Izzy\Exchanges\Bybit;
use Izzy\Financial\StrategyFactory;
use Izzy\RealApplications\Analyzer;

// Initialize application
$application = new Analyzer();

// Test strategy factory
echo "Testing Strategy Factory:\n";
echo "Available strategies: " . implode(', ', StrategyFactory::getAvailableStrategies()) . "\n";

// Test indicator factory
echo "Testing Indicator Factory:\n";
echo "Available indicators: " . implode(', ', \Izzy\Indicators\IndicatorFactory::getAvailableTypes()) . "\n";

// Test creating a strategy
try {
    $config = new Configuration('config/config.xml');
    $exchanges = $config->connectExchanges($application);
    
    if (empty($exchanges)) {
        echo "No exchanges found in configuration.\n";
    } else {
        echo "Found " . count($exchanges) . " exchanges:\n";
        foreach ($exchanges as $name => $exchange) {
            echo "- $name\n";
            $exchange->update();
        }
    }
    
    echo "Success!\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
