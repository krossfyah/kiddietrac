#!/bin/sh
# Rebuild Laravel's route cache WITHOUT a window where it is missing.
#
# `php artisan route:cache` first runs route:clear (deletes bootstrap/cache/routes-v7.php)
# and only then writes the new file. A request arriving in between finds no cache and
# fails with "require(.../routes-v7.php): Failed to open stream" - support ticket #117
# (2026-10-06). Same family as #115 (a request parsing a half-uploaded file).
#
# So the cache is built under another name (APP_ROUTES_CACHE points route:cache, and its
# route:clear, at the temporary path) and renamed over the live file in one atomic mv.
# Usage: sh tools/route-cache-atomic.sh   (from anywhere)
set -e
cd "$(dirname "$0")/../backend"
NEXT=bootstrap/cache/routes-v7.next.php
rm -f "$NEXT"
APP_ROUTES_CACHE="$NEXT" php artisan route:cache >/dev/null
php -l "$NEXT" >/dev/null
mv -f "$NEXT" bootstrap/cache/routes-v7.php
echo "route cache rebuilt atomically ($(php artisan route:list 2>/dev/null | tail -1 | tr -s ' '))"
