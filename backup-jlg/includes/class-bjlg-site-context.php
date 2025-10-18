<?php
namespace BJLG;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gestionnaire de contexte multisite pour les options du plugin.
 */
class BJLG_Site_Context {
    /** @var bool */
    private static $switched = false;

    /**
     * Récupère une option en tenant compte du contexte multisite.
     *
     * @param string     $option   Nom de l'option.
     * @param mixed      $default  Valeur par défaut.
     * @param int|null   $site_id  Identifiant explicite du site.
     * @param bool|null  $network  Forcer le contexte réseau (true) ou site (false).
     *
     * @return mixed
     */
    public static function get_option($option, $default = false, $site_id = null, $network = null) {
        if (!function_exists('is_multisite') || !is_multisite()) {
            return \get_option($option, $default);
        }

        if ($site_id !== null) {
            $site_id = absint($site_id);
            if ($site_id > 0) {
                return \get_blog_option($site_id, $option, $default);
            }
        }

        if ($network === true || ($network === null && function_exists('is_network_admin') && is_network_admin())) {
            return function_exists('get_site_option')
                ? \get_site_option($option, $default)
                : \get_option($option, $default);
        }

        return \get_option($option, $default);
    }

    /**
     * Met à jour une option en tenant compte du contexte multisite.
     *
     * @param string     $option   Nom de l'option.
     * @param mixed      $value    Valeur à enregistrer.
     * @param int|null   $site_id  Identifiant explicite du site.
     * @param bool|null  $network  Forcer le contexte réseau (true) ou site (false).
     * @param string|bool|null $autoload
     *
     * @return bool
     */
    public static function update_option($option, $value, $site_id = null, $network = null, $autoload = null) {
        if (!function_exists('is_multisite') || !is_multisite()) {
            return \update_option($option, $value, $autoload);
        }

        if ($site_id !== null) {
            $site_id = absint($site_id);
            if ($site_id > 0) {
                return \update_blog_option($site_id, $option, $value);
            }
        }

        if ($network === true || ($network === null && function_exists('is_network_admin') && is_network_admin())) {
            return function_exists('update_site_option')
                ? \update_site_option($option, $value)
                : \update_option($option, $value, $autoload);
        }

        return \update_option($option, $value, $autoload);
    }

    /**
     * Supprime une option en tenant compte du contexte multisite.
     *
     * @param string    $option   Nom de l'option.
     * @param int|null  $site_id  Identifiant explicite du site.
     * @param bool|null $network  Forcer le contexte réseau.
     *
     * @return bool
     */
    public static function delete_option($option, $site_id = null, $network = null) {
        if (!function_exists('is_multisite') || !is_multisite()) {
            return \delete_option($option);
        }

        if ($site_id !== null) {
            $site_id = absint($site_id);
            if ($site_id > 0) {
                return \delete_blog_option($site_id, $option);
            }
        }

        if ($network === true || ($network === null && function_exists('is_network_admin') && is_network_admin())) {
            return function_exists('delete_site_option')
                ? \delete_site_option($option)
                : \delete_option($option);
        }

        return \delete_option($option);
    }

    /**
     * Bascule sur un site spécifique et retourne un indicateur pour la restauration.
     */
    public static function switch_to_site($site_id) {
        if (!function_exists('is_multisite') || !is_multisite()) {
            return false;
        }

        $site_id = absint($site_id);
        if ($site_id <= 0) {
            return false;
        }

        if (function_exists('get_current_blog_id') && get_current_blog_id() === $site_id) {
            return false;
        }

        if (!function_exists('get_site') || !get_site($site_id)) {
            return false;
        }

        switch_to_blog($site_id);
        self::$switched = true;

        return true;
    }

    /**
     * Restaure le blog courant si un switch a été effectué.
     */
    public static function restore_site($did_switch) {
        if ($did_switch || self::$switched) {
            self::$switched = false;
            restore_current_blog();
        }
    }
}
