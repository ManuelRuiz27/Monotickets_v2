#!/bin/sh
set -e

if [ -x "vendor/bin/php-cs-fixer" ]; then
  EXEC="vendor/bin/php-cs-fixer"
elif command -v php-cs-fixer >/dev/null 2>&1; then
  EXEC="php-cs-fixer"
else
  echo "php-cs-fixer not found. Install it via Composer or globally." >&2
  exit 1
fi

"$EXEC" fix --config=.php-cs-fixer.php --dry-run --diff "$@"
