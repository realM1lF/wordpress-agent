#!/usr/bin/env bash
set -euo pipefail

# Cron wrapper: Update WordPress & WooCommerce developer documentation.
#
# Installs into crontab with:
#   ./scripts/update-docs-cron.sh --install
#
# Or run manually:
#   ./scripts/update-docs-cron.sh
#
# Schedule: Weekly on Sundays at 04:00

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"
LOG_DIR="$ROOT_DIR/logs"

mkdir -p "$LOG_DIR"

LOGFILE="$LOG_DIR/docs-update-$(date +%Y%m%d-%H%M%S).log"

if [[ "${1:-}" == "--install" ]]; then
    CRON_CMD="0 4 * * 0 $SCRIPT_DIR/update-docs-cron.sh >> $LOG_DIR/docs-update-cron.log 2>&1"

    EXISTING=$(crontab -l 2>/dev/null || true)
    if echo "$EXISTING" | grep -qF "update-docs-cron.sh"; then
        echo "Cron job already installed. Current entry:"
        echo "$EXISTING" | grep "update-docs-cron.sh"
        exit 0
    fi

    (echo "$EXISTING"; echo "$CRON_CMD") | crontab -
    echo "Cron job installed: $CRON_CMD"
    echo "Logs will be written to: $LOG_DIR/docs-update-cron.log"
    exit 0
fi

echo "=== Documentation Update: $(date) ===" | tee -a "$LOGFILE"

echo "" | tee -a "$LOGFILE"
echo "--- WordPress Docs ---" | tee -a "$LOGFILE"
python3 "$SCRIPT_DIR/fetch_wordpress_docs.py" 2>&1 | tee -a "$LOGFILE"

echo "" | tee -a "$LOGFILE"
echo "--- WooCommerce Docs ---" | tee -a "$LOGFILE"
python3 "$SCRIPT_DIR/fetch_woocommerce_docs.py" 2>&1 | tee -a "$LOGFILE"

echo "" | tee -a "$LOGFILE"
echo "=== Done: $(date) ===" | tee -a "$LOGFILE"
