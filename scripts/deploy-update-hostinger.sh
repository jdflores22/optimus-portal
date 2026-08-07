#!/usr/bin/env bash
# Update-only deploy for Hostinger
set -euo pipefail

APP_DIR="${1:-$HOME/websites/s2Y40pWr2/public_html/_optimus}"

cd "$APP_DIR"

if [[ ! -f .env.local ]]; then
  echo "ERROR: .env.local not found. Run: ln -sf ../.env.local .env.local"
  exit 1
fi

git pull origin main
composer install --no-dev --optimize-autoloader --no-interaction

if command -v npm >/dev/null 2>&1; then
  npm ci --omit=dev 2>/dev/null || npm install --omit=dev
  npm run build:assets
fi

php bin/console doctrine:migrations:migrate --no-interaction --env=prod
php bin/console cache:clear --env=prod --no-warmup
php bin/console cache:warmup --env=prod

# Flush emails/notifications stuck in queue from before sync routing
php bin/console messenger:consume async --env=prod --limit=100 --time-limit=60 -vv || true

echo "Deploy complete."
