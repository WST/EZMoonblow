<?php

/**
 * Migration: Create positions table
 * 
 * This migration creates a table to store information about trading positions
 * including entry price, volume, direction, status, and exchange details.
 */

$positionsFields = [
    'id' => 'INT AUTO_INCREMENT PRIMARY KEY',
    'exchange_name' => "VARCHAR(50) NOT NULL",
    'ticker' => "VARCHAR(20) NOT NULL",
    'market_type' => "ENUM('spot', 'futures') NOT NULL",
    'direction' => "ENUM('long', 'short') NOT NULL",
    'entry_price' => "DECIMAL(20,8) NOT NULL",
    'current_price' => "DECIMAL(20,8) NOT NULL",
    'volume' => "DECIMAL(20,8) NOT NULL",
    'currency' => "VARCHAR(10) NOT NULL",
    'status' => "ENUM('open', 'closed', 'pending') NOT NULL DEFAULT 'open'",
    'position_id' => "VARCHAR(100) NULL",
    'order_id' => "VARCHAR(100) NULL",
    'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
    'updated_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
];
$positionsKeys = [
    'INDEX idx_exchange_ticker' => ['exchange_name', 'ticker'],
    'INDEX idx_status' => ['status'],
    'INDEX idx_position_id' => ['position_id'],
];
$manager->createTable('positions', $positionsFields, $positionsKeys);
