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
  --exclude 'vendor/' \
  --exclude 'tmp/' \
  --exclude 'STYLING_FILES_FLAT/' \
  --exclude 'plugin-files/' \
  --exclude 'memories/' \
  --exclude 'data/*.sqlite' \
  --exclude 'data/vector-memory.sqlite' \
  --exclude 'screenshot-*' \
  --exclude '*.zip' \
  --exclude '*.log' \
  --exclude '*.bak' \
  --exclude '*.bak2' \
  --exclude '*.old' \
  --exclude '*.orig' \
  --exclude '*.tmp' \
  --exclude '_fix_*.py' \
  --exclude '.DS_Store' \
  --exclude '._*' \
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

# Ensure data/ directory exists with .htaccess for security
mkdir -p "$PKG_DIR/data"
echo "Deny from all" > "$PKG_DIR/data/.htaccess"
touch "$PKG_DIR/data/.gitkeep"

if [[ -f "$ROOT_DIR/composer.json" && ! -d "$PKG_DIR/vendor" ]]; then
  echo "WARNING: composer.json exists but vendor/ is missing."
  echo "If runtime depends on Composer packages, run composer install before build."
fi

# --- Secret leak detection ---
SECRET_PATTERNS='(sk-or-v1-[a-zA-Z0-9]+|sk-[a-zA-Z0-9]{20,}|ghp_[a-zA-Z0-9]{36}|gho_[a-zA-Z0-9]{36}|github_pat_[a-zA-Z0-9_]{22,}|OPEN_ROUTER_API_KEY=.+|OPENAI_API_KEY=.+|ANTHROPIC_API_KEY=.+|GITHUB_TOKEN=.+|KIMI_API_KEY=.+)'
LEAKS="$(grep -rPn "$SECRET_PATTERNS" "$PKG_DIR" 2>/dev/null || true)"

if [[ -n "$LEAKS" ]]; then
  echo ""
  echo "!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!"
  echo "  ABORTED: Potential secrets/API keys found in package!"
  echo "!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!"
  echo ""
  echo "$LEAKS"
  echo ""
  echo "Fix: Remove the files above or add them to the exclude list."
  rm -rf "$PKG_DIR"
  exit 1
fi

# Check for .env files that slipped through
ENV_FILES="$(find "$PKG_DIR" -name '.env' -o -name '.env.*' 2>/dev/null || true)"
if [[ -n "$ENV_FILES" ]]; then
  echo ""
  echo "ABORTED: .env file(s) found in package:"
  echo "$ENV_FILES"
  rm -rf "$PKG_DIR"
  exit 1
fi

echo "Security check passed: No secrets or .env files detected."

(
  cd "$BUILD_DIR"
  rm -f "$ZIP_PATH"
  zip -rq "$ZIP_PATH" "$PLUGIN_SLUG"
)

echo "OK: Production ZIP created:"
echo "  $ZIP_PATH"
echo "Size: $(du -h "$ZIP_PATH" | awk '{print $1}')"
