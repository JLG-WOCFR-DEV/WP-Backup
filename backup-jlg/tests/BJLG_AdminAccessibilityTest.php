<?php
declare(strict_types=1);

use BJLG\BJLG_Admin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-bjlg-admin.php';

final class BJLG_AdminAccessibilityTest extends TestCase
{
    private function renderSection(string $methodName): DOMDocument
    {
        $admin = new BJLG_Admin();
        $method = new ReflectionMethod(BJLG_Admin::class, $methodName);
        $method->setAccessible(true);

        ob_start();
        $method->invoke($admin);
        $html = (string) ob_get_clean();

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $dom;
    }

    private function getElementById(DOMDocument $dom, string $id): ?DOMElement
    {
        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query(sprintf("//*[@id='%s']", $id));

        if (!$nodes || $nodes->length === 0) {
            return null;
        }

        $node = $nodes->item(0);

        return $node instanceof DOMElement ? $node : null;
    }

    /**
     * @param array{live:string,atomic:string,busy:string,valuetext:string} $expectations
     */
    private function assertProgressAttributes(DOMElement $element, array $expectations): void
    {
        $this->assertSame('progressbar', $element->getAttribute('role'));
        $this->assertSame('0', $element->getAttribute('aria-valuemin'));
        $this->assertSame('100', $element->getAttribute('aria-valuemax'));
        $this->assertSame('0', $element->getAttribute('aria-valuenow'));
        $this->assertSame($expectations['live'], $element->getAttribute('aria-live'));
        $this->assertSame($expectations['atomic'], $element->getAttribute('aria-atomic'));
        $this->assertSame($expectations['busy'], $element->getAttribute('aria-busy'));
        $this->assertSame($expectations['valuetext'], $element->getAttribute('aria-valuetext'));
    }

    private function assertStatusLiveRegion(DOMElement $element, string $expectedText): void
    {
        $document = $element->ownerDocument;
        $this->assertInstanceOf(DOMDocument::class, $document);

        $xpath = new DOMXPath($document);
        $nodes = $xpath->query(sprintf("//*[@id='%s']//*[@role='status']", $element->getAttribute('id')));
        $this->assertNotFalse($nodes);
        $this->assertGreaterThan(0, $nodes->length);

        $liveRegion = $nodes->item(0);
        $this->assertInstanceOf(DOMElement::class, $liveRegion);
        $this->assertSame($expectedText, trim($liveRegion->textContent));
    }

    public function test_backup_section_progress_elements_have_aria_attributes(): void
    {
        $dom = $this->renderSection('render_backup_creation_section');

        $progressBar = $this->getElementById($dom, 'bjlg-backup-progress-bar');
        $this->assertInstanceOf(DOMElement::class, $progressBar);
        $this->assertProgressAttributes($progressBar, [
            'live' => 'off',
            'atomic' => 'false',
            'busy' => 'false',
            'valuetext' => '0%',
        ]);

        $status = $this->getElementById($dom, 'bjlg-backup-status-text');
        $this->assertInstanceOf(DOMElement::class, $status);
        $this->assertProgressAttributes($status, [
            'live' => 'polite',
            'atomic' => 'true',
            'busy' => 'false',
            'valuetext' => 'Initialisation...',
        ]);
        $this->assertStatusLiveRegion($status, 'Initialisation...');
    }

    public function test_restore_section_progress_elements_have_aria_attributes(): void
    {
        $dom = $this->renderSection('render_restore_section');

        $progressBar = $this->getElementById($dom, 'bjlg-restore-progress-bar');
        $this->assertInstanceOf(DOMElement::class, $progressBar);
        $this->assertProgressAttributes($progressBar, [
            'live' => 'off',
            'atomic' => 'false',
            'busy' => 'false',
            'valuetext' => '0%',
        ]);

        $status = $this->getElementById($dom, 'bjlg-restore-status-text');
        $this->assertInstanceOf(DOMElement::class, $status);
        $this->assertProgressAttributes($status, [
            'live' => 'polite',
            'atomic' => 'true',
            'busy' => 'false',
            'valuetext' => 'Préparation...',
        ]);
        $this->assertStatusLiveRegion($status, 'Préparation...');
    }
}
