<?php

namespace Levi\Agent\AI\Tools;

use Levi\Agent\AI\Tools\Concerns\ValidatesSyntax;
use Levi\Agent\AI\Tools\Concerns\ResolvesPluginPaths;
use Levi\Agent\AI\Tools\Concerns\WordPressCoreWhitelist;

class CheckPluginHealthTool extends AbstractTool {

    use ValidatesSyntax;
    use ResolvesPluginPaths;
    use WordPressCoreWhitelist;

    private const MAX_FILES_TO_CHECK = 50;

    public function getName(): string {
        return 'check_plugin_health';
    }

    public function getDescription(): string {
        return 'Run syntax and structural health checks on all files in a plugin. '
            . 'Validates PHP syntax, CSS brace balance, and checks that require/include targets exist. '
            . 'Use proactively after multiple edits to catch issues early.';
    }

    public function getParameters(): array {
        return [
            'plugin_slug' => [
                'type' => 'string',
                'description' => 'Plugin slug (directory in wp-content/plugins)',
                'required' => true,
            ],
        ];
    }

    public function checkPermission(): bool {
        return current_user_can('install_plugins') || current_user_can('edit_plugins');
    }

    public function execute(array $params): array {
        $slug = sanitize_title($params['plugin_slug'] ?? '');
        if ($slug === '') {
            return ['success' => false, 'error' => 'plugin_slug is required.'];
        }

        $resolved = $this->resolvePluginRoot($slug);
        if (isset($resolved['error'])) {
            return ['success' => false] + $resolved;
        }
        $pluginRoot = $resolved['root'];
        $slug = $resolved['slug'];

        $pluginRootReal = realpath($pluginRoot);
        if ($pluginRootReal === false) {
            return ['success' => false, 'error' => 'Plugin root directory could not be resolved.'];
        }

        $mainFile = $pluginRootReal . '/' . $slug . '.php';
        $issues = [];

        if (!is_file($mainFile)) {
            $issues[] = [
                'type' => 'missing_main_file',
                'file' => $slug . '.php',
                'message' => 'Main plugin file does not exist.',
            ];
        }

        $files = $this->collectFiles($pluginRootReal);
        $filesChecked = 0;
        $phpFiles = [];
        $cssFiles = [];

        foreach ($files as $filePath) {
            if ($filesChecked >= self::MAX_FILES_TO_CHECK) {
                break;
            }
            $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            if ($ext === 'php') {
                $phpFiles[] = $filePath;
            } elseif ($ext === 'css') {
                $cssFiles[] = $filePath;
            }
            $filesChecked++;
        }

        $knownFunctions = $this->buildKnownFunctionsForPlugin($pluginRootReal);

        foreach ($phpFiles as $phpFile) {
            $relativePath = str_replace($pluginRootReal . '/', '', $phpFile);
            $lint = $this->validatePhpSyntax($phpFile);
            if (($lint['valid'] ?? false) !== true) {
                $issues[] = [
                    'type' => 'php_syntax_error',
                    'file' => $relativePath,
                    'message' => $lint['error'] ?? 'PHP syntax check failed.',
                ];
            }

            $this->checkIncludeTargets($phpFile, $pluginRootReal, $relativePath, $issues);

            $refCheck = $this->checkReferenceIntegrity($phpFile, $pluginRootReal, $knownFunctions);
            if (!empty($refCheck['undefined_calls'])) {
                $issues[] = [
                    'type' => 'undefined_reference',
                    'file' => $relativePath,
                    'calls' => array_slice($refCheck['undefined_calls'], 0, 10),
                    'message' => $refCheck['warning'],
                ];
            }

            $fileContent = @file_get_contents($phpFile);
            if ($fileContent !== false) {
                $wpWarnings = $this->checkWordPressPatterns($fileContent, $relativePath);
                foreach ($wpWarnings['warnings'] ?? [] as $warning) {
                    $issues[] = [
                        'type' => 'wp_antipattern',
                        'file' => $relativePath,
                        'message' => $warning,
                    ];
                }
            }
        }

        foreach ($cssFiles as $cssFile) {
            $relativePath = str_replace($pluginRootReal . '/', '', $cssFile);
            $cssLint = $this->validateCssSyntax($cssFile);
            if (($cssLint['valid'] ?? true) === false) {
                $issues[] = [
                    'type' => 'css_syntax_error',
                    'file' => $relativePath,
                    'message' => $cssLint['error'] ?? 'CSS syntax check failed.',
                ];
            }
        }

        return [
            'success' => true,
            'plugin_slug' => $slug,
            'healthy' => empty($issues),
            'files_checked' => $filesChecked,
            'issues' => $issues,
        ];
    }

    private function collectFiles(string $rootDir): array {
        $files = [];
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($rootDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $ext = strtolower($file->getExtension());
                if (in_array($ext, ['php', 'css', 'js'], true)) {
                    $files[] = $file->getPathname();
                }
                if (count($files) >= self::MAX_FILES_TO_CHECK) {
                    break;
                }
            }
        } catch (\Exception $e) {
            // Silently handle filesystem access errors
        }
        return $files;
    }

    private function checkIncludeTargets(string $phpFile, string $pluginRoot, string $relativePath, array &$issues): void {
        $content = @file_get_contents($phpFile);
        if ($content === false) {
            return;
        }

        $patterns = [
            '~(?:require_once|require|include_once|include)\s*\(\s*__DIR__\s*\.\s*[\'"]([^\'"]+)[\'"]\s*\)~',
            '~(?:require_once|require|include_once|include)\s+__DIR__\s*\.\s*[\'"]([^\'"]+)[\'"]~',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $content, $matches)) {
                foreach ($matches[1] as $includePath) {
                    $dir = dirname($phpFile);
                    $resolvedInclude = $dir . $includePath;
                    $realResolved = realpath($resolvedInclude);
                    if ($realResolved === false && !is_file($resolvedInclude)) {
                        $issues[] = [
                            'type' => 'missing_include',
                            'file' => $relativePath,
                            'target' => ltrim($includePath, '/'),
                            'message' => "Include target does not exist: {$includePath}",
                        ];
                    }
                }
            }
        }
    }
}
