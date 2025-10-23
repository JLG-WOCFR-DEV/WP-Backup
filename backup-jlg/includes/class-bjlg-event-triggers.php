<?php
namespace BJLG;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Observe WordPress events (filesystem/database) and enqueue backup jobs.
 */
class BJLG_Event_Triggers
{
    /**
     * Singleton instance.
     *
     * @var BJLG_Event_Triggers|null
     */
    private static $instance = null;

    /**
     * Retrieve the singleton instance.
     */
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        add_action('init', [$this, 'register_hooks'], 12);
    }

    /**
     * Register event listeners for filesystem and database activity.
     */
    public function register_hooks(): void
    {
        add_action('save_post', [$this, 'handle_post_saved'], 999, 3);
        add_action('deleted_post', [$this, 'handle_post_deleted'], 10, 1);
        add_action('comment_post', [$this, 'handle_comment_created'], 10, 3);
        add_action('deleted_comment', [$this, 'handle_comment_deleted'], 10, 2);
        add_action('added_option', [$this, 'handle_option_added'], 10, 2);
        add_action('updated_option', [$this, 'handle_option_updated'], 10, 3);
        add_action('deleted_option', [$this, 'handle_option_deleted'], 10, 1);
        add_action('add_attachment', [$this, 'handle_attachment_added'], 10, 1);
        add_action('delete_attachment', [$this, 'handle_attachment_deleted'], 10, 1);
        add_action('upgrader_process_complete', [$this, 'handle_upgrader_process_complete'], 10, 2);
    }

    /**
     * Database event: post saved (created or updated).
     */
    public function handle_post_saved($post_id, $post = null, $update = false): void
    {
        if (!$this->is_valid_post_event($post_id, $post)) {
            return;
        }

        $post_type = $this->extract_post_type($post);
        $status = $this->extract_post_status($post);

        $context = [
            'type' => $update ? 'post_updated' : 'post_created',
            'post_type' => $post_type,
            'status' => $status,
            'id' => (int) $post_id,
        ];

        $this->record_database_event($context);
    }

    /**
     * Database event: post deleted.
     */
    public function handle_post_deleted($post_id): void
    {
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return;
        }

        $this->record_database_event([
            'type' => 'post_deleted',
            'id' => $post_id,
        ]);
    }

    /**
     * Database event: comment created.
     */
    public function handle_comment_created($comment_id, $approved = null, $commentdata = []): void
    {
        $comment_id = (int) $comment_id;
        if ($comment_id <= 0) {
            return;
        }

        $post_id = isset($commentdata['comment_post_ID']) ? (int) $commentdata['comment_post_ID'] : 0;

        $this->record_database_event([
            'type' => 'comment_created',
            'id' => $comment_id,
            'post' => $post_id,
            'status' => $approved === null ? '' : (string) $approved,
        ]);
    }

    /**
     * Database event: comment deleted.
     */
    public function handle_comment_deleted($comment_id, $comment = null): void
    {
        $comment_id = (int) $comment_id;
        if ($comment_id <= 0) {
            return;
        }

        $post_id = 0;
        if (is_array($comment) && isset($comment['comment_post_ID'])) {
            $post_id = (int) $comment['comment_post_ID'];
        }

        $this->record_database_event([
            'type' => 'comment_deleted',
            'id' => $comment_id,
            'post' => $post_id,
        ]);
    }

    public function handle_option_added($option, $value): void
    {
        $this->record_database_event([
            'type' => 'option_added',
            'option' => $this->normalize_option_key($option),
        ]);
    }

    public function handle_option_updated($option, $old_value, $value): void
    {
        $this->record_database_event([
            'type' => 'option_updated',
            'option' => $this->normalize_option_key($option),
        ]);
    }

    public function handle_option_deleted($option): void
    {
        $this->record_database_event([
            'type' => 'option_deleted',
            'option' => $this->normalize_option_key($option),
        ]);
    }

    public function handle_attachment_added($attachment_id): void
    {
        $this->record_filesystem_event([
            'type' => 'attachment_added',
            'id' => (int) $attachment_id,
        ]);
    }

    public function handle_attachment_deleted($attachment_id): void
    {
        $this->record_filesystem_event([
            'type' => 'attachment_deleted',
            'id' => (int) $attachment_id,
        ]);
    }

    public function handle_upgrader_process_complete($upgrader, $context = []): void
    {
        $action = isset($context['action']) ? (string) $context['action'] : '';
        $type = isset($context['type']) ? (string) $context['type'] : '';

        $this->record_filesystem_event([
            'type' => 'upgrader_' . $this->sanitize_token($type),
            'action' => $this->sanitize_token($action),
        ]);
    }

    private function record_database_event(array $context): void
    {
        $scheduler = $this->get_scheduler();
        if (!$scheduler) {
            return;
        }

        $scheduler->handle_event_trigger('database', $context);
    }

    private function record_filesystem_event(array $context): void
    {
        $scheduler = $this->get_scheduler();
        if (!$scheduler) {
            return;
        }

        $scheduler->handle_event_trigger('filesystem', $context);
    }

    private function get_scheduler(): ?BJLG_Scheduler
    {
        if (!class_exists(__NAMESPACE__ . '\\BJLG_Scheduler')) {
            return null;
        }

        return BJLG_Scheduler::instance();
    }

    private function is_valid_post_event($post_id, $post): bool
    {
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return false;
        }

        if (function_exists('wp_is_post_revision') && wp_is_post_revision($post_id)) {
            return false;
        }

        if (function_exists('wp_is_post_autosave') && wp_is_post_autosave($post_id)) {
            return false;
        }

        $post_type = $this->extract_post_type($post);
        if ($post_type === 'revision') {
            return false;
        }

        return true;
    }

    private function extract_post_type($post): string
    {
        if (is_object($post) && isset($post->post_type)) {
            return $this->sanitize_token((string) $post->post_type);
        }

        if (function_exists('get_post_type')) {
            $type = get_post_type($post);
            if (is_string($type)) {
                return $this->sanitize_token($type);
            }
        }

        return '';
    }

    private function extract_post_status($post): string
    {
        if (is_object($post) && isset($post->post_status)) {
            return $this->sanitize_token((string) $post->post_status);
        }

        return '';
    }

    private function normalize_option_key($option): string
    {
        $option = is_string($option) ? $option : '';

        return $this->sanitize_token($option);
    }

    private function sanitize_token(string $value): string
    {
        if (function_exists('sanitize_key')) {
            $value = sanitize_key($value);
        } else {
            $value = strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '', $value));
        }

        return (string) $value;
    }
}
