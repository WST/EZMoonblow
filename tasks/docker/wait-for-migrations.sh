#!/bin/bash

# Wait for migrations to complete.
# This script checks if migrations have been run by looking for a marker file.

MIGRATION_MARKER="/app/migration_marker/.migrations-complete"
MAX_WAIT=${MAX_WAIT:-300}  # Maximum wait time in seconds (default 5 minutes)
WAIT_INTERVAL=${WAIT_INTERVAL:-2}  # Check interval in seconds

elapsed=0
while [ ! -f "$MIGRATION_MARKER" ]; do
	if [ $elapsed -ge $MAX_WAIT ]; then
		echo "Timeout waiting for migrations to complete." >&2
		exit 1
	fi
	echo "Waiting for migrations to complete... ($elapsed/$MAX_WAIT seconds)"
	sleep $WAIT_INTERVAL
	elapsed=$((elapsed + WAIT_INTERVAL))
done

echo "Migrations completed. Starting application..."

