#!/usr/bin/env bash
# Fresh OPTIMUS install on Hostinger.
#
#   cd ~/websites/s2Y40pWr2/public_html
#   git clone https://github.com/jdflores22/optimus-portal.git _optimus
#   bash _optimus/scripts/deploy-fresh-hostinger.sh --wipe

set -euo pipefail

WEB_ROOT="${WEB_ROOT:-$HOME/websites/s2Y40pWr2/public_html}"
APP_DIR_NAME="${APP_DIR_NAME:-_optimus}"
GIT_REPO="${GIT_REPO:-https://github.com/jdflores22/optimus-portal.git}"
GIT_BRANCH="${GIT_BRANCH:-main}"

APP_DIR="$WEB_ROOT/$APP_DIR_NAME"
ENV_FILE="$WEB_ROOT/.env.local"
SELF="$(cd "$(dirname "$0")" && pwd)/$(basename "$0")"

do_wipe=false
if [[ "${1:-}" == "--wipe" ]]; then
  do_wipe=true
fi

if $do_wipe; then
  BACKUP_DIR="$WEB_ROOT/_optimus_backup_$(date +%Y%m%d_%H%M%S)"
  echo "=== OPTIMUS fresh deploy (wipe) ==="
  mkdir -p "$BACKUP_DIR"

  if [[ -f "$ENV_FILE" ]]; then
    cp -a "$ENV_FILE" "$BACKUP_DIR/.env.local"
    echo "Backed up .env.local"
  fi

  if [[ -d "$APP_DIR/public/uploads" ]]; then
    cp -a "$APP_DIR/public/uploads" "$BACKUP_DIR/uploads"
    echo "Backed up public/uploads"
  fi

  if [[ -f "$APP_DIR/config/firebase/service-account.json" ]]; then
    mkdir -p "$BACKUP_DIR/firebase"
    cp -a "$APP_DIR/config/firebase/service-account.json" "$BACKUP_DIR/firebase/"
  fi

  cp "$SELF" /tmp/optimus-deploy-fresh.sh

  if [[ -d "$APP_DIR" ]]; then
    rm -rf "$APP_DIR"
    echo "Removed $APP_DIR"
  fi

  for stray in index.php .htaccess; do
    if [[ -f "$WEB_ROOT/$stray" ]]; then
      mv "$WEB_ROOT/$stray" "$BACKUP_DIR/$stray.bak"
      echo "Moved stray public_html/$stray to backup"
    fi
  done

  git clone --branch "$GIT_BRANCH" --depth 1 "$GIT_REPO" "$APP_DIR"
  echo "Cloned $GIT_REPO"

  if [[ -d "$BACKUP_DIR/uploads" ]]; then
    mkdir -p "$APP_DIR/public/uploads"
    cp -a "$BACKUP_DIR/uploads/." "$APP_DIR/public/uploads/" 2>/dev/null || true
  fi

  if [[ -f "$BACKUP_DIR/firebase/service-account.json" ]]; then
    mkdir -p "$APP_DIR/config/firebase"
    cp -a "$BACKUP_DIR/firebase/service-account.json" "$APP_DIR/config/firebase/"
  fi

  echo "Backup: $BACKUP_DIR"
  exec bash /tmp/optimus-deploy-fresh.sh
fi

echo "=== OPTIMUS setup ==="
cd "$APP_DIR"

if [[ ! -f composer.json ]]; then
  echo "ERROR: Run from public_html with --wipe first."
  exit 1
fi

if [[ -f "$ENV_FILE" ]]; then
  ln -sf "$ENV_FILE" .env.local
  echo "Linked .env.local"
fi

composer install --no-dev --optimize-autoloader --no-interaction

if command -v npm >/dev/null 2>&1; then
  npm ci --omit=dev 2>/dev/null || npm install --omit=dev
  npm run build:assets
fi

if [[ ! -f public/css/app.css ]]; then
  echo "ERROR: public/css/app.css missing — pull latest git and retry."
  exit 1
fi

mkdir -p var/cache var/log public/uploads storage
chmod -R ug+rwx var storage public/uploads 2>/dev/null || true

php bin/console doctrine:migrations:migrate --no-interaction --env=prod
php bin/console cache:clear --env=prod --no-warmup
php bin/console cache:warmup --env=prod

php bin/console messenger:consume async --env=prod --limit=100 --time-limit=60 -vv || true

echo ""
echo "=== Done ==="
echo "Set hPanel Document Root to: $APP_DIR/public"
echo "Then open: https://YOUR-DOMAIN/"
