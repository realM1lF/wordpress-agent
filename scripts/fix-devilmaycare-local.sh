#!/usr/bin/env bash
# Fix für devilmaycare.local auf Linux (Arch)
# Problem: mdns_minimal [NOTFOUND=return] verhindert .local-Auflösung aus /etc/hosts
# Lösung: files vor mdns_minimal in nsswitch.conf
# Ausführen: ./scripts/fix-devilmaycare-local.sh

set -e
echo "=== Fix devilmaycare.local ==="
sudo cp /etc/nsswitch.conf /etc/nsswitch.conf.bak
sudo sed -i 's/mdns_minimal \[NOTFOUND=return\] resolve files/files mdns_minimal [NOTFOUND=return] resolve/' /etc/nsswitch.conf
echo "Erledigt. Test: getent hosts devilmaycare.local"
