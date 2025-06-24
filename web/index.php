<?php

use Izzy\AbstractApplications\WebApplication;

require_once dirname(__DIR__) . '/lib/common.php';

$webApp = WebApplication::getInstance();
$webApp->run();
