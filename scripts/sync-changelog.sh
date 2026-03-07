#!/usr/bin/env bash
# Zeigt Commits seit dem letzten CHANGELOG-Update an,
# damit fehlende Einträge nachgetragen werden können.
#
# Usage: ./scripts/sync-changelog.sh [anzahl]
#   anzahl  Wie viele Commits zurückschauen (default: 20)

set -euo pipefail
cd "$(dirname "$0")/.."

COUNT="${1:-20}"
CHANGELOG="CHANGELOG.md"

if [[ ! -f "$CHANGELOG" ]]; then
    echo "Kein $CHANGELOG gefunden."
    exit 1
fi

LAST_CHANGELOG_COMMIT=$(git log -1 --format='%H' -- "$CHANGELOG" 2>/dev/null || echo "")

echo "=== Letzte $COUNT Commits ==="
echo ""

if [[ -n "$LAST_CHANGELOG_COMMIT" ]]; then
    echo "(Letzter CHANGELOG-Commit: $(git log -1 --format='%h %s' "$LAST_CHANGELOG_COMMIT"))"
    echo ""
    echo "Commits SEIT letztem CHANGELOG-Update:"
    echo "────────────────────────────────────────"
    git log --oneline "$LAST_CHANGELOG_COMMIT"..HEAD -- . ':!CHANGELOG.md' ':!.cursor/' ':!scripts/sync-changelog.sh' || echo "(keine)"
    echo ""
fi

echo "Alle letzten $COUNT Commits (zur Übersicht):"
echo "────────────────────────────────────────"
git log --oneline -"$COUNT"
