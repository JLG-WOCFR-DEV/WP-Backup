<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BJLG_PluginBootstrapTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (!class_exists('BJLG_Plugin', false)) {
            require_once dirname(__DIR__) . '/backup-jlg.php';
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (!isset($GLOBALS['bjlg_test_hooks'])) {
            $GLOBALS['bjlg_test_hooks'] = [
                'actions' => [],
                'filters' => [],
            ];
        }

        $plugin = BJLG_Plugin::instance();
        $this->resetAutoloaderState($plugin);

        if (function_exists('has_action') && has_action('plugins_loaded', [$plugin, 'bootstrap']) === false) {
            add_action('plugins_loaded', [$plugin, 'bootstrap']);
        }

        $this->resetCleanupSingleton();
    }

    protected function tearDown(): void
    {
        $this->resetCleanupSingleton();

        parent::tearDown();
    }

    public function test_destination_factory_is_loaded_when_bootstrapped(): void
    {
        $wasLoaded = class_exists(\BJLG\BJLG_Destination_Factory::class, false);

        do_action('plugins_loaded');

        $this->assertTrue(class_exists(\BJLG\BJLG_Destination_Factory::class));

        if ($wasLoaded) {
            $this->addToAssertionCount(1);

            return;
        }
    }

    public function test_autoloader_is_required_when_present(): void
    {
        $vendorDir = dirname(__DIR__) . '/vendor-bjlg';
        if (!is_dir($vendorDir)) {
            mkdir($vendorDir, 0777, true);
        }

        $autoloadFile = $vendorDir . '/autoload.php';
        $backupFile = $autoloadFile . '.bjlg-test-backup';
        $hadOriginal = is_file($autoloadFile);
        $autoloadRealPath = $hadOriginal ? realpath($autoloadFile) : false;
        $alreadyIncluded = false;

        if ($autoloadRealPath !== false) {
            foreach (get_included_files() as $included) {
                if ($included === $autoloadRealPath) {
                    $alreadyIncluded = true;
                    break;
                }
            }
        }
        $marker = uniqid('bjlg-autoload-', true);

        if ($hadOriginal) {
            rename($autoloadFile, $backupFile);
        }

        $autoloadCode = "<?php\n\$GLOBALS['bjlg_autoload_markers'][] = '$marker';\n";

        if ($hadOriginal) {
            $autoloadCode .= "return require __DIR__ . '/" . basename($backupFile) . "';\n";
        }

        file_put_contents($autoloadFile, $autoloadCode);

        unset($GLOBALS['bjlg_autoload_markers']);

        try {
            do_action('plugins_loaded');

            if ($alreadyIncluded) {
                $this->assertTrue($this->isAutoloaderFlaggedAsLoaded());

                return;
            }

            $this->assertNotEmpty($GLOBALS['bjlg_autoload_markers'] ?? []);
            $this->assertSame([$marker], array_values($GLOBALS['bjlg_autoload_markers']));
        } finally {
            if (is_file($autoloadFile)) {
                unlink($autoloadFile);
            }

            if ($hadOriginal && is_file($backupFile)) {
                rename($backupFile, $autoloadFile);
            }
        }
    }

    /**
     * @param BJLG_Plugin $plugin
     */
    private function resetAutoloaderState($plugin): void
    {
        $reflection = new ReflectionClass($plugin);

        foreach (['autoloader_loaded', 'autoloader_missing_logged'] as $property) {
            if ($reflection->hasProperty($property)) {
                $prop = $reflection->getProperty($property);
                $prop->setAccessible(true);
                $prop->setValue($plugin, false);
            }
        }

        if ($reflection->hasProperty('missing_includes_logged')) {
            $prop = $reflection->getProperty('missing_includes_logged');
            $prop->setAccessible(true);
            $prop->setValue($plugin, []);
        }
    }

    private function isAutoloaderFlaggedAsLoaded(): bool
    {
        $plugin = BJLG_Plugin::instance();
        $reflection = new ReflectionClass($plugin);

        if (!$reflection->hasProperty('autoloader_loaded')) {
            return false;
        }

        $prop = $reflection->getProperty('autoloader_loaded');
        $prop->setAccessible(true);

        return (bool) $prop->getValue($plugin);
    }

    private function resetCleanupSingleton(): void
    {
        if (!class_exists(\BJLG\BJLG_Cleanup::class, false)) {
            return;
        }

        $cleanupReflection = new ReflectionClass(\BJLG\BJLG_Cleanup::class);

        if (!$cleanupReflection->hasProperty('instance')) {
            return;
        }

        $property = $cleanupReflection->getProperty('instance');
        $property->setAccessible(true);
        $property->setValue(null, null);
    }
}
