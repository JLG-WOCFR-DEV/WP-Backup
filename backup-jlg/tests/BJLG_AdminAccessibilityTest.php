<?php
declare(strict_types=1);

use BJLG\BJLG_Admin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-bjlg-site-context.php';
require_once __DIR__ . '/../includes/class-bjlg-admin.php';

if (!defined('BJLG_VERSION')) {
    define('BJLG_VERSION', 'test-version');
}

if (!function_exists('get_admin_page_title')) {
    function get_admin_page_title() {
        return 'Backup - JLG';
    }
}

if (!function_exists('date_i18n')) {
    function date_i18n($format, $timestamp = false, $gmt = false) {
        $timestamp = $timestamp === false ? time() : (int) $timestamp;

        return gmdate($format, $timestamp);
    }
}

if (!function_exists('wp_kses_post')) {
    function wp_kses_post($content) {
        return (string) $content;
    }
}

if (!function_exists('untrailingslashit')) {
    function untrailingslashit($value) {
        return rtrim((string) $value, "/\\");
    }
}

final class BJLG_AdminAccessibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['bjlg_test_options'] = [];
    }

    private function createXPathFromHtml(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>');
        libxml_clear_errors();

        return new \DOMXPath($document);
    }

    /**
     * @param string $methodName
     */
    private function renderSection(string $methodName): \DOMXPath
    {
        $admin = new BJLG_Admin();
        $reflection = new ReflectionClass(BJLG_Admin::class);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        ob_start();
        $method->invoke($admin);
        $html = (string) ob_get_clean();

        return $this->createXPathFromHtml($html);
    }

    private function renderAdminPageHtml(): string
    {
        $_GET['tab'] = 'history';

        $admin = new class extends BJLG_Admin {
            protected function render_section_content($section_key, $active_section, array $metrics, array $onboarding_payload) {
                echo '<div class="bjlg-test-section-stub" data-section="' . esc_attr((string) $section_key) . '">';
                if ($section_key === 'backup') {
                    echo '<div id="bjlg-backup-progress-bar"></div>';
                }
                if ($section_key === 'restore') {
                    echo '<div id="bjlg-restore-progress-bar"></div>';
                }
                if ($section_key === 'settings') {
                    BJLG\BJLG_Settings::render_settings_fields();
                }
                echo '</div>';
            }
        };
        $advancedProperty = new ReflectionProperty(BJLG_Admin::class, 'advanced_admin');
        $advancedProperty->setAccessible(true);
        $advancedProperty->setValue($admin, null);

        ob_start();
        $admin->render_admin_page();
        $html = (string) ob_get_clean();

        unset($_GET['tab']);

        return $html;
    }

    private function assertProgressAccessibility(\DOMXPath $xpath, string $progressId, string $statusId): void
    {
        $progress = $xpath->query('//*[@id="' . $progressId . '"]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $progress, 'Progress element not found.');
        /** @var \DOMElement $progressElement */
        $progressElement = $progress;

        $this->assertSame('progressbar', $progressElement->getAttribute('role'));
        $this->assertSame('0', $progressElement->getAttribute('aria-valuemin'));
        $this->assertSame('100', $progressElement->getAttribute('aria-valuemax'));
        $this->assertSame('0', $progressElement->getAttribute('aria-valuenow'));
        $this->assertSame('0%', $progressElement->getAttribute('aria-valuetext'));
        $this->assertSame('off', $progressElement->getAttribute('aria-live'));
        $this->assertSame('true', $progressElement->getAttribute('aria-atomic'));
        $this->assertSame('false', $progressElement->getAttribute('aria-busy'));
        $this->assertSame('0%', trim($progressElement->textContent));

        $status = $xpath->query('//*[@id="' . $statusId . '"]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $status, 'Status element not found.');
        /** @var \DOMElement $statusElement */
        $statusElement = $status;

        $this->assertSame('status', $statusElement->getAttribute('role'));
        $this->assertSame('polite', $statusElement->getAttribute('aria-live'));
        $this->assertSame('true', $statusElement->getAttribute('aria-atomic'));
        $this->assertSame('false', $statusElement->getAttribute('aria-busy'));
    }

    public function test_backup_section_has_accessible_progress_elements(): void
    {
        $xpath = $this->renderSection('render_backup_creation_section');
        $this->assertProgressAccessibility($xpath, 'bjlg-backup-progress-bar', 'bjlg-backup-status-text');
    }

    public function test_restore_section_has_accessible_progress_elements(): void
    {
        $xpath = $this->renderSection('render_restore_section');
        $this->assertProgressAccessibility($xpath, 'bjlg-restore-progress-bar', 'bjlg-restore-status-text');
    }

    public function test_history_section_uses_scheduler_singleton(): void
    {
        $xpath = $this->renderSection('render_history_section');
        $section = $xpath->query('//*[contains(@class, "bjlg-history")]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $section);
    }

    public function test_admin_page_uses_a_single_native_wp_admin_ui(): void
    {
        $html = $this->renderAdminPageHtml();
        $xpath = $this->createXPathFromHtml($html);

        $this->assertStringNotContainsString('bjlg-modern-admin-root', $html);
        $this->assertStringNotContainsString('bjlg-modern-admin-templates', $html);
        $this->assertStringNotContainsString('bjlg-contrast-toggle', $html);
        $this->assertStringNotContainsString('data-bjlg-theme', $html);
        $this->assertStringNotContainsString('bjlg-admin-shell__sidebar', $html);

        $wrap = $xpath->query('//*[@id="bjlg-main-content"]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $wrap);
        /** @var \DOMElement $wrap */
        $this->assertNotFalse(strpos($wrap->getAttribute('class'), 'wrap'));

        $tabs = $xpath->query('//nav[contains(@class, "nav-tab-wrapper")]/a[contains(@class, "nav-tab")]');
        $this->assertGreaterThan(1, $tabs->length, 'Native nav-tab links missing.');

        $status = $xpath->query('//*[@id="bjlg-admin-status"]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $status, 'Status region missing.');
        /** @var \DOMElement $status */
        $this->assertSame('status', $status->getAttribute('role'));
        $this->assertSame('polite', $status->getAttribute('aria-live'));

        $panels = $xpath->query('//*[@id="bjlg-admin-app"]/section[@data-section]');
        $this->assertGreaterThan(0, $panels->length, 'No section panels were rendered.');

        foreach ($panels as $panelElement) {
            $this->assertInstanceOf(\DOMElement::class, $panelElement);
            /** @var \DOMElement $panel */
            $panel = $panelElement;
            $this->assertNotSame('', $panel->getAttribute('id'));
            $this->assertNotSame('', $panel->getAttribute('data-section'));
        }

        $this->assertNotNull($xpath->query('//*[@id="bjlg-backup-progress-bar"]')->item(0));
        $this->assertNotNull($xpath->query('//*[@id="bjlg-restore-progress-bar"]')->item(0));
        $this->assertStringContainsString('option_page', $html);
        $this->assertStringContainsString('bjlg_plugin_settings', $html);
    }

    public function test_plugin_header_declares_tested_up_to_71(): void
    {
        $plugin_file = dirname(__DIR__) . '/backup-jlg.php';
        $contents = (string) file_get_contents($plugin_file);

        $this->assertMatchesRegularExpression('/^\s*\*\s*Tested up to:\s*7\.1\s*$/m', $contents);
    }
}
