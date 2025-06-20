#!/usr/bin/env php
<?php

use Izzy\Installer;

// Check if vendor/autoload.php exists.
if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    die('Please run "composer install" first');
}

// EZMoonblow core file.
require __DIR__ . '/lib/common.php';

$installer = Installer::getInstance();
$installer->run();
