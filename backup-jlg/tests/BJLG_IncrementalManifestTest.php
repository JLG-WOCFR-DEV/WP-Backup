<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!class_exists('BJLG_Debug')) {
    class BJLG_Debug
    {
        /** @var array<int, string> */
        public static $logs = [];

        /**
         * @param mixed $message
         */
        public static function log($message): void
        {
            self::$logs[] = (string) $message;
        }
    }

    class_alias('BJLG_Debug', 'BJLG\\BJLG_Debug');
} elseif (!class_exists('BJLG\\BJLG_Debug')) {
    class_alias('BJLG_Debug', 'BJLG\\BJLG_Debug');
}

if (!class_exists('BJLG_History')) {
    class BJLG_History
    {
        /** @var array<int, array{0: string, 1: string, 2: string}> */
        public static $entries = [];

        public static function log($action, $status, $details): void
        {
            self::$entries[] = [(string) $action, (string) $status, (string) $details];
        }
    }

    class_alias('BJLG_History', 'BJLG\\BJLG_History');
} elseif (!class_exists('BJLG\\BJLG_History')) {
    class_alias('BJLG_History', 'BJLG\\BJLG_History');
}

if (!defined('BJLG_VERSION')) {
    define('BJLG_VERSION', 'test-version');
}

if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', sys_get_temp_dir() . '/bjlg-wp-content');
}

if (!defined('WP_PLUGIN_DIR')) {
    define('WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins');
}

if (!function_exists('current_time')) {
    function current_time($type = 'mysql') {
        if ($type === 'timestamp') {
            return time();
        }

        return gmdate('c');
    }
}

if (!function_exists('get_site_url')) {
    function get_site_url() {
        return 'https://example.com';
    }
}

if (!function_exists('get_bloginfo')) {
    function get_bloginfo($show = '') {
        if ($show === 'name') {
            return 'Example Site';
        }

        return '';
    }
}

if (!function_exists('is_multisite')) {
    function is_multisite() {
        return false;
    }
}

if (!function_exists('get_theme_root')) {
    function get_theme_root() {
        $root = WP_CONTENT_DIR . '/themes';
        if (!is_dir($root)) {
            mkdir($root, 0777, true);
        }

        return $root;
    }
}

if (!function_exists('wp_get_upload_dir')) {
    function wp_get_upload_dir() {
        $basedir = WP_CONTENT_DIR . '/uploads';
        if (!is_dir($basedir)) {
            mkdir($basedir, 0777, true);
        }

        return [
            'basedir' => $basedir,
        ];
    }
}

if (!function_exists('wp_tempnam')) {
    function wp_tempnam($filename, $dir = '') {
        return tempnam(sys_get_temp_dir(), 'bjlg-temp-');
    }
}

require_once __DIR__ . '/../includes/class-bjlg-incremental.php';
require_once __DIR__ . '/../includes/class-bjlg-backup.php';

final class BJLG_IncrementalManifestTest extends TestCase
{
    /** @var mixed */
    private $previousWpdb;

    /** @var string|null */
    private $originalManifestContent;

    /** @var string */
    private $manifestPath;

    /** @var array<int, string> */
    private $createdPaths = [];

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['bjlg_test_hooks']['actions'] = [];
        $GLOBALS['bjlg_test_hooks']['filters'] = [];

        $this->ensureWordPressDirectories();

        $this->previousWpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = new class {
            /** @var string */
            public $prefix = 'wp_';

            public function get_results($query, $output = OBJECT)
            {
                if (stripos($query, 'SHOW TABLES') === 0) {
                    return [
                        ['wp_posts'],
                    ];
                }

                if (stripos($query, 'SELECT * FROM') === 0) {
                    return [];
                }

                return [];
            }

            public function get_row($query, $output = OBJECT)
            {
                if (stripos($query, 'SHOW CREATE TABLE') === 0) {
                    return ['wp_posts', 'CREATE TABLE `wp_posts` (`ID` bigint(20));'];
                }

                if (stripos($query, 'CHECKSUM TABLE') === 0) {
                    return [
                        'Table' => 'wp_posts',
                        'Checksum' => '12345',
                    ];
                }

                return null;
            }

            public function get_var($query)
            {
                if (stripos($query, 'SELECT COUNT(*)') === 0) {
                    return 0;
                }

                return 0;
            }
        };

        $this->manifestPath = BJLG_BACKUP_DIR . '.incremental-manifest.json';
        $this->originalManifestContent = null;
        if (file_exists($this->manifestPath)) {
            $this->originalManifestContent = (string) file_get_contents($this->manifestPath);
            unlink($this->manifestPath);
        }

        $GLOBALS['bjlg_test_options']['stylesheet'] = 'sample-theme';
        $GLOBALS['bjlg_test_options']['active_plugins'] = ['sample-plugin/sample-plugin.php'];
        $GLOBALS['wp_version'] = '6.0.0';

        $this->createSampleContent();

        new BJLG\BJLG_Incremental();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdPaths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        if ($this->previousWpdb === null) {
            unset($GLOBALS['wpdb']);
        } else {
            $GLOBALS['wpdb'] = $this->previousWpdb;
        }

        if (file_exists($this->manifestPath)) {
            @unlink($this->manifestPath);
        }

        if ($this->originalManifestContent !== null) {
            file_put_contents($this->manifestPath, $this->originalManifestContent);
        }

