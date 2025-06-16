#!/usr/bin/env php
<?php

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/lib/common.php';

$analyzer = Izzy\Analyzer::getInstance();
$analyzer->run();
