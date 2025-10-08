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

        $nav = $xpath->query('//nav[contains(@class, "nav-tab-wrapper")]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $nav, 'Navigation wrapper not found.');
        /** @var \DOMElement $nav */
        $this->assertSame('tablist', $nav->getAttribute('role'));

        $tabs = $xpath->query('//nav[contains(@class, "nav-tab-wrapper")]//a[@role="tab"]');
        $this->assertGreaterThan(0, $tabs->length, 'No tabs were rendered.');

        $activeTabs = 0;

        foreach ($tabs as $tabElement) {
            $this->assertInstanceOf(\DOMElement::class, $tabElement);
            /** @var \DOMElement $tab */
            $tab = $tabElement;

            $tabId = $tab->getAttribute('id');
            $panelId = $tab->getAttribute('aria-controls');

            $this->assertNotSame('', $tabId, 'Tab must have an id attribute.');
            $this->assertNotSame('', $panelId, 'Tab must reference a panel via aria-controls.');

            $panel = $xpath->query('//*[@id="' . $panelId . '"]')->item(0);
            $this->assertInstanceOf(\DOMElement::class, $panel, sprintf('Panel "%s" not found.', $panelId));
            /** @var \DOMElement $panelElement */
            $panelElement = $panel;

            $this->assertSame('tabpanel', $panelElement->getAttribute('role'));
            $this->assertSame($tabId, $panelElement->getAttribute('aria-labelledby'));

            $isSelected = $tab->getAttribute('aria-selected') === 'true';

            if ($isSelected) {
                ++$activeTabs;
                $this->assertSame('page', $tab->getAttribute('aria-current'));
                $this->assertSame('0', $tab->getAttribute('tabindex'));
                $this->assertSame('false', $panelElement->getAttribute('aria-hidden'));
                $this->assertFalse($panelElement->hasAttribute('hidden'));
            } else {
                $this->assertSame('', $tab->getAttribute('aria-current'));
                $this->assertSame('-1', $tab->getAttribute('tabindex'));
                $this->assertSame('true', $panelElement->getAttribute('aria-hidden'));
                $this->assertTrue($panelElement->hasAttribute('hidden'));
            }
        }

        $this->assertSame(1, $activeTabs, 'Exactly one tab should be selected.');
    }
}
