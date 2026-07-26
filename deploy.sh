#!/usr/bin/env bash

# Exit immediately if a command exits with a non-zero status
set -e

# ==========================================
# CONFIGURATION (Read from environment, with defaults)
# ==========================================
VPS_USER="${VPS_USER:-root}"
VPS_HOST="${VPS_HOST:-185.169.252.206}"
VPS_NC_PATH="${VPS_NC_PATH:-/opt/nextcloud-stack/nextcloud}"
APP_NAME="${APP_NAME:-projectcreatoraio}"
CONTAINER_NAME="${CONTAINER_NAME:-nextcloud-app}"

# ==========================================
# VALIDATION
# ==========================================
if [ "$VPS_HOST" = "your-vps-ip" ]; then
  echo "⚠️  Error: Please configure VPS_HOST in your .env file."
  exit 1
fi

# ==========================================
# 1. BUILD LOCAL ASSETS
# ==========================================
if command -v npm >/dev/null 2>&1 && command -v composer >/dev/null 2>&1; then
  echo "📦 Building assets locally..."
  npm run build
  composer install --no-dev --no-scripts
else
  echo "📦 Building assets using Docker (local tools not found)..."
  docker run --rm -v "$PWD:/app" -w /app node:20-alpine sh -c "npm install && npm run build"
  docker run --rm -v "$PWD:/app" -u "$(id -u):$(id -g)" composer install --no-dev --no-scripts
fi

# ==========================================
# 2. RSYNC FILES TO VPS (Syncing to custom_apps volume)
# ==========================================
echo "🚀 Syncing files to VPS volume..."
rsync -avz --delete \
  --exclude='node_modules/' \
  --exclude='.git/' \
  --exclude='.github/' \
  --exclude='tests/' \
  --exclude='cypress/' \
  --exclude='composer.json' \
  --exclude='composer.lock' \
  --exclude='package.json' \
  --exclude='package-lock.json' \
  --exclude='webpack.js' \
  --exclude='.eslint*' \
  --exclude='.stylelint*' \
  --exclude='deploy.sh' \
  --exclude='Justfile' \
  --exclude='.env' \
  ./ "$VPS_USER@$VPS_HOST:$VPS_NC_PATH/custom_apps/$APP_NAME/"

# ==========================================
# 3. DOCKER COMMANDS: SET PERMISSIONS AND RELOAD APP
# ==========================================
echo "🔧 Setting permissions and enabling app inside Docker container..."
ssh "$VPS_USER@$VPS_HOST" "
  # Set ownership of the app folder to www-data inside the container
  docker exec -u root $CONTAINER_NAME chown -R www-data:www-data /var/www/html/custom_apps/$APP_NAME

  # Reload the deployed app without triggering an App Store update.
  docker exec -u www-data $CONTAINER_NAME php occ app:disable $APP_NAME
  docker exec -u www-data $CONTAINER_NAME php occ app:enable $APP_NAME
"

echo "✅ Deployment finished successfully!"
