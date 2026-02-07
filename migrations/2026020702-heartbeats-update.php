<?php

// Rename all columns to have the 'heartbeat_' prefix for consistency.
$manager->renameColumn('system_heartbeats', 'component_name', 'heartbeat_component_name');
$manager->renameColumn('system_heartbeats', 'last_heartbeat', 'heartbeat_last_heartbeat');
$manager->renameColumn('system_heartbeats', 'status', 'heartbeat_status');
$manager->renameColumn('system_heartbeats', 'pid', 'heartbeat_pid');
$manager->renameColumn('system_heartbeats', 'started_at', 'heartbeat_started_at');
$manager->renameColumn('system_heartbeats', 'extra_info', 'heartbeat_extra_info');
