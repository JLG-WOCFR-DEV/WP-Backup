<?php
declare(strict_types=1);

namespace {
    use BJLG\BJLG_API_Keys;
    use BJLG\BJLG_Admin;
    use BJLG\BJLG_REST_API;
    use PHPUnit\Framework\TestCase;

    if (!function_exists('esc_attr')) {
        function esc_attr($text)
        {
            return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
        }
    }

    require_once __DIR__ . '/../includes/class-bjlg-api-keys.php';
    require_once __DIR__ . '/../includes/class-bjlg-rest-api.php';
    require_once __DIR__ . '/../includes/class-bjlg-admin.php';

    final class BJLG_API_KeysTest extends TestCase
    {
        protected function setUp(): void
        {
            parent::setUp();

            $GLOBALS['bjlg_test_options'] = [];
            $GLOBALS['bjlg_test_transients'] = [];
            $GLOBALS['bjlg_test_users'] = [];
            $GLOBALS['bjlg_test_last_json_success'] = null;
            $GLOBALS['bjlg_test_last_json_error'] = null;
            $GLOBALS['bjlg_test_current_user_can'] = true;
            $GLOBALS['current_user'] = null;
            $GLOBALS['current_user_id'] = 0;
            $_POST = [];
        }

        protected function tearDown(): void
        {
            parent::tearDown();

            $_POST = [];
            $GLOBALS['bjlg_test_users'] = [];
            $GLOBALS['current_user'] = null;
            $GLOBALS['current_user_id'] = 0;
            $GLOBALS['bjlg_test_current_user_can'] = true;
        }

        private function makeUser(int $id, string $login, array $roles = ['administrator']): object
        {
            $user = (object) [
                'ID' => $id,
                'user_login' => $login,
                'user_email' => $login . '@example.com',
                'display_name' => ucfirst($login),
                'roles' => $roles,
                'allcaps' => [
                    BJLG_CAPABILITY => true,
                ],
            ];

            $GLOBALS['bjlg_test_users'][$id] = $user;

            return $user;
        }

        public function test_create_api_key_persists_entry_and_returns_plain_key(): void
        {
            $manager = new BJLG_API_Keys();
            $user = $this->makeUser(101, 'api-admin');
            wp_set_current_user($user->ID);

            $_POST = [
                'nonce' => 'test',
                'label' => 'Intégration CLI',
                'user_id' => (string) $user->ID,
            ];

            try {
                $manager->handle_create_key();
                $this->fail('Expected BJLG_Test_JSON_Response to be thrown.');
            } catch (\BJLG_Test_JSON_Response $response) {
                $this->assertNotEmpty($response->data, 'The JSON response should include data.');
                $this->assertNotEmpty($response->data['plain_key'] ?? '', 'Plain key should be returned.');
                $this->assertSame('Nouvelle clé API générée avec succès.', $response->data['message']);

                $stored = get_option('bjlg_api_keys');
                $this->assertIsArray($stored);
                $this->assertCount(1, $stored);

                $entry = $stored[0];
                $this->assertIsArray($entry);
                $this->assertArrayHasKey('id', $entry);
                $this->assertSame($user->ID, $entry['user_id']);
                $this->assertArrayHasKey('key', $entry);
                $this->assertNotSame($response->data['plain_key'], $entry['key'], 'Key should be stored hashed.');
                $this->assertTrue(wp_check_password($response->data['plain_key'], $entry['key']));
                $this->assertContains('administrator', $entry['roles']);
                $this->assertSame('Intégration CLI', $entry['label']);
                $this->assertIsInt($entry['created']);
                $this->assertIsInt($entry['last_rotated']);
                $this->assertSame($response->data['id'], $entry['id']);
            }
        }

        public function test_revoke_api_key_removes_entry_and_stats(): void
        {
            $manager = new BJLG_API_Keys();
            $user = $this->makeUser(202, 'api-owner');
            wp_set_current_user($user->ID);

            $plainKey = 'revocation-key';
            $hashed = wp_hash_password($plainKey);
            $entry = [
                'id' => 'key-123',
                'key' => $hashed,
                'label' => 'Revocation Test',
                'user_id' => $user->ID,
                'created' => time() - 100,
                'last_rotated' => time() - 50,
            ];

            update_option('bjlg_api_keys', [$entry]);
            set_transient(BJLG_REST_API::API_KEY_STATS_TRANSIENT_PREFIX . md5($hashed), ['usage_count' => 5], 0);

            $_POST = [
                'nonce' => 'test',
                'id' => 'key-123',
            ];

            try {
                $manager->handle_revoke_key();
                $this->fail('Expected BJLG_Test_JSON_Response to be thrown.');
            } catch (\BJLG_Test_JSON_Response $response) {
                $this->assertSame('La clé API a été révoquée.', $response->data['message']);
                $this->assertSame('key-123', $response->data['id']);

                $stored = get_option('bjlg_api_keys');
                $this->assertIsArray($stored);
                $this->assertCount(0, $stored, 'The key should have been removed.');
                $this->assertFalse(get_transient(BJLG_REST_API::API_KEY_STATS_TRANSIENT_PREFIX . md5($hashed)));
            }
        }

        public function test_create_api_key_requires_capability(): void
        {
            $manager = new BJLG_API_Keys();
            $user = $this->makeUser(303, 'no-cap');
            $user->allcaps = [];
            $GLOBALS['bjlg_test_users'][$user->ID] = $user;
            $GLOBALS['bjlg_test_current_user_can'] = false;
            wp_set_current_user($user->ID);

            $_POST = [
                'nonce' => 'test',
                'label' => 'Forbidden',
            ];

            try {
                $manager->handle_create_key();
                $this->fail('Expected BJLG_Test_JSON_Response to be thrown.');
            } catch (\BJLG_Test_JSON_Response $response) {
                $this->assertSame(403, $response->status_code);
                $this->assertSame('Vous n\'avez pas la permission d\'effectuer cette action.', $response->data['message']);
                $this->assertEmpty(get_option('bjlg_api_keys'), 'No key should be created without capability.');
            }
        }

        public function test_render_api_section_outputs_rows(): void
        {
            $user = $this->makeUser(404, 'render-admin');
            wp_set_current_user($user->ID);

            $entry = [
                'id' => 'render-key',
                'key' => wp_hash_password('render-plain'),
                'label' => 'Clé de rendu',
                'user_id' => $user->ID,
                'created' => time() - 200,
                'last_rotated' => time() - 100,
            ];

            update_option('bjlg_api_keys', [$entry]);

            $admin = new BJLG_Admin();
            $reflection = new \ReflectionClass(BJLG_Admin::class);
            $method = $reflection->getMethod('render_api_section');
            $method->setAccessible(true);

            ob_start();
            $method->invoke($admin);
            $html = (string) ob_get_clean();

            $this->assertStringContainsString('bjlg-api-keys-section', $html);
            $this->assertStringContainsString('Clé de rendu', $html);
            $this->assertStringContainsString('@' . $user->user_login, $html);
            $this->assertStringContainsString('Renouveler', $html);
            $this->assertStringContainsString('Révoquer', $html);
        }
    }
}
