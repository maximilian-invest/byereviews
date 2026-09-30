#!/usr/bin/env bash
# Pull-based auto-deploy for byereviews.com.
# Installed on the VPS as /usr/local/bin/byereviews-deploy (a copy, NOT run from the repo,
# so a push can never change what runs as root). Triggered every minute by
# /etc/cron.d/byereviews-deploy. Does nothing unless origin/main has moved.
set -euo pipefail

REPO=/var/www/byereviews
BRANCH=main
LOG=/var/log/byereviews-deploy.log

cd "$REPO"
git fetch --quiet origin "$BRANCH"

[ "$(git rev-parse HEAD)" = "$(git rev-parse "origin/$BRANCH")" ] && exit 0

# -f discards local edits to tracked files; untracked files (stored orders) are kept.
git checkout --quiet -f -B "$BRANCH" "origin/$BRANCH"

mkdir -p public/orders
chown -R www-data:www-data public/orders
chmod 750 public/orders

echo "$(date -Is) deployed $(git rev-parse --short HEAD): $(git log -1 --format=%s)" >> "$LOG"
