<?php
declare(strict_types=1);

namespace BJLG {
    if (!function_exists(__NAMESPACE__ . '\\date')) {
        function date($format, $timestamp = null)
        {
            if ($timestamp === null && isset($GLOBALS['bjlg_test_mock_now'])) {
                $timestamp = (int) $GLOBALS['bjlg_test_mock_now'];
            }

            if ($timestamp !== null) {
                return \date($format, (int) $timestamp);
            }

            return \date($format);
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\strtotime')) {
        function strtotime($datetime, $baseTimestamp = null)
        {
            if ($baseTimestamp === null && isset($GLOBALS['bjlg_test_mock_now'])) {
                $baseTimestamp = (int) $GLOBALS['bjlg_test_mock_now'];
            }

            if ($baseTimestamp !== null) {
                return \strtotime($datetime, (int) $baseTimestamp);
            }

            return \strtotime($datetime);
        }
    }
}

namespace {
    require_once __DIR__ . '/../includes/class-bjlg-client-ip-helper.php';
    require_once __DIR__ . '/../includes/class-bjlg-history.php';

    if (!function_exists('dbDelta')) {
        function dbDelta($sql)
        {
            if (!isset($GLOBALS['bjlg_test_dbdelta'])) {
                $GLOBALS['bjlg_test_dbdelta'] = [];
            }

            $GLOBALS['bjlg_test_dbdelta'][] = (string) $sql;

            return [];
        }
    }

    /**
     * Minimal in-memory replacement for the WordPress $wpdb object used by BJLG_History.
     */
    class BJLG_Test_History_WPDB
    {
        /** @var string */
        public $prefix = 'wp_';

        /** @var string */
        public $last_prepared_query = '';

        /** @var string|null */
        public $last_insert_table = null;

        /** @var array<int, array<string, mixed>> */
        private $rows;

        /**
         * @param array<int, array<string, mixed>> $rows
         */
        public function __construct(array $rows)
        {
            $this->rows = array_values($rows);
        }

        /**
         * @param array<int, array<string, mixed>> $rows
         */
        public function set_rows(array $rows): void
        {
            $this->rows = array_values($rows);
        }

        /**
         * @return array<int, array<string, mixed>>
         */
        public function get_rows(): array
        {
            return $this->rows;
        }

        /**
         * @param string $query
         * @param mixed ...$args
         * @return array{query: string, args: array<int, mixed>}
         */
        public function prepare($query, ...$args): array
        {
            $this->last_prepared_query = (string) $query;

            return [
                'query' => (string) $query,
                'args'  => $args,
            ];
        }

        public function insert($table, $data, $formats)
        {
            $this->last_insert_table = (string) $table;

            $this->rows[] = array_merge(['id' => count($this->rows) + 1], (array) $data);

            return 1;
        }

        public function get_charset_collate(): string
        {
            return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        }

        public function get_blog_prefix($blog_id = null): string
        {
            $blog_id = $blog_id !== null ? (int) $blog_id : 0;

            if ($blog_id <= 0) {
                return 'wp_';
            }

            return 'wp_' . $blog_id . '_';
        }

        /**
         * @param array{query: string, args: array<int, mixed>}|string $prepared
         */
        public function get_var($prepared)
        {
            [$query, $args] = $this->extract_query_and_args($prepared);

            if (strpos($query, 'COUNT(*)') !== false) {
                $limit = $args[0] ?? '1970-01-01 00:00:00';

                return count($this->filter_after_date((string) $limit));
            }

            return null;
        }

