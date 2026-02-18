<?php

/**
 * Migration: Widen task_attributes from TEXT to MEDIUMTEXT.
 *
 * Candle data is now serialized in task_attributes for chart-drawing tasks.
 * 1000 candles in compact JSON can exceed 64 KB (TEXT limit).
 * MEDIUMTEXT supports up to 16 MB, which is more than enough.
 */

$manager->modifyTableColumn(
	'tasks',
	'task_attributes',
	"MEDIUMTEXT NULL DEFAULT NULL",
);
