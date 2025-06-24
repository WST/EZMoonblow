#!/usr/bin/env php
<?php

use Izzy\RealApplications\Analyzer;

require __DIR__ . '/lib/common.php';

$analyzer = Analyzer::getInstance();
$analyzer->run();
