<?php
declare(strict_types=1);

namespace BJLG {
    if (!class_exists(__NAMESPACE__ . '\\BJLG_Health_Check')) {
        class BJLG_Health_Check
        {
            public function export_health_report(): string
            {
                return "Rapport de test";
            }
        }
    }
}

namespace {

require_once __DIR__ . '/../includes/class-bjlg-backup.php';
require_once __DIR__ . '/../includes/class-bjlg-actions.php';
require_once __DIR__ . '/../includes/class-bjlg-diagnostics.php';

final class BJLG_DiagnosticsSupportPackageTest extends \PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['bjlg_test_current_user_can'] = true;
        $GLOBALS['bjlg_test_transients'] = [];
        $GLOBALS['bjlg_test_last_json_success'] = null;
        $GLOBALS['bjlg_test_last_json_error'] = null;
        $GLOBALS['current_user'] = null;
        $GLOBALS['current_user_id'] = 0;
        \BJLG\BJLG_Debug::$logs = [];
        $_POST = [];

        foreach (glob(bjlg_get_backup_directory() . 'support-package-*.zip') ?: [] as $existing) {
            @unlink($existing);
        }
    }

    public function test_handle_generate_support_package_returns_download_payload(): void
    {
        $diagnostics = new \BJLG\BJLG_Diagnostics();

        $_POST['nonce'] = 'test-nonce';

        try {
            $diagnostics->handle_generate_support_package();
            $this->fail('Expected BJLG_Test_JSON_Response to be thrown.');
        } catch (BJLG_Test_JSON_Response $response) {
            $this->assertSame('JSON response', $response->getMessage());
            $lastSuccess = $GLOBALS['bjlg_test_last_json_success'];
            $this->assertIsArray($lastSuccess);
            $this->assertArrayHasKey('data', $lastSuccess);

            $data = $lastSuccess['data'];
            $this->assertIsArray($data);
            $this->assertArrayHasKey('download_url', $data);
            $this->assertArrayHasKey('filename', $data);
            $this->assertArrayHasKey('size', $data);
            $this->assertSame('Pack de support généré avec succès.', $data['message']);

            $downloadUrl = $data['download_url'];
            $this->assertIsString($downloadUrl);
            $this->assertNotEmpty($downloadUrl);

            parse_str((string) parse_url($downloadUrl, PHP_URL_QUERY), $query);
            $this->assertArrayHasKey('token', $query);
            $token = $query['token'];

            $transientKey = 'bjlg_download_' . $token;
            $this->assertArrayHasKey($transientKey, $GLOBALS['bjlg_test_transients']);

            $payload = $GLOBALS['bjlg_test_transients'][$transientKey];
            $this->assertArrayHasKey('file', $payload);
            $this->assertArrayHasKey('delete_after_download', $payload);
            $this->assertTrue($payload['delete_after_download']);

            $filePath = $payload['file'];
            $this->assertIsString($filePath);
            $this->assertFileExists($filePath);
            $this->assertGreaterThan(0, filesize($filePath));

            @unlink($filePath);
            unset($GLOBALS['bjlg_test_transients'][$transientKey]);
        }
    }

    public function test_handle_generate_support_package_requires_capability(): void
    {
        $GLOBALS['bjlg_test_current_user_can'] = false;

        $diagnostics = new \BJLG\BJLG_Diagnostics();
        $_POST['nonce'] = 'test-nonce';

        try {
            $diagnostics->handle_generate_support_package();
            $this->fail('Expected BJLG_Test_JSON_Response to be thrown.');
        } catch (BJLG_Test_JSON_Response $response) {
            $this->assertSame(['message' => 'Permission refusée.'], $response->data);

            $lastError = $GLOBALS['bjlg_test_last_json_error'];
            $this->assertIsArray($lastError);
            $this->assertSame(['message' => 'Permission refusée.'], $lastError['data']);
        } finally {
            $GLOBALS['bjlg_test_current_user_can'] = true;
        }
    }
}

}
