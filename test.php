<?php

use Izzy\Enums\MarketTypeEnum;
use Izzy\Enums\PositionDirectionEnum;
use Izzy\Enums\TimeFrameEnum;
use Izzy\Financial\Money;
use Izzy\Financial\Pair;
use Izzy\RealApplications\Backtester;

require __DIR__.'/lib/common.php';

$app = Backtester::getInstance();
$config = $app->getConfiguration();
$bybit = $config->connectExchange($app, 'Bybit');

$pair = new Pair("XRP/USDT", TimeFrameEnum::TF_1HOUR, 'Bybit', MarketTypeEnum::FUTURES);
$market = $bybit->createMarket($pair);

$amount = Money::from(20);
$direction = PositionDirectionEnum::SHORT;
$takeProfitPercent = 2.0;

$position = $market->openShortPosition($amount, $takeProfitPercent);

var_dump($position);
