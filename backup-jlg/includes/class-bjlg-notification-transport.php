<?php
namespace BJLG;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Provides transport helpers for multi-channel notifications.
 */
class BJLG_Notification_Transport {

    /**
     * Normalizes a raw list of email recipients into a unique array of addresses.
     *
     * @param mixed $raw
     *
     * @return string[]
     */
    public static function normalize_email_recipients($raw) {
        if (!is_string($raw)) {
            return [];
        }

        $parts = preg_split('/[,\n]+/', $raw);
        if (!$parts) {
            return [];
        }

        $valid = [];
        foreach ($parts as $part) {
            $email = sanitize_email(trim((string) $part));
            if ($email !== '' && is_email($email)) {
                $valid[] = $email;
            }
        }

        return array_values(array_unique($valid));
    }

    /**
     * Sends a notification email.
     *
     * @param string[] $recipients
     * @param string   $subject
     * @param string   $body
     *
     * @return array{success:bool,message?:string}
     */
    public static function send_email(array $recipients, $subject, $body) {
        if (empty($recipients)) {
            return [
                'success' => false,
                'message' => __('Aucun destinataire e-mail valide.', 'backup-jlg'),
            ];
        }

        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        $sent = wp_mail($recipients, $subject, $body, $headers);

        if ($sent) {
            return ['success' => true];
        }

        return [
            'success' => false,
            'message' => __('Échec de l\'envoi de la notification e-mail.', 'backup-jlg'),
        ];
    }

    /**
     * Sends a message to the configured Slack webhook.
     *
     * @param string   $webhook_url
     * @param string   $title
     * @param string[] $lines
     *
     * @return array{success:bool,message?:string}
     */
    public static function send_slack($webhook_url, $title, array $lines) {
        if (!self::is_valid_url($webhook_url)) {
            return [
                'success' => false,
                'message' => __('URL Slack invalide.', 'backup-jlg'),
            ];
        }

        $message = sprintf('*%s*\n%s', $title, implode("\n", $lines));

        $response = wp_remote_post($webhook_url, [
            'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
            'body' => wp_json_encode(['text' => $message]),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
            ];
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 300) {
            return ['success' => true];
        }

        return [
            'success' => false,
            'message' => sprintf(__('Réponse inattendue de Slack : %s', 'backup-jlg'), $code),
        ];
    }

    /**
     * Sends a message to the configured Discord webhook.
     *
     * @param string   $webhook_url
     * @param string   $title
     * @param string[] $lines
     *
     * @return array{success:bool,message?:string}
     */
    public static function send_discord($webhook_url, $title, array $lines) {
        if (!self::is_valid_url($webhook_url)) {
            return [
                'success' => false,
                'message' => __('URL Discord invalide.', 'backup-jlg'),
            ];
        }

        $content = sprintf('**%s**\n%s', $title, implode("\n", $lines));

        $response = wp_remote_post($webhook_url, [
            'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
            'body' => wp_json_encode(['content' => $content]),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
            ];
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 300) {
            return ['success' => true];
        }

        return [
            'success' => false,
            'message' => sprintf(__('Réponse inattendue de Discord : %s', 'backup-jlg'), $code),
        ];
    }

    /**
     * Sends a message card to a Microsoft Teams incoming webhook.
     *
     * @param string   $webhook_url
     * @param string   $title
     * @param string[] $lines
     *
     * @return array{success:bool,message?:string}
     */
    public static function send_teams($webhook_url, $title, array $lines) {
        if (!self::is_valid_url($webhook_url)) {
            return [
                'success' => false,
                'message' => __('URL Teams invalide.', 'backup-jlg'),
            ];
        }

        $body_lines = array_map('strval', $lines);
        $text = implode("\n\n", $body_lines);

        $payload = [
            '@type' => 'MessageCard',
            '@context' => 'http://schema.org/extensions',
            'summary' => $title,
            'themeColor' => '0078D7',
            'title' => $title,
            'text' => $title !== '' ? sprintf("**%s**\n\n%s", $title, $text) : $text,
        ];

        $response = wp_remote_post($webhook_url, [
            'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
            'body' => wp_json_encode($payload),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
            ];
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 300) {
            return ['success' => true];
        }

        return [
            'success' => false,
            'message' => sprintf(__('Réponse inattendue de Teams : %s', 'backup-jlg'), $code),
        ];
    }

    /**
     * Sends a compact payload to a generic SMS webhook gateway.
     *
     * @param string   $webhook_url
     * @param string   $title
     * @param string[] $lines
     *
     * @return array{success:bool,message?:string}
     */
    public static function send_sms($webhook_url, $title, array $lines) {
        if (!self::is_valid_url($webhook_url)) {
            return [
                'success' => false,
                'message' => __('URL SMS invalide.', 'backup-jlg'),
            ];
        }

        $body_lines = array_values(array_filter(array_map('strval', $lines)));
        $message_parts = [];

        if ($title !== '') {
            $message_parts[] = $title;
        }

        if (!empty($body_lines)) {
            $message_parts[] = implode(' | ', $body_lines);
        }

        $message = implode(' – ', $message_parts);

        $payload = [
            'title' => $title,
            'message' => $message,
            'lines' => $body_lines,
        ];

        $response = wp_remote_post($webhook_url, [
            'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
            'body' => wp_json_encode($payload),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
            ];
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 300) {
            return ['success' => true];
        }

        return [
            'success' => false,
            'message' => sprintf(__('Réponse inattendue de la passerelle SMS : %s', 'backup-jlg'), $code),
        ];
    }

    /**
     * Validates an URL before calling wp_remote_post.
     *
     * @param mixed $url
     */
    public static function is_valid_url($url) {
        if (!is_string($url) || trim($url) === '') {
            return false;
        }

        $validated = wp_http_validate_url($url);

        return $validated !== false && filter_var($validated, FILTER_VALIDATE_URL);
    }
}
