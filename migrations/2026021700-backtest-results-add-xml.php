<?php

$manager->addTableColumn(
	'backtest_results',
	'br_pair_xml',
	"TEXT NULL DEFAULT NULL AFTER br_open_positions",
);
