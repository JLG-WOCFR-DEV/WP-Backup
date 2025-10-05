<?php
namespace BJLG {
    if (!class_exists(__NAMESPACE__ . '\\BJLG_Incremental', false)) {
        class BJLG_Incremental
        {
            /** @var self|null */
            public static $latestInstance = null;

            /** @var array<string, bool> */
            public static $changedTables = [];

            /** @var array<int, string> */
            public static $checkedTables = [];

            public function __construct()
            {
                self::$latestInstance = $this;
            }

            public static function get_latest_instance()
            {
                return self::$latestInstance;
            }

            public function table_has_changed($table_name)
            {
                self::$checkedTables[] = $table_name;

                return self::$changedTables[$table_name] ?? false;
            }
        }
    }
}

namespace {
    if (!class_exists('BJLG_Incremental', false)) {
        class_alias('BJLG\\BJLG_Incremental', 'BJLG_Incremental');
    }
}
