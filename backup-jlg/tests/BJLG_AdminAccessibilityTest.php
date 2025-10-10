<?php
declare(strict_types=1);

use BJLG\BJLG_Admin;
use PHPUnit\Framework\TestCase;

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

    public function test_admin_tabs_render_accessible_tablist(): void
    {
        $_GET['tab'] = 'history';

        $admin = new BJLG_Admin();
        $advancedProperty = new ReflectionProperty(BJLG_Admin::class, 'advanced_admin');
        $advancedProperty->setAccessible(true);
        $advancedProperty->setValue($admin, null);

        ob_start();
        $admin->render_admin_page();
        $html = (string) ob_get_clean();

        unset($_GET['tab']);

        $xpath = $this->createXPathFromHtml($html);

        $app = $xpath->query('//*[@id="bjlg-admin-app"]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $app, 'React app container not found.');
        /** @var \DOMElement $app */
        $this->assertNotSame('', $app->getAttribute('data-bjlg-sections'), 'Sections payload missing.');

        $nav = $xpath->query('//*[@id="bjlg-admin-app-nav"]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $nav, 'TabPanel placeholder missing.');
        /** @var \DOMElement $nav */
        $this->assertSame('true', $nav->getAttribute('aria-hidden'));

        $panels = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " bjlg-shell-section ")]');
        $this->assertGreaterThan(0, $panels->length, 'No panels were rendered.');

        foreach ($panels as $panelElement) {
            $this->assertInstanceOf(\DOMElement::class, $panelElement);
            /** @var \DOMElement $panel */
            $panel = $panelElement;

            $panelId = $panel->getAttribute('id');
            $panelSection = $panel->getAttribute('data-section');
            $panelLabelledBy = $panel->getAttribute('aria-labelledby');

            $this->assertNotSame('', $panelId, 'Panel must have an id.');
            $this->assertNotSame('', $panelSection, 'Panel must declare its section.');
            $this->assertSame('tabpanel', $panel->getAttribute('role'));
            $this->assertNotSame('', $panelLabelledBy, 'Panel must reference its tab control.');
        }
    }
}
