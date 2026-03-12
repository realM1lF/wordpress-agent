<?php

namespace Levi\Agent\Testing\Cases;

use Levi\Agent\Testing\TestCase;

class CreatePageTest extends TestCase {
    private const PAGE_TITLE = 'Levi Testseite E2E';

    public function name(): string {
        return 'Create Page';
    }

    public function description(): string {
        return 'Levi soll eine neue Seite mit einem bestimmten Titel anlegen.';
    }

    protected function message(): string {
        return 'Lege bitte eine neue Seite mit dem Titel "' . self::PAGE_TITLE . '" an.';
    }

    protected function setUp(): void {
        $this->cleanupPage(self::PAGE_TITLE);
    }

    protected function validate(): void {
        $this->assertPageExistsByTitle(self::PAGE_TITLE);

        $pages = get_posts([
            'post_type' => 'page',
            'title' => self::PAGE_TITLE,
            'post_status' => 'any',
            'numberposts' => 1,
        ]);

        if (!empty($pages)) {
            $page = $pages[0];

            $this->assertTrue(
                in_array($page->post_status, ['publish', 'draft'], true),
                'Page status is publish or draft',
                "Status: {$page->post_status}"
            );

            $this->assertEquals(
                self::PAGE_TITLE,
                $page->post_title,
                'Page title matches exactly'
            );
        }
    }

    protected function tearDown(): void {
        $this->cleanupPage(self::PAGE_TITLE);
    }
}
