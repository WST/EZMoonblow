<?php

use Izzy\Enums\MarketTypeEnum;
use Izzy\Enums\PositionDirectionEnum;
use Izzy\Enums\TimeFrameEnum;
use Izzy\Exchanges\Gate\Gate;
use Izzy\Financial\Money;
use Izzy\Financial\Pair;
use Izzy\RealApplications\Backtester;

require __DIR__.'/lib/common.php';

$testedExchange = 'Gate';

/** @var Backtester $app */
$app = Backtester::getInstance();
$config = $app->getConfiguration();

/** @var Gate $ex */
$ex = $config->connectExchange($app, $testedExchange);

$pair = new Pair("XRP/USDT", TimeFrameEnum::TF_1HOUR, $ex->getName(), MarketTypeEnum::FUTURES);
$market = $ex->createMarket($pair);

$amount = Money::from(10);
$direction = PositionDirectionEnum::LONG;
$takeProfitPercent = 2.0;
$stopLossPercent = 1.0;

$position = $market->openPosition($amount, $direction, $takeProfitPercent, $stopLossPercent);

var_dump($position);
