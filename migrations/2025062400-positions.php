<?php

/**
 * Migration: Create positions table
 * 
 * This migration creates a table to store information about trading positions
 * including entry price, volume, direction, status, and exchange details.
 */
$positionsFields = [
	'position_id' => 'INT AUTO_INCREMENT PRIMARY KEY',
	'position_exchange_name' => "VARCHAR(50) NOT NULL",
	'position_ticker' => "VARCHAR(20) NOT NULL",
	'position_market_type' => "ENUM('spot', 'futures') NOT NULL",
	'position_direction' => "ENUM('LONG', 'SHORT') NOT NULL DEFAULT 'LONG'",
	'position_entry_price' => "DECIMAL(20,8) NOT NULL",
	'position_current_price' => "DECIMAL(20,8) NOT NULL",
	'position_volume' => "DECIMAL(20,8) NOT NULL",
	'position_base_currency' => "VARCHAR(16) NOT NULL",
	'position_quote_currency' => "VARCHAR(16) NOT NULL",
	'position_status' => "ENUM('PENDING', 'OPEN', 'FINISHED', 'ERROR', 'CANCELLED') NOT NULL DEFAULT 'PENDING'",
	'position_id_on_exchange' => "VARCHAR(100) NULL",
	'position_order_id' => "VARCHAR(100) NULL",
	'position_created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
	'position_updated_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
];
$positionsKeys = [
	'INDEX idx_position_exchange_ticker_status' => ['position_exchange_name', 'position_ticker', 'position_status'],
	'INDEX idx_position_exchange_ticker' => ['position_exchange_name', 'position_ticker'],
	'INDEX idx_position_id_on_exchange' => ['position_id_on_exchange'],
	'INDEX idx_position_status' => ['position_status'],
];
$manager->createTable('positions', $positionsFields, $positionsKeys);
