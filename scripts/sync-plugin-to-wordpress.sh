#!/usr/bin/env bash

set -euo pipefail

SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET_DIR="${SOURCE_DIR}/wordpress/web/wp-content/plugins/levi-agent"
WP_PROJECT_DIR="${SOURCE_DIR}/wordpress"

DRY_RUN=0
ACTIVATE=0

for arg in "$@"; do
  case "${arg}" in
    --dry-run)
      DRY_RUN=1
      ;;
    --activate)
      ACTIVATE=1
      ;;
    -h|--help)
      cat <<'USAGE'
Usage: scripts/sync-plugin-to-wordpress.sh [--dry-run] [--activate]

Options:
  --dry-run   Show what would be synced without writing files
  --activate  Activate plugin in local ddev instance after sync
USAGE
      exit 0
      ;;
    *)
      echo "Unknown option: ${arg}" >&2
      exit 1
      ;;
  esac
done

if [[ ! -d "${SOURCE_DIR}" ]]; then
  echo "Source directory not found: ${SOURCE_DIR}" >&2
  exit 1
fi

if ! command -v rsync >/dev/null 2>&1; then
  echo "rsync is required but not installed." >&2
  exit 1
fi

mkdir -p "${TARGET_DIR}"

RSYNC_FLAGS=(-a --delete)
if [[ "${DRY_RUN}" -eq 1 ]]; then
  RSYNC_FLAGS+=(--dry-run --itemize-changes)
fi

# Keep runtime data in the target installation.
rsync "${RSYNC_FLAGS[@]}" \
  --filter='P data/' \
  --exclude ".git" \
  --exclude ".git/" \
  --exclude ".idea/" \
  --exclude ".cursor/" \
  --exclude "wordpress/" \
  --exclude "tests/" \
  --exclude "docs/" \
  --exclude "screenshot-*" \
  --exclude "node_modules/" \
  --exclude "vendor/" \
  --exclude "tmp/" \
  "${SOURCE_DIR}/" "${TARGET_DIR}/"

echo "Sync finished:"
echo "  ${SOURCE_DIR} -> ${TARGET_DIR}"

# Invalidate PHP-FPM OPcache so DDEV picks up changed files immediately
if command -v ddev >/dev/null 2>&1 && [[ -d "${WP_PROJECT_DIR}" ]]; then
  echo "Flushing OPcache..."
  (
    cd "${WP_PROJECT_DIR}"
    OC_FILE="/var/www/html/web/_opcache_reset.php"
    ddev exec bash -c "cat > ${OC_FILE} <<'OPCPHP'
<?php opcache_reset(); echo 'opcache_cleared';
OPCPHP" 2>/dev/null
    RESULT=$(ddev exec curl -sf http://localhost/_opcache_reset.php 2>/dev/null) || true
    ddev exec rm -f "${OC_FILE}" 2>/dev/null
    if [[ "${RESULT}" == *"opcache_cleared"* ]]; then
      echo "  OPcache cleared"
    else
      echo "  OPcache reset skipped (not active or unreachable)"
    fi
  ) || true
fi

if [[ "${ACTIVATE}" -eq 1 ]]; then
  if ! command -v ddev >/dev/null 2>&1; then
    echo "ddev not found, skipping plugin activation."
    exit 0
  fi

  if [[ ! -d "${WP_PROJECT_DIR}" ]]; then
    echo "WordPress project directory not found: ${WP_PROJECT_DIR}" >&2
    exit 1
  fi

  (
    cd "${WP_PROJECT_DIR}"
    ddev wp plugin activate levi-agent || true
    ddev wp plugin status levi-agent || true
  )
fi
