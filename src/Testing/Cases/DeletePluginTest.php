<?php

namespace Levi\Agent\Testing\Cases;

use Levi\Agent\Testing\TestCase;

class DeletePluginTest extends TestCase {
    private const PLUGIN_SLUG = 'levi-test-dummy-plugin';
    private const PLUGIN_NAME = 'Levi Test Dummy Plugin';

    public function name(): string {
        return 'Delete Plugin';
    }

    public function description(): string {
        return 'Levi soll ein bestehendes Plugin loeschen.';
    }

    protected function message(): string {
        return 'Loesche bitte das Plugin "' . self::PLUGIN_NAME . '".';
    }

    protected function setUp(): void {
        $this->cleanupPlugin(self::PLUGIN_SLUG);
        $this->createDummyPlugin(self::PLUGIN_SLUG, self::PLUGIN_NAME);

        $pluginFile = self::PLUGIN_SLUG . '/' . self::PLUGIN_SLUG . '.php';
        $this->log('info', [
            'message' => 'Dummy plugin created and activated: ' . $pluginFile,
            'active' => is_plugin_active($pluginFile),
        ]);
    }

    protected function validate(): void {
        $pluginFile = self::PLUGIN_SLUG . '/' . self::PLUGIN_SLUG . '.php';

        $this->assertPluginInactive($pluginFile);

        $pluginDir = WP_PLUGIN_DIR . '/' . self::PLUGIN_SLUG;
        $dirGone = !is_dir($pluginDir);
        $mainFileGone = !file_exists($pluginDir . '/' . self::PLUGIN_SLUG . '.php');

        $this->assertTrue(
            $dirGone || $mainFileGone,
            'Plugin files removed',
            $dirGone ? 'Directory deleted' : ($mainFileGone ? 'Main file deleted' : 'Still exists at ' . $pluginDir)
        );
    }

    protected function tearDown(): void {
        $this->cleanupPlugin(self::PLUGIN_SLUG);
    }
}
