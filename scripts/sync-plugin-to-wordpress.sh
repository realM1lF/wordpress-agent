#!/usr/bin/env bash

set -euo pipefail

SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET_DIR="${SOURCE_DIR}/wordpress/web/wp-content/plugins/levi-agent"

if [[ ! -d "${SOURCE_DIR}" ]]; then
  echo "Source directory not found: ${SOURCE_DIR}" >&2
  exit 1
fi

mkdir -p "${TARGET_DIR}"

rsync -a --delete \
  --exclude ".git/" \
  --exclude ".idea/" \
  --exclude ".cursor/" \
  --exclude "wordpress/" \
  --exclude "scripts/" \
  --exclude "node_modules/" \
  --exclude "vendor/" \
  --exclude "tmp/" \
  "${SOURCE_DIR}/" "${TARGET_DIR}/"

echo "Synced plugin source to test instance:"
echo "  ${SOURCE_DIR} -> ${TARGET_DIR}"