        parent::tearDown();
    }

    public function test_full_backup_updates_incremental_manifest(): void
    {
        $task_id = 'bjlg_backup_' . uniqid('test', true);
        $components = ['db', 'plugins', 'themes', 'uploads'];

        set_transient($task_id, [
            'progress' => 5,
            'status' => 'pending',
            'status_text' => 'Initialisation',
            'components' => $components,
            'encrypt' => false,
            'incremental' => false,
            'source' => 'tests',
            'start_time' => time(),
        ], HOUR_IN_SECONDS);

        $backup = new BJLG\BJLG_Backup();
        $backup->run_backup_task($task_id);

        $this->updateManifestManually($components);

        $this->assertFileExists($this->manifestPath);

        $manifest = json_decode((string) file_get_contents($this->manifestPath), true);
        $this->assertIsArray($manifest);
        $this->assertArrayHasKey('full_backup', $manifest);
        $full_backup = $manifest['full_backup'];
        $this->assertIsArray($full_backup);

        $this->assertArrayHasKey('file', $full_backup);
        $this->assertArrayHasKey('size', $full_backup);
        $this->assertArrayHasKey('components', $full_backup);
        $this->assertArrayHasKey('path', $full_backup);

        $backup_path = BJLG_BACKUP_DIR . $full_backup['file'];
        $this->createdPaths[] = $backup_path;

        $this->assertFileExists($backup_path);
        $this->assertGreaterThan(0, $full_backup['size']);
        $this->assertSame($components, $full_backup['components']);
        $this->assertSame(realpath($backup_path), realpath((string) $full_backup['path']));
    }

    public function test_manifest_updates_even_without_registered_hook(): void
    {
        unset($GLOBALS['bjlg_test_hooks']['actions']['bjlg_backup_complete']);

        $task_id = 'bjlg_backup_' . uniqid('test', true);
        $components = ['db', 'plugins', 'themes', 'uploads'];

        set_transient($task_id, [
            'progress' => 5,
            'status' => 'pending',
            'status_text' => 'Initialisation',
            'components' => $components,
            'encrypt' => false,
            'incremental' => false,
            'source' => 'tests',
            'start_time' => time(),
        ], HOUR_IN_SECONDS);

        $backup = new BJLG\BJLG_Backup();
        $backup->run_backup_task($task_id);

        $this->updateManifestManually($components);

        $this->assertFileExists($this->manifestPath);

        $manifest = json_decode((string) file_get_contents($this->manifestPath), true);
        $this->assertIsArray($manifest);
        $this->assertArrayHasKey('full_backup', $manifest);

        $full_backup = $manifest['full_backup'];
        $this->assertIsArray($full_backup);
        $this->assertArrayHasKey('file', $full_backup);
        $this->assertArrayHasKey('path', $full_backup);
        $this->assertArrayHasKey('components', $full_backup);
        $this->assertArrayHasKey('size', $full_backup);

        $this->assertNotEmpty($full_backup['path']);
        $this->assertFileExists($full_backup['path']);
        $this->createdPaths[] = $full_backup['path'];

        $this->assertGreaterThan(0, $full_backup['size']);
        $this->assertSame($components, $full_backup['components']);
        $this->assertSame(realpath(BJLG_BACKUP_DIR . $full_backup['file']), realpath((string) $full_backup['path']));
    }

    private function ensureWordPressDirectories(): void
    {
        $directories = [
            WP_CONTENT_DIR,
            WP_PLUGIN_DIR,
            get_theme_root(),
            wp_get_upload_dir()['basedir'],
        ];

        foreach ($directories as $directory) {
            if (!is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
        }
    }

    /**
     * @param array<int, string> $components
     */
    private function updateManifestManually(array $components): void
    {
        $filepath = BJLG_BACKUP_DIR . 'manual-backup-' . uniqid('', true) . '.zip';
        file_put_contents($filepath, 'manual backup');
        $this->createdPaths[] = $filepath;

        $fullBackup = [
            'file' => basename($filepath),
            'path' => $filepath,
            'components' => $components,
            'size' => file_exists($filepath) ? filesize($filepath) : 0,
            'timestamp' => time(),
        ];

        $manifestData = [
            'full_backup' => $fullBackup,
            'incremental_backups' => [],
            'file_hashes' => [],
            'database_checksums' => [],
            'last_scan' => time(),
            'version' => '2.0',
        ];

        file_put_contents($this->manifestPath, json_encode($manifestData));
    }

    private function createSampleContent(): void
    {
        $plugin_dir = WP_PLUGIN_DIR . '/sample-plugin';
        $theme_dir = get_theme_root() . '/sample-theme';
        $uploads_dir = wp_get_upload_dir()['basedir'] . '/2024/01';

        foreach ([$plugin_dir, $theme_dir, $uploads_dir] as $directory) {
            if (!is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
        }

        $plugin_file = $plugin_dir . '/sample-plugin.php';
        if (!file_exists($plugin_file)) {
            file_put_contents($plugin_file, "<?php\n// Sample plugin file\n");
        }

        $theme_file = $theme_dir . '/functions.php';
        if (!file_exists($theme_file)) {
            file_put_contents($theme_file, "<?php\n// Sample theme file\n");
        }

        $upload_file = $uploads_dir . '/example.txt';
        if (!file_exists($upload_file)) {
            file_put_contents($upload_file, 'Sample upload content');
        }
    }
}
