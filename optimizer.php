#!/usr/bin/env php
<?php

use Izzy\RealApplications\Optimizer;

require __DIR__.'/lib/common.php';

$app = Optimizer::getInstance();
$app->run();
