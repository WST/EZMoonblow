#!/usr/bin/env php
<?php

use Izzy\Configuration\Configuration;

// EZMoonblow core file.
require __DIR__ . '/lib/common.php';

// Load the configuration file.
$configuration = new Configuration(IZZY_CONFIG . "/config.xml");

// Connect to the database.
$db = $configuration->openDatabase();
$db->connect();

// Apply the migrations. 
$db->runMigrations();