        /**
         * @param array{query: string, args: array<int, mixed>}|string $prepared
         * @param string|int $output
         * @return array<int, array<string, mixed>>
         */
        public function get_results($prepared, $output = OBJECT): array
        {
            [$query, $args] = $this->extract_query_and_args($prepared);

            if (strpos($query, 'GROUP BY status') !== false) {
                $limit = $args[0] ?? '1970-01-01 00:00:00';

                return $this->group_by('status', $this->filter_after_date((string) $limit));
            }

            if (strpos($query, 'GROUP BY action_type') !== false) {
                $limit = $args[0] ?? '1970-01-01 00:00:00';

                return $this->group_by('action_type', $this->filter_after_date((string) $limit), 10);
            }

            if (strpos($query, 'GROUP BY user_id') !== false) {
                $limit = $args[0] ?? '1970-01-01 00:00:00';

                $rows = array_filter(
                    $this->filter_after_date((string) $limit),
                    static function ($row): bool {
                        return isset($row['user_id']) && $row['user_id'] !== null;
                    }
                );

                return $this->group_by('user_id', $rows, 5);
            }

            if (strpos($query, 'GROUP BY HOUR') !== false) {
                $limit = $args[0] ?? '1970-01-01 00:00:00';
                $rows = $this->filter_after_date((string) $limit);

                $by_hour = [];
                foreach ($rows as $row) {
                    $hour = (int) \date('G', \strtotime((string) $row['timestamp']));
                    $by_hour[$hour] = ($by_hour[$hour] ?? 0) + 1;
                }

                arsort($by_hour);

                $results = [];
                foreach ($by_hour as $hour => $count) {
                    $results[] = [
                        'hour'  => $hour,
                        'count' => $count,
                    ];
                }

                return $results;
            }

            if (strpos($query, 'WHERE action_type LIKE %s') !== false) {
                $like_term = isset($args[0]) ? (string) $args[0] : '';
                $needle = str_replace('\\', '', trim($like_term, '%'));
                $limit = isset($args[2]) ? (int) $args[2] : 50;

                $matches = array_filter(
                    $this->rows,
                    static function ($row) use ($needle): bool {
                        $action = (string) ($row['action_type'] ?? '');
                        $details = (string) ($row['details'] ?? '');

                        return stripos($action, $needle) !== false || stripos($details, $needle) !== false;
                    }
                );

                usort(
                    $matches,
                    static function ($a, $b): int {
                        return strcmp((string) $b['timestamp'], (string) $a['timestamp']);
                    }
                );

                return array_slice(array_values($matches), 0, $limit);
            }

            if (strpos($query, 'SELECT *') !== false) {
                $rows = $this->rows;
                $arg_index = 0;

                if (strpos($query, 'action_type = %s') !== false) {
                    $value = $args[$arg_index++] ?? '';
                    $rows = array_filter(
                        $rows,
                        static function ($row) use ($value): bool {
                            return (string) ($row['action_type'] ?? '') === (string) $value;
                        }
                    );
                }

                if (strpos($query, 'status = %s') !== false) {
                    $value = $args[$arg_index++] ?? '';
                    $rows = array_filter(
                        $rows,
                        static function ($row) use ($value): bool {
                            return (string) ($row['status'] ?? '') === (string) $value;
                        }
                    );
                }

                if (strpos($query, 'user_id = %d') !== false) {
                    $value = (int) ($args[$arg_index++] ?? 0);
                    $rows = array_filter(
                        $rows,
                        static function ($row) use ($value): bool {
                            return (int) ($row['user_id'] ?? 0) === $value;
                        }
                    );
                }

                if (strpos($query, 'timestamp >= %s') !== false) {
                    $value = $args[$arg_index++] ?? '1970-01-01 00:00:00';
                    $rows = array_filter(
                        $rows,
                        static function ($row) use ($value): bool {
                            return strcmp((string) $row['timestamp'], (string) $value) >= 0;
                        }
                    );
                }

                if (strpos($query, 'timestamp <= %s') !== false) {
                    $value = $args[$arg_index++] ?? '9999-12-31 23:59:59';
                    $rows = array_filter(
                        $rows,
                        static function ($row) use ($value): bool {
                            return strcmp((string) $row['timestamp'], (string) $value) <= 0;
                        }
                    );
                }

                $limit = $args[$arg_index] ?? 50;

                usort(
                    $rows,
                    static function ($a, $b): int {
                        return strcmp((string) $b['timestamp'], (string) $a['timestamp']);
                    }
                );

                return array_slice(array_values($rows), 0, (int) $limit);
            }

            return [];
        }

        /**
         * @param array{query: string, args: array<int, mixed>}|string $prepared
         * @return array<string, mixed>|null
         */
        public function get_row($prepared)
        {
            $results = $this->get_results($prepared, ARRAY_A);

            return $results[0] ?? null;
        }

        /**
         * @param array{query: string, args: array<int, mixed>}|string $prepared
         * @return int
         */
        public function query($prepared): int
        {
            [$query, $args] = $this->extract_query_and_args($prepared);

            if (strpos($query, 'DELETE FROM') !== false) {
                $cutoff = $args[0] ?? '9999-12-31 23:59:59';
                $remaining = [];
                $deleted = 0;

                foreach ($this->rows as $row) {
                    if (strcmp((string) $row['timestamp'], (string) $cutoff) < 0) {
                        $deleted++;
                        continue;
                    }

                    $remaining[] = $row;
                }

                $this->rows = $remaining;

                return $deleted;
            }

            return 0;
        }

        public function esc_like($text): string
        {
            return addcslashes((string) $text, '%_');
        }

        /**
         * @param array{query: string, args: array<int, mixed>}|string $prepared
         * @return array{0: string, 1: array<int, mixed>}
         */
        private function extract_query_and_args($prepared): array
        {
            if (is_array($prepared) && isset($prepared['query'], $prepared['args'])) {
                return [$prepared['query'], $prepared['args']];
            }

            return [(string) $prepared, []];
        }

