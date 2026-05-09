# Levi Agent Tests

## Test-Übersicht

| Test | Typ | Beschreibung | Laufzeit |
|------|-----|-------------|----------|
| `AgentStateTest.php` | Unit | ORPA State Machine: Transitionen, Labels, Visibility | ~10ms |
| `GenericToolContractTest.php` | Unit | 12 generische Tools: Schema, Error-Shape, Registry | ~50ms |
| `GenericToolIntegrationTest.php` | Integration | 12 generische Tools gegen echte WP-Instanz | ~500ms |
| `GenericToolWorkflowTest.php` | E2E | Multi-Step-Workflows mit generischen Tools | ~1s |
| `PerformanceBenchmarkTest.php` | Benchmark | V1 vs V2: Tokens, Latenz, Memory | ~2s |
| `ToolResponseContractTest.php` | Unit | Alte Registry (44 Tools): Schema, Error-Shape | ~100ms |
| `PluginScaffoldScenarioTest.php` | Standalone | Plugin-Scaffolding-Szenario (legacy) | variabel |

## Ausführung

### Innerhalb DDEV (empfohlen)

```bash
# State Machine
ddev exec php wp-content/plugins/levi-agent/tests/AgentStateTest.php

# Generische Tool-Contracts
ddev exec php wp-content/plugins/levi-agent/tests/GenericToolContractTest.php

# Generische Tool-Integration (braucht aktiviertes Plugin + Admin)
ddev exec php wp-content/plugins/levi-agent/tests/GenericToolIntegrationTest.php

# E2E-Workflows mit generischen Tools (braucht aktiviertes Plugin + Admin)
ddev exec php wp-content/plugins/levi-agent/tests/GenericToolWorkflowTest.php

# Performance-Benchmark (V1 vs V2)
ddev exec php wp-content/plugins/levi-agent/tests/PerformanceBenchmarkTest.php

# Alte Tool-Registry
ddev exec php wp-content/plugins/levi-agent/tests/ToolResponseContractTest.php
```

### E2E-Tests (mit echten AI-Calls)

```bash
# Alle E2E-Tests
ddev exec wp levi test run

# Einzelner Test
ddev exec wp levi test run --case=create-page --verbose
```

## Exit Codes

- `0` — Alle Assertions bestanden
- `1` — Mindestens eine Assertion fehlgeschlagen

## Neue Tests hinzufügen

1. Datei in `tests/` oder `src/Testing/Cases/` erstellen
2. Für Unit/Integration: Standalone-Script mit WordPress-Bootstrap
3. Für E2E: `TestCase` erweitern und in `TestRunner::$registry` registrieren
