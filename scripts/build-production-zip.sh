#!/usr/bin/env bash
set -euo pipefail

# Build a production-ready WordPress plugin ZIP.
#
# Usage:
#   ./scripts/build-production-zip.sh
#   ./scripts/build-production-zip.sh /absolute/path/to/plugin-root
#
# Optional environment variables:
#   PLUGIN_SLUG=levi-agent
#   MAIN_FILE=wp-levi-agent.php

ROOT_DIR="${1:-$(pwd)}"
PLUGIN_SLUG="${PLUGIN_SLUG:-levi-agent}"
MAIN_FILE="${MAIN_FILE:-wp-levi-agent.php}"

if [[ ! -d "$ROOT_DIR" ]]; then
  echo "ERROR: Root directory not found: $ROOT_DIR"
  exit 1
fi

if [[ ! -f "$ROOT_DIR/$MAIN_FILE" ]]; then
  echo "ERROR: Main plugin file not found: $ROOT_DIR/$MAIN_FILE"
  exit 1
fi

BUILD_DIR="$ROOT_DIR/.build"
PKG_DIR="$BUILD_DIR/$PLUGIN_SLUG"
DIST_DIR="$ROOT_DIR/dist"

rm -rf "$PKG_DIR"
mkdir -p "$PKG_DIR" "$DIST_DIR"

VERSION="$(awk -F': ' '/^[[:space:]]*\*[[:space:]]*Version:/{print $2; exit}' "$ROOT_DIR/$MAIN_FILE" | tr -d '\r' || true)"
if [[ -z "${VERSION}" ]]; then
  VERSION="dev-$(date +%Y%m%d%H%M%S)"
fi

ZIP_NAME="${PLUGIN_SLUG}-${VERSION}.zip"
ZIP_PATH="$DIST_DIR/$ZIP_NAME"

rsync -a \
  --delete \
  --exclude '.git/' \
  --exclude '.github/' \
  --exclude '.idea/' \
  --exclude '.vscode/' \
  --exclude '.cursor/' \
  --exclude '.build/' \
  --exclude 'dist/' \
  --exclude 'wordpress/' \
  --exclude 'tests/' \
  --exclude 'test/' \
  --exclude 'docs/' \
  --exclude 'scripts/' \
  --exclude 'node_modules/' \
  --exclude 'tmp/' \
  --exclude '*.zip' \
  --exclude '*.log' \
  --exclude '.DS_Store' \
  --exclude '.env' \
  --exclude '.env.*' \
  --exclude 'phpunit.xml*' \
  --exclude '.editorconfig' \
  --exclude '.gitattributes' \
  --exclude '.gitignore' \
  "$ROOT_DIR/" "$PKG_DIR/"

required_paths=(
  "$MAIN_FILE"
  "src"
  "assets"
  "templates"
)

for p in "${required_paths[@]}"; do
  if [[ ! -e "$PKG_DIR/$p" ]]; then
    echo "ERROR: Required runtime path missing in package: $p"
    exit 1
  fi
done

if [[ -f "$ROOT_DIR/composer.json" && ! -d "$PKG_DIR/vendor" ]]; then
  echo "WARNING: composer.json exists but vendor/ is missing."
  echo "If runtime depends on Composer packages, run composer install before build."
fi

(
  cd "$BUILD_DIR"
  rm -f "$ZIP_PATH"
  zip -rq "$ZIP_PATH" "$PLUGIN_SLUG"
)

echo "OK: Production ZIP created:"
echo "  $ZIP_PATH"
echo "Size: $(du -h "$ZIP_PATH" | awk '{print $1}')"
