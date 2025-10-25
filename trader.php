#!/usr/bin/env php
<?php

use Izzy\RealApplications\Trader;

require __DIR__.'/lib/common.php';

$trader = Trader::getInstance();
$trader->run();
