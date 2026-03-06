#!/bin/bash
# PHP wrapper that delegates execution to the Docker container.
# Used by Cursor/VS Code for PHP validation without a local PHP install.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

args=()
for arg in "$@"; do
	if [[ "$arg" == "$PROJECT_DIR"* ]]; then
		arg="/app${arg#$PROJECT_DIR}"
	fi
	args+=("$arg")
done

docker run --rm -i \
	-v "$PROJECT_DIR:/app" \
	-w /app \
	ezmoonblow-php-cli \
	php "${args[@]}"
