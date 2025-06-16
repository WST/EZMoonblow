<?php

// Root path for the project.
define('IZZY_ROOT', dirname(__DIR__));

// The class autoloader.
require IZZY_ROOT . '/vendor/autoload.php';

// Configuration files location.
const IZZY_CONFIG = IZZY_ROOT . '/config';

// RRD databases location.
const IZZY_RRD = IZZY_ROOT . '/rrd';
