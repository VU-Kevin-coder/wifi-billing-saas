#!/bin/sh
set -eu

/usr/local/bin/preflight.sh worker
exec php artisan schedule:work
