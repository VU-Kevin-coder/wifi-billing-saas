#!/bin/sh
set -eu

/usr/local/bin/preflight.sh worker
exec php artisan queue:work redis --sleep=3 --tries=3 --timeout=60 --no-interaction
