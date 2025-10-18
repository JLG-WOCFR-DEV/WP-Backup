<?php
declare(strict_types=1);

namespace BJLG\Tests\Stubs;

use BJLG\BJLG_Destination_Interface;

final class BJLG_Fake_Remote_Destination implements BJLG_Destination_Interface
{
    private string $id;
    private string $name;
    private bool $connected;
    private array $usage;
    private array $backups;

    public function __construct(string $id, array $config = [])
    {
        $this->id = $id;
        $this->name = $config['name'] ?? ucfirst($id);
        $this->connected = $config['connected'] ?? true;
        $this->usage = $config['usage'] ?? [];
        $this->backups = $config['backups'] ?? [];
    }

    public function get_id()
    {
        return $this->id;
    }

    public function get_name()
    {
        return $this->name;
    }

    public function is_connected()
    {
        return $this->connected;
    }

    public function disconnect()
    {
        $this->connected = false;
    }

    public function render_settings()
    {
    }

    public function upload_file($filepath, $task_id)
    {
    }

    public function list_remote_backups()
    {
        return $this->backups;
    }

    public function prune_remote_backups($retain_by_number, $retain_by_age_days)
    {
        return ['success' => true, 'deleted' => 0];
    }

    public function delete_remote_backup_by_name($filename)
    {
        return ['success' => false, 'message' => 'not implemented'];
    }

    public function get_storage_usage()
    {
        return $this->usage;
    }
}

