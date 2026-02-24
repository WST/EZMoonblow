#!/usr/bin/env php
<?php

use Izzy\RealApplications\Screener;

require __DIR__.'/lib/common.php';

$app = Screener::getInstance();
$app->run();
