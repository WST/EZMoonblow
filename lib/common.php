<?php

// Check PHP version, exit if it’s lower than 8.4.
if (version_compare(PHP_VERSION, '8.4.0', '<')) {
	die('PHP version 8.4 or higher is required.'.PHP_EOL);
}

// Root path for the project.
define('IZZY_ROOT', dirname(__DIR__));

// Check if vendor/autoload.php exists.
if (!file_exists(IZZY_ROOT.'/vendor/autoload.php')) {
	die("Please run “composer install” first".PHP_EOL);
}

// Vanadzor.
date_default_timezone_set('Asia/Yerevan');

// The class autoloader.
require IZZY_ROOT.'/vendor/autoload.php';

// Configuration files location.
const IZZY_CONFIG = IZZY_ROOT.'/config';

// Main configuration file name.
const IZZY_CONFIG_XML = IZZY_CONFIG.'/config.xml';

// RRD databases location.
const IZZY_RRD = IZZY_ROOT.'/rrd';

// Database migrations directory.
const IZZY_MIGRATIONS = IZZY_ROOT.'/migrations';

// Charts directory.
const IZZY_CHARTS = IZZY_ROOT.'/charts';

// Web app templates.
const IZZY_TEMPLATES = IZZY_ROOT.'/templates';
const IZZY_CACHE = IZZY_ROOT.'/cache';
