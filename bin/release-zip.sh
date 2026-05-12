#!/usr/bin/env bash
set -euo pipefail

PLUGIN_SLUG="mcp-site-manager"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

VERSION="$(grep -E "^\s*\*\s*Version:" "${PLUGIN_SLUG}.php" | head -n1 | awk -F: '{print $2}' | tr -d ' ')"
if [[ -z "$VERSION" ]]; then
  echo "Could not detect plugin version from ${PLUGIN_SLUG}.php" >&2
  exit 1
fi

DIST_DIR="${ROOT_DIR}/dist"
STAGE_DIR="${DIST_DIR}/${PLUGIN_SLUG}"
ZIP_FILE="${DIST_DIR}/${PLUGIN_SLUG}-${VERSION}.zip"

echo "==> Building assets"
npm run build

echo "==> Installing production composer dependencies"
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction

echo "==> Staging files in ${STAGE_DIR}"
rm -rf "$DIST_DIR"
mkdir -p "$STAGE_DIR"

rsync -a \
  --exclude='.git/' \
  --exclude='.github/' \
  --exclude='.gitignore' \
  --exclude='.distignore' \
  --exclude='.DS_Store' \
  --exclude='.gstack/' \
  --exclude='.claude/' \
  --exclude='.wordpress-org/' \
  --exclude='.wp-env.json' \
  --exclude='node_modules/' \
  --exclude='dist/' \
  --exclude='src/' \
  --exclude='tests/' \
  --exclude='docs/' \
  --exclude='bin/' \
  --exclude='package.json' \
  --exclude='package-lock.json' \
  --exclude='composer.json' \
  --exclude='composer.lock' \
  --exclude='webpack.config.js' \
  --exclude='phpunit.xml.dist' \
  --exclude='.phpunit.result.cache' \
  ./ "$STAGE_DIR/"

echo "==> Creating ${ZIP_FILE}"
( cd "$DIST_DIR" && zip -rq "$(basename "$ZIP_FILE")" "$PLUGIN_SLUG" )

echo "==> Restoring dev composer dependencies"
composer install --prefer-dist --no-interaction

echo "==> Done: ${ZIP_FILE}"
