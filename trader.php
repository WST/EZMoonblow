#!/usr/bin/env php
<?php

require __DIR__ . '/lib/common.php';

$trader = Izzy\Trader::getInstance();
$trader->run();
