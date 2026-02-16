<?php

$manager->addTableColumn(
	'backtest_results',
	'br_trades_bl',
	"INT NOT NULL DEFAULT 0 AFTER br_trades_losses",
);
