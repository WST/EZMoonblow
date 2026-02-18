<?php

$manager->addTableColumn(
	'backtest_results',
	'br_balance_chart',
	"MEDIUMBLOB NULL DEFAULT NULL AFTER br_pair_xml",
);