        /**
         * @param string $field
         * @param array<int, array<string, mixed>> $rows
         * @param int|null $limit
         * @return array<int, array<string, mixed>>
         */
        private function group_by(string $field, array $rows, $limit = null): array
        {
            $counts = [];

            foreach ($rows as $row) {
                $key = $row[$field] ?? null;

                if ($key === null) {
                    continue;
                }

                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }

            arsort($counts);

            $results = [];
            foreach ($counts as $value => $count) {
                $results[] = [
                    $field => $value,
                    'count' => $count,
                ];
            }

            if ($limit !== null) {
                return array_slice($results, 0, (int) $limit);
            }

            return $results;
        }

        /**
         * @param string $date
         * @return array<int, array<string, mixed>>
         */
        private function filter_after_date(string $date): array
        {
            return array_values(
                array_filter(
                    $this->rows,
                    static function ($row) use ($date): bool {
                        return strcmp((string) $row['timestamp'], $date) > 0;
                    }
                )
            );
        }
    }

    final class BJLG_HistoryTest extends \PHPUnit\Framework\TestCase
    {
        protected function setUp(): void
        {
            parent::setUp();

            $GLOBALS['bjlg_test_mock_now'] = \strtotime('2024-02-15 12:00:00');
            $GLOBALS['bjlg_test_users'] = [
                1 => (object) [
                    'ID'           => 1,
                    'display_name' => 'Admin One',
                ],
                2 => (object) [
                    'ID'           => 2,
                    'display_name' => 'Editor Two',
                ],
            ];
        }

        protected function tearDown(): void
        {
            unset($GLOBALS['wpdb'], $GLOBALS['bjlg_test_mock_now']);
            parent::tearDown();
        }

        public function test_get_stats_aggregates_recent_activity(): void
        {
            $rows = [
                [
                    'id'          => 1,
                    'timestamp'   => '2024-02-15 11:15:00',
                    'action_type' => 'backup_created',
                    'status'      => 'success',
                    'details'     => 'Nightly backup',
                    'user_id'     => 1,
                    'ip_address'  => '192.0.2.10',
                ],
                [
                    'id'          => 2,
                    'timestamp'   => '2024-02-12 09:30:00',
                    'action_type' => 'restore_run',
                    'status'      => 'failed',
                    'details'     => 'Restore failed',
                    'user_id'     => 2,
                    'ip_address'  => '192.0.2.11',
                ],
                [
                    'id'          => 3,
                    'timestamp'   => '2024-02-10 11:45:00',
                    'action_type' => 'cleanup_task_finished',
                    'status'      => 'info',
                    'details'     => 'Cleanup completed',
                    'user_id'     => 1,
                    'ip_address'  => '192.0.2.12',
                ],
                [
                    'id'          => 4,
                    'timestamp'   => '2024-01-10 12:00:00',
                    'action_type' => 'backup_created',
                    'status'      => 'success',
                    'details'     => 'Old backup',
                    'user_id'     => 1,
                    'ip_address'  => '192.0.2.13',
                ],
            ];

            $GLOBALS['wpdb'] = new BJLG_Test_History_WPDB($rows);

            $stats = \BJLG\BJLG_History::get_stats('week');

            self::assertSame(3, (int) $stats['total_actions']);
            self::assertSame(1, (int) $stats['success']);
            self::assertSame(1, (int) $stats['failed']);
            self::assertSame(1, (int) $stats['info']);
            self::assertSame([
                'backup_created'        => 1,
                'restore_run'           => 1,
                'cleanup_task_finished' => 1,
            ], $stats['by_action']);
            self::assertSame([
                'Admin One'  => 2,
                'Editor Two' => 1,
            ], $stats['by_user']);
            self::assertSame('11:00', $stats['most_active_hour']);
        }

        public function test_search_returns_matching_rows(): void
        {
            $rows = [
                [
                    'id'          => 5,
                    'timestamp'   => '2024-02-14 08:00:00',
                    'action_type' => 'backup_created',
                    'status'      => 'success',
                    'details'     => 'Scheduled backup',
                    'user_id'     => 1,
                    'ip_address'  => '198.51.100.1',
                ],
                [
                    'id'          => 6,
                    'timestamp'   => '2024-02-14 10:00:00',
                    'action_type' => 'restore_run',
                    'status'      => 'success',
                    'details'     => 'Manual restore completed',
                    'user_id'     => 2,
                    'ip_address'  => '198.51.100.2',
                ],
                [
                    'id'          => 7,
                    'timestamp'   => '2024-02-13 09:30:00',
                    'action_type' => 'backup_deleted',
                    'status'      => 'info',
                    'details'     => 'Removed old restore point',
                    'user_id'     => null,
                    'ip_address'  => '198.51.100.3',
                ],
            ];

            $GLOBALS['wpdb'] = new BJLG_Test_History_WPDB($rows);

            $results = \BJLG\BJLG_History::search('restore', 10);

            self::assertCount(2, $results);
            self::assertSame('restore_run', $results[0]['action_type']);
            self::assertSame('backup_deleted', $results[1]['action_type']);
        }

