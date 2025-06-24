<?php

// Check PHP version, exit if it's lower than 8.4.
if (version_compare(PHP_VERSION, '8.3.0', '<')) {
	die('PHP version 8.3 or higher is required.' . PHP_EOL);
}

// Root path for the project.
define('IZZY_ROOT', dirname(__DIR__));

// The class autoloader.
require IZZY_ROOT . '/vendor/autoload.php';

// Configuration files location.
const IZZY_CONFIG = IZZY_ROOT . '/config';

// Main configuration file name.
const IZZY_CONFIG_XML = IZZY_CONFIG . '/config.xml';

// RRD databases location.
const IZZY_RRD = IZZY_ROOT . '/rrd';

// Database migrations directory.
const IZZY_MIGRATIONS =  IZZY_ROOT . '/migrations';

// Charts directory.
const IZZY_CHARTS = IZZY_ROOT . '/charts';
