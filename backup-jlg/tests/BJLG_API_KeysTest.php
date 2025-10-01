<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-bjlg-api-keys.php';

final class BJLG_API_KeysTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['bjlg_test_current_user_can'] = true;
        $GLOBALS['bjlg_test_options'] = [];
        $_POST = [];
        $_REQUEST = [];
    }

    public function test_handle_create_key_persists_option(): void
    {
        $service = new BJLG\BJLG_API_Keys();

        $_POST['label'] = 'Intégration Test';
        $_POST['nonce'] = 'test-nonce';

        try {
            $service->handle_create_key();
            $this->fail('Expected BJLG_Test_JSON_Response to be thrown.');
        } catch (BJLG_Test_JSON_Response $response) {
            $this->assertIsArray($response->data);
            $this->assertSame('Clé API créée avec succès.', $response->data['message']);
            $this->assertArrayHasKey('key', $response->data);
            $this->assertArrayHasKey('nonce', $response->data);
            $this->assertNotEmpty($response->data['nonce']);

            $createdKey = $response->data['key'];
            $this->assertArrayHasKey('id', $createdKey);
            $this->assertArrayHasKey('secret', $createdKey);
            $this->assertSame('Intégration Test', $createdKey['label']);
        }

        $stored = $GLOBALS['bjlg_test_options'][BJLG\BJLG_API_Keys::OPTION_NAME] ?? [];
        $this->assertCount(1, $stored);

        $record = reset($stored);
        $this->assertSame('Intégration Test', $record['label']);
        $this->assertArrayHasKey('secret', $record);
        $this->assertNotEmpty($record['secret']);
    }

    public function test_handle_create_key_requires_capability(): void
    {
        $GLOBALS['bjlg_test_current_user_can'] = false;

        $service = new BJLG\BJLG_API_Keys();

        $_POST['nonce'] = 'test-nonce';

        try {
            $service->handle_create_key();
            $this->fail('Expected BJLG_Test_JSON_Response to be thrown.');
        } catch (BJLG_Test_JSON_Response $response) {
            $this->assertSame(403, $response->status_code);
            $this->assertSame(['message' => 'Permission refusée.'], $response->data);
        }
    }

    public function test_handle_revoke_key_removes_record(): void
    {
        $service = new BJLG\BJLG_API_Keys();

        $keyId = 'cle123';
        $existing = [
            'id' => $keyId,
            'label' => 'Clé existante',
            'secret' => 'SECRET123',
            'created_at' => time() - 100,
            'last_rotated_at' => time() - 50,
        ];

        $GLOBALS['bjlg_test_options'][BJLG\BJLG_API_Keys::OPTION_NAME] = [
            $keyId => $existing,
        ];

        $_POST['nonce'] = 'test-nonce';
        $_POST['key_id'] = $keyId;

        try {
            $service->handle_revoke_key();
            $this->fail('Expected BJLG_Test_JSON_Response to be thrown.');
        } catch (BJLG_Test_JSON_Response $response) {
            $this->assertSame('Clé API révoquée.', $response->data['message']);
            $this->assertSame($keyId, $response->data['key_id']);
            $this->assertArrayHasKey('nonce', $response->data);
        }

        $stored = $GLOBALS['bjlg_test_options'][BJLG\BJLG_API_Keys::OPTION_NAME] ?? [];
        $this->assertArrayNotHasKey($keyId, $stored);
    }

    public function test_handle_rotate_key_updates_secret_and_timestamp(): void
    {
        $service = new BJLG\BJLG_API_Keys();

        $keyId = 'cle456';
        $existing = [
            'id' => $keyId,
            'label' => 'Rotation',
            'secret' => 'ANCIENSECRET',
            'created_at' => time() - 200,
            'last_rotated_at' => time() - 150,
        ];

        $GLOBALS['bjlg_test_options'][BJLG\BJLG_API_Keys::OPTION_NAME] = [
            $keyId => $existing,
        ];

        $_POST['nonce'] = 'test-nonce';
        $_POST['key_id'] = $keyId;

        try {
            $service->handle_rotate_key();
            $this->fail('Expected BJLG_Test_JSON_Response to be thrown.');
        } catch (BJLG_Test_JSON_Response $response) {
            $this->assertSame('Clé API régénérée.', $response->data['message']);
            $this->assertArrayHasKey('key', $response->data);

            $rotated = $response->data['key'];
            $this->assertSame($keyId, $rotated['id']);
            $this->assertNotSame('ANCIENSECRET', $rotated['secret']);
            $this->assertGreaterThan($existing['last_rotated_at'], $rotated['last_rotated_at']);
        }

        $stored = $GLOBALS['bjlg_test_options'][BJLG\BJLG_API_Keys::OPTION_NAME][$keyId] ?? null;
        $this->assertNotNull($stored);
        $this->assertNotSame('ANCIENSECRET', $stored['secret']);
        $this->assertGreaterThan($existing['last_rotated_at'], $stored['last_rotated_at']);
    }
}