        public function test_export_csv_formats_history_rows(): void
        {
            $rows = [
                [
                    'id'          => 8,
                    'timestamp'   => '2024-02-15 07:30:00',
                    'action_type' => 'backup_created',
                    'status'      => 'success',
                    'details'     => 'Morning backup',
                    'user_id'     => 1,
                    'ip_address'  => '203.0.113.10',
                ],
                [
                    'id'          => 9,
                    'timestamp'   => '2024-02-14 18:00:00',
                    'action_type' => 'restore_run',
                    'status'      => 'failed',
                    'details'     => 'Restore timed out',
                    'user_id'     => 2,
                    'ip_address'  => '203.0.113.11',
                ],
            ];

            $GLOBALS['wpdb'] = new BJLG_Test_History_WPDB($rows);

            $csv = \BJLG\BJLG_History::export_csv();

            self::assertSame(['Date', 'Action', 'Statut', 'Détails', 'Utilisateur', 'IP'], $csv[0]);
            self::assertSame(
                [
                    '2024-02-15 07:30:00',
                    'backup_created',
                    'success',
                    'Morning backup',
                    'Admin One',
                    '203.0.113.10',
                ],
                $csv[1]
            );
            self::assertSame(
                [
                    '2024-02-14 18:00:00',
                    'restore_run',
                    'failed',
                    'Restore timed out',
                    'Editor Two',
                    '203.0.113.11',
                ],
                $csv[2]
            );
        }

        public function test_cleanup_removes_entries_before_cutoff(): void
        {
            $rows = [
                [
                    'id'          => 10,
                    'timestamp'   => '2023-12-31 23:50:00',
                    'action_type' => 'backup_created',
                    'status'      => 'success',
                    'details'     => 'Year end backup',
                    'user_id'     => 1,
                    'ip_address'  => '203.0.113.12',
                ],
                [
                    'id'          => 11,
                    'timestamp'   => '2024-01-10 12:00:00',
                    'action_type' => 'backup_deleted',
                    'status'      => 'info',
                    'details'     => 'Cleanup old files',
                    'user_id'     => 1,
                    'ip_address'  => '203.0.113.13',
                ],
                [
                    'id'          => 12,
                    'timestamp'   => '2024-02-10 15:00:00',
                    'action_type' => 'restore_run',
                    'status'      => 'success',
                    'details'     => 'Recent restore',
                    'user_id'     => 2,
                    'ip_address'  => '203.0.113.14',
                ],
            ];

            $wpdb = new BJLG_Test_History_WPDB($rows);
            $GLOBALS['wpdb'] = $wpdb;

            $deleted = \BJLG\BJLG_History::cleanup(30);

            self::assertSame(2, $deleted);
            $remaining = $wpdb->get_rows();
            self::assertCount(1, $remaining);
            self::assertSame(12, $remaining[0]['id']);
        }

        public function test_create_table_uses_blog_prefix(): void
        {
            $GLOBALS['bjlg_test_dbdelta'] = [];
            $wpdb = new BJLG_Test_History_WPDB([]);
            $GLOBALS['wpdb'] = $wpdb;

            \BJLG\BJLG_History::create_table(7);

            self::assertNotEmpty($GLOBALS['bjlg_test_dbdelta']);
            $sql = (string) end($GLOBALS['bjlg_test_dbdelta']);
            self::assertStringContainsString('wp_7_bjlg_history', $sql);

            $GLOBALS['bjlg_test_dbdelta'] = [];
            \BJLG\BJLG_History::create_table(0);
            $sql_base = (string) end($GLOBALS['bjlg_test_dbdelta']);
            self::assertStringContainsString('wp_bjlg_history', $sql_base);
        }

        public function test_get_history_honors_requested_blog_id(): void
        {
            $wpdb = new BJLG_Test_History_WPDB([]);
            $GLOBALS['wpdb'] = $wpdb;

            \BJLG\BJLG_History::get_history(5, [], 12);

            self::assertStringContainsString('wp_12_bjlg_history', $wpdb->last_prepared_query);
        }
    }
}
