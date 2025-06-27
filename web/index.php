<?php

use Izzy\RealApplications\IzzyWeb;

require_once dirname(__DIR__) . '/lib/common.php';

$webApp = IzzyWeb::getInstance();
$webApp->run();
