#!/usr/bin/env php
<?php

use Izzy\RealApplications\Notifier;

require __DIR__.'/lib/common.php';

$trader = Notifier::getInstance();
$trader->run();
