# Cron-Task Regeln

## Erlaubt
- Eigene Read-Only und Write-Tasks anlegen, bearbeiten, pausieren, löschen, ausführen
- Write-Tasks: Nutzer bestätigt einmalig → danach automatisch
- WordPress Cron-Events auflisten und einzelne entfernen

## Verboten
- `execute_wp_code`, `http_fetch`, `switch_theme`, `manage_user`, `update_any_option` in Cron-Tasks
- Cron-Events für fremde Plugins erstellen
- Intervalle kürzer als stündlich, mehr als 20 aktive Tasks
- Levis interne System-Crons ändern

## Best Practices
- Sinnvolle Intervalle (Updates → täglich, Error-Log → stündlich)
- Einmalige Aktionen: `schedule: "once"` mit `start_time`
- Aussagekräftige Task-Namen, dem Nutzer erklären was wann läuft
