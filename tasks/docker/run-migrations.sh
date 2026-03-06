#!/bin/bash

# Run database migrations and create a marker file upon completion.

set -e

MIGRATION_MARKER="/app/migration_marker/.migrations-complete"

# Remove old marker from previous runs so other services wait for fresh migrations.
rm -f "$MIGRATION_MARKER"

echo "Starting database migrations..."

# Wait for MySQL to be ready.
echo "Waiting for MySQL to be ready..."
php -r "
require '/app/lib/common.php';
\$config = \Izzy\Configuration\Configuration::getInstance();
\$db = \$config->openDatabase();
\$maxAttempts = 30;
\$attempt = 0;
while (\$attempt < \$maxAttempts) {
    if (\$db->connect()) {
        echo \"MySQL is ready.\n\";
        exit(0);
    }
    sleep(2);
    \$attempt++;
}
echo \"Failed to connect to MySQL after \$maxAttempts attempts.\n\";
exit(1);
"

# Run migrations.
/app/tasks/db/migrate

# Create marker directory if it doesn't exist.
mkdir -p "$(dirname "$MIGRATION_MARKER")"

# Create marker file to indicate migrations are complete.
touch "$MIGRATION_MARKER"
echo "Migrations completed successfully."

