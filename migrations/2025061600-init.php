<?php

$fields = ['exchange_name' => 'VARCHAR(64) NOT NULL DEFAULT \'\''];
$manager->createTable('exchange_balances', $fields);
