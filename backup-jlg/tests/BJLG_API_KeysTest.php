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
        $user = (object) [
            'ID' => 1,
            'user_login' => 'admin',
            'user_email' => 'admin@example.com',
            'allcaps' => [BJLG_CAPABILITY => true],
            'roles' => ['administrator'],
        ];
        $GLOBALS['bjlg_test_users'] = [$user->ID => $user];
        wp_set_current_user($user->ID);
        $_POST = [];
        $_REQUEST = [];
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        $GLOBALS['bjlg_test_users'] = [];
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
            $this->assertArrayHasKey('display_secret', $createdKey);
            $this->assertArrayHasKey('masked_secret', $createdKey);
            $this->assertArrayHasKey('is_secret_hidden', $createdKey);
            $this->assertFalse($createdKey['is_secret_hidden']);
            $this->assertNotEmpty($createdKey['display_secret']);
            $this->assertSame('Clé masquée', $createdKey['masked_secret']);
            $this->assertSame('Intégration Test', $createdKey['label']);
        }

        $stored = $GLOBALS['bjlg_test_options'][BJLG\BJLG_API_Keys::OPTION_NAME] ?? [];
        $this->assertCount(1, $stored);

        $record = reset($stored);
        $this->assertSame('Intégration Test', $record['label']);
        $this->assertArrayHasKey('key', $record);
        $this->assertNotEmpty($record['key']);
        $this->assertTrue(wp_check_password($createdKey['display_secret'], $record['key']));
        $this->assertSame(1, $record['user_id']);
        $this->assertSame('admin', $record['user_login']);
        $this->assertSame('admin@example.com', $record['user_email']);
        $this->assertArrayNotHasKey('display_secret', $record);
        $this->assertArrayNotHasKey('masked_secret', $record);
    }

    public function test_handle_create_key_requires_capability(): void
    {
        $GLOBALS['bjlg_test_current_user_can'] = false;
        wp_set_current_user(0);
        $GLOBALS['bjlg_test_users'] = [];

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
            'key' => wp_hash_password('SECRET123'),
            'created_at' => time() - 100,
            'last_rotated_at' => time() - 50,
            'user_id' => 1,
            'user_login' => 'admin',
            'user_email' => 'admin@example.com',
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
            'key' => wp_hash_password('ANCIENSECRET'),
            'created_at' => time() - 200,
            'last_rotated_at' => time() - 150,
            'user_id' => 1,
            'user_login' => 'admin',
            'user_email' => 'admin@example.com',
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
            $this->assertArrayHasKey('display_secret', $rotated);
            $this->assertArrayHasKey('masked_secret', $rotated);
            $this->assertArrayHasKey('is_secret_hidden', $rotated);
            $this->assertFalse($rotated['is_secret_hidden']);
            $this->assertNotSame('ANCIENSECRET', $rotated['display_secret']);
            $this->assertGreaterThan($existing['last_rotated_at'], $rotated['last_rotated_at']);
        }

        $stored = $GLOBALS['bjlg_test_options'][BJLG\BJLG_API_Keys::OPTION_NAME][$keyId] ?? null;
        $this->assertNotNull($stored);
        $this->assertTrue(wp_check_password($rotated['display_secret'], $stored['key']));
        $this->assertFalse(wp_check_password('ANCIENSECRET', $stored['key']));
        $this->assertGreaterThan($existing['last_rotated_at'], $stored['last_rotated_at']);
        $this->assertSame(1, $stored['user_id']);
    }
}
