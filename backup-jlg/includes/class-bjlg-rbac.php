<?php
namespace BJLG;

if (!defined('ABSPATH')) {
    exit;
}

class BJLG_RBAC
{
    /**
     * Returns RBAC context definitions used across the admin interface.
     *
     * @return array<string,array<string,string>>
     */
    public static function get_context_definitions(): array
    {
        $contexts = [
            'manage_plugin' => [
                'label' => __('Accès complet', 'backup-jlg'),
                'description' => __('Contrôle total du plugin (configuration, sauvegardes, restauration).', 'backup-jlg'),
            ],
            'manage_backups' => [
                'label' => __('Sauvegardes', 'backup-jlg'),
                'description' => __('Autoriser le lancement, la planification et la suppression des sauvegardes.', 'backup-jlg'),
            ],
            'restore' => [
                'label' => __('Restauration', 'backup-jlg'),
                'description' => __('Accès aux opérations de restauration et aux environnements de test.', 'backup-jlg'),
            ],
            'manage_settings' => [
                'label' => __('Paramètres', 'backup-jlg'),
                'description' => __('Modifier les réglages généraux, le chiffrement et la rétention.', 'backup-jlg'),
            ],
            'manage_integrations' => [
                'label' => __('Intégrations & API', 'backup-jlg'),
                'description' => __('Gérer les destinations cloud, les webhooks et les clés API.', 'backup-jlg'),
            ],
            'view_logs' => [
                'label' => __('Journaux & audit', 'backup-jlg'),
                'description' => __('Consulter les journaux, l’historique et les notifications.', 'backup-jlg'),
            ],
        ];

        if (function_exists('is_multisite') && is_multisite()) {
            $contexts['manage_network'] = [
                'label' => __('Administration réseau', 'backup-jlg'),
                'description' => __('Accès à la console réseau multisite et aux options globales.', 'backup-jlg'),
            ];
        }

        return $contexts;
    }

    /**
     * Returns default RBAC templates.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function get_templates(): array
    {
        $contexts = self::get_context_definitions();
        $allContextKeys = array_keys($contexts);

        $administratorMap = array_fill_keys($allContextKeys, 'manage_options');
        $operatorMap = $administratorMap;
        $auditorMap = $administratorMap;

        $operatorMap['manage_settings'] = 'manage_options';
        $operatorMap['manage_integrations'] = 'manage_options';

        $auditorMap['manage_backups'] = 'read';
        $auditorMap['restore'] = 'read';
        $auditorMap['manage_settings'] = 'read';
        $auditorMap['manage_integrations'] = 'read';
        $auditorMap['view_logs'] = 'read';
        $auditorMap['manage_plugin'] = 'read';

        if (isset($auditorMap['manage_network'])) {
            $administratorMap['manage_network'] = 'manage_network_options';
            $operatorMap['manage_network'] = 'manage_network_options';
            $auditorMap['manage_network'] = 'manage_network_options';
        }

        return [
            'full_admin' => [
                'label' => __('Administrateur complet', 'backup-jlg'),
                'description' => __('Attribue toutes les actions du plugin à la capacité manage_options.', 'backup-jlg'),
                'map' => $administratorMap,
            ],
            'operations' => [
                'label' => __('Opérations sauvegarde', 'backup-jlg'),
                'description' => __('Autorise l’exécution des sauvegardes et restaurations sans modifier les paramètres.', 'backup-jlg'),
                'map' => $operatorMap,
            ],
            'auditor' => [
                'label' => __('Auditeur', 'backup-jlg'),
                'description' => __('Lecture seule des journaux et de l’historique.', 'backup-jlg'),
                'map' => $auditorMap,
            ],
        ];
    }

    /**
     * Returns the selectable roles and capabilities.
     *
     * @return array{roles: array<string,string>, capabilities: array<string,string>}
     */
    public static function get_permission_choices(): array
    {
        $roles = [];
        $capabilities = [];

        if (function_exists('wp_roles')) {
            $wp_roles = wp_roles();
            if ($wp_roles && $wp_roles instanceof \WP_Roles) {
                foreach ($wp_roles->roles as $roleKey => $details) {
                    $label = isset($details['name']) ? (string) $details['name'] : $roleKey;
                    if (function_exists('translate_user_role')) {
                        $label = translate_user_role($label);
                    }
                    $roles[$roleKey] = $label;

                    if (!empty($details['capabilities']) && is_array($details['capabilities'])) {
                        foreach ($details['capabilities'] as $capability => $granted) {
                            if ($granted) {
                                $capabilities[$capability] = $capability;
                            }
                        }
                    }
                }
            }
        }

        if (empty($roles)) {
            $roles = [
                'administrator' => __('Administrateur', 'backup-jlg'),
                'editor' => __('Éditeur', 'backup-jlg'),
                'author' => __('Auteur', 'backup-jlg'),
                'contributor' => __('Contributeur', 'backup-jlg'),
                'subscriber' => __('Abonné', 'backup-jlg'),
            ];
        }

        if (empty($capabilities)) {
            $capabilities = [
                'manage_options' => 'manage_options',
                'publish_posts' => 'publish_posts',
                'read' => 'read',
                'upload_files' => 'upload_files',
            ];
            if (function_exists('is_multisite') && is_multisite()) {
                $capabilities['manage_network_options'] = 'manage_network_options';
            }
        }

        ksort($roles);
        ksort($capabilities);

        $sanitize = static function ($items) {
            $result = [];
            if (!is_array($items)) {
                return $result;
            }

            foreach ($items as $key => $label) {
                if (!is_string($key) || $key === '') {
                    continue;
                }
                $result[$key] = is_string($label) && $label !== '' ? $label : $key;
            }

            return $result;
        };

        $choices = [
            'roles' => $sanitize($roles),
            'capabilities' => $sanitize($capabilities),
        ];

        /**
         * Allow third-parties to adjust the selectable roles and capabilities.
         *
         * @param array{roles: array<string,string>, capabilities: array<string,string>} $choices
         */
        $filtered = apply_filters('bjlg_required_capability_choices', $choices);
        if (is_array($filtered)) {
            return [
                'roles' => $sanitize($filtered['roles'] ?? $choices['roles']),
                'capabilities' => $sanitize($filtered['capabilities'] ?? $choices['capabilities']),
            ];
        }

        return $choices;
    }

    /**
     * Sanitizes a capability map before persisting it.
     *
     * @param array<string,mixed> $map
     *
     * @return array<string,string>
     */
    public static function sanitize_map(array $map): array
    {
        $contexts = self::get_context_definitions();
        $contextKeys = array_keys($contexts);
        $sanitized = [];

        foreach ($contextKeys as $context) {
            $value = isset($map[$context]) ? $map[$context] : '';
            if (!is_string($value) || $value === '') {
                continue;
            }
            $sanitized[$context] = sanitize_text_field($value);
        }

        return $sanitized;
    }

    /**
     * Normalizes the provided RBAC scope.
     */
    public static function normalize_scope(?string $scope): string
    {
        $scope = is_string($scope) ? strtolower($scope) : '';
        if ($scope === 'network' && function_exists('is_multisite') && is_multisite()) {
            return 'network';
        }

        return 'site';
    }
}
