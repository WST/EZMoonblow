<?php

/**
 * Standalone SSE endpoint for backtest event streaming.
 *
 * This file is served directly by Nginx, bypassing Slim and all its
 * middleware/output buffering. This is necessary because SSE requires
 * unbuffered real-time output (echo + flush), which is incompatible
 * with Slim's PSR-7 response lifecycle.
 */

require_once dirname(__DIR__) . '/lib/common.php';

use Izzy\RealApplications\Backtester;

// Authenticate: reuse the PHP session started by the Slim app.
session_start();
if (empty($_SESSION['authorized'])) {
	http_response_code(403);
	header('Content-Type: application/json');
	echo json_encode(['error' => 'Unauthorized']);
	exit;
}
session_write_close();

// Validate session ID.
$sessionId = $_GET['id'] ?? '';
if (!preg_match('/^[a-zA-Z0-9\-]+$/', $sessionId)) {
	http_response_code(400);
	header('Content-Type: application/json');
	echo json_encode(['error' => 'Invalid session ID']);
	exit;
}

set_time_limit(0);

// SSE headers.
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

// Clear all output buffers.
while (ob_get_level()) {
	ob_end_flush();
}

$eventsFile = Backtester::getEventFilePath($sessionId);

// Wait for the events file to appear (backtest process may not have started yet).
$waitStart = time();
while (!file_exists($eventsFile)) {
	if (time() - $waitStart > 30) {
		echo "data: " . json_encode(['type' => 'error', 'message' => 'Backtest process did not start']) . "\n\n";
		flush();
		exit;
	}
	usleep(200_000);
}

$fh = fopen($eventsFile, 'rb');
if (!$fh) {
	echo "data: " . json_encode(['type' => 'error', 'message' => 'Cannot open events file']) . "\n\n";
	flush();
	exit;
}

// Support SSE reconnection: if the browser sends Last-Event-ID, seek to
// the byte position where it left off so the client doesn't miss events.
$lastEventId = $_SERVER['HTTP_LAST_EVENT_ID'] ?? ($_GET['lastEventId'] ?? '');
if ($lastEventId !== '' && ctype_digit($lastEventId)) {
	$resumePos = (int)$lastEventId;
	fseek($fh, $resumePos);
}

// Tell the browser to reconnect after 2 seconds if the connection drops.
echo "retry: 2000\n\n";
flush();

$lastActivity = time();
$done = false;

while (!$done) {
	$line = fgets($fh);
	if ($line !== false) {
		$line = trim($line);
		if ($line !== '') {
			$pos = ftell($fh);
			echo "id: $pos\ndata: $line\n\n";
			flush();
			$lastActivity = time();

			$decoded = json_decode($line, true);
			if (is_array($decoded) && ($decoded['type'] ?? '') === 'done') {
				$done = true;
			}
		}
	} else {
		// Clear the EOF flag so fgets() can read newly appended data.
		// Without this, PHP's stream layer remembers "we hit EOF" and
		// keeps returning false even as the backtest process writes more lines.
		fseek($fh, 0, SEEK_CUR);

		if (time() - $lastActivity > 5) {
			echo ": keep-alive\n\n";
			flush();
			$lastActivity = time();
		}
		usleep(100_000);

		if (time() - $lastActivity > 600) {
			echo "data: " . json_encode(['type' => 'error', 'message' => 'Stream timeout']) . "\n\n";
			flush();
			$done = true;
		}
	}

	if (connection_aborted()) {
		$done = true;
	}
}

fclose($fh);

// Cleanup temp files.
@unlink($eventsFile);
$configFile = Backtester::getConfigFilePath($sessionId);
@unlink($configFile);
