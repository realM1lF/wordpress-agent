<?php

namespace Levi\Agent\AI\Tools\Generic;

use Levi\Agent\AI\Tools\AbstractTool;
use Levi\Agent\AI\Tools\Concerns\HasFileOperations;

/**
 * Generic edit tool — apply search-and-replace edits to files.
 */
class EditTool extends AbstractTool {
    use HasFileOperations;

    public function getName(): string { return 'edit'; }

    public function getDescription(): string {
        return "Editiert Dateien mit atomaren Search-and-Replace Operationen. "
            . "`path` + `replacements` (Array von {search, replace}). "
            . "Wenn auch nur eine Replacement-Operation fehlschlägt, wird NICHTS geschrieben (atomar). "
            . "Für `search` muss EXAKT der vorhandene Text angegeben werden (inkl. Leerzeichen, Zeilenumbrüche). "
            . "Nutze `read` vorher, um den exakten Inhalt zu sehen. "
            . "`dry_run=true` um zu simulieren ohne zu schreiben.";
    }

    public function getParameters(): array {
        return [
            'path' => [
                'type' => 'string',
                'description' => 'Absoluter Pfad zur Datei',
            ],
            'replacements' => [
                'type' => 'array',
                'description' => 'Array von {search: string, replace: string}',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'search' => ['type' => 'string', 'description' => 'Exakter vorhandener Text'],
                        'replace' => ['type' => 'string', 'description' => 'Neuer Text'],
                    ],
                    'required' => ['search', 'replace'],
                ],
            ],
            'dry_run' => [
                'type' => 'boolean',
                'default' => false,
                'description' => 'Nur simulieren, nicht schreiben',
            ],
        ];
    }

    public function execute(array $params): array {
        $path = (string) ($params['path'] ?? '');
        $replacements = $params['replacements'] ?? [];
        $dryRun = (bool) ($params['dry_run'] ?? false);

        if ($path === '') {
            return ['success' => false, 'error' => 'Erforderlich: path'];
        }
        if (empty($replacements) || !is_array($replacements)) {
            return ['success' => false, 'error' => 'Erforderlich: replacements (mindestens ein Element)'];
        }

        return $this->editFile($path, $replacements, $dryRun);
    }

    public function checkPermission(): bool {
        return current_user_can('edit_files') || current_user_can('activate_plugins');
    }
}
