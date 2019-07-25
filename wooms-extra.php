<?php
/**
 * Plugin Name: WooMS XT
 * Description: Расширение для синхронизации данных между приложениями МойСклад и WooCommerce
 * Plugin URI: https://wpcraft.ru/product/wooms-extra/
 * Author: WPCraft
 * Author URI: https://wpcraft.ru/
 * Developer: WPCraft
 * Developer URI: https://wpcraft.ru/
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wooms-xt
 * Domain Path: /languages
 * WC requires at least: 3.0
 * WC tested up to: 3.5.0
 * WooMS requires at least: 2.0.5
 * WooMS tested up to: 2.0.5
 * Version: 5.5
 */

defined( 'ABSPATH' ) || exit;

class WooMS_XT
{
    /**
     * activate wooms
     */
    public static $is_core_exist = false;

    /**
     * The init
     */
    public static function init()
    {
        add_action('admin_notices', array(__CLASS__, 'check_base_plugin'));
        add_action('plugins_loaded', array(__CLASS__, 'load'));
    }

    /**
     * load files
     */
    public static function load()
    {
        if ( ! class_exists('WooMS_Core')) {
            self::$is_core_exist = false;
            return;
        }

        self::$is_core_exist = apply_filters('wooms_xt_load', true);

        if (self::$is_core_exist) {
            require_once 'inc/OrdersSender.php';
            require_once 'inc/class-products-variations.php';
            require_once 'inc/class-products-variations-hider.php';
            require_once 'inc/class-products-stocks.php';
            require_once 'inc/class-products-categories-filter.php';
            require_once 'inc/class-products-attributes.php';
            require_once 'inc/class-products-bundles.php';
            require_once 'inc/class-products-prices-sale.php';
            require_once 'inc/class-orders-statuses-from-site.php';
            require_once 'inc/class-orders-statuses-from-moysklad.php';
            require_once 'inc/class-product-single.php';
            require_once 'inc/class-orders-warehouse.php';
        }
    }

    /**
     * check_base_plugin
     */
    public static function check_base_plugin()
    {
        if ( ! function_exists('get_plugin_data') ) {
            require_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }

        $wooms_version = get_file_data(__FILE__, array('wooms_ver' => 'WooMS requires at least'));

        $error_text = '';

        if ( ! is_plugin_active('wooms/wooms.php')) {
            $error_text = 'Для работы плагина WooMS XT требуется основной плагин <strong><a href="//wordpress.org/plugins/wooms/" target="_blank">WooMS</a></strong>';
        }

        /**
         * hook for change error message
         */
        $error_text = apply_filters('wooms_xt_error_msg', $error_text);

        if ( ! empty($error_text)) {
            printf('
        <div class="notice notice-error">
            <p><strong>Внимание!</strong> %s</p>
        </div>
        ', $error_text);
        }
    }

}

WooMS_XT::init();
