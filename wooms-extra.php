<?php

/**
 * Plugin Name: WooMS XT (Extra)
 * Description: Расширение для синхронизации данных между приложениями МойСклад и WooCommerce - расширенная версия
 * Plugin URI: https://wpcraft.ru/product/wooms-extra/?utm_source=admin-plugin-url-wooms-xt
 * Author: WPCraft
 * Author URI: https://wpcraft.ru/
 * Developer: WPCraft
 * Developer URI: https://wpcraft.ru/
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wooms-xt
 * Domain Path: /languages
 * WC requires at least: 3.6
 * WC tested up to: 4.0
 * WooMS requires at least: 2.0.5
 * WooMS tested up to: 2.0.5
 * Version: 7.9
 */

defined('ABSPATH') || exit;

/**
 * load files
 */
add_action('plugins_loaded', function () {
    if (!class_exists('WooMS_Core')) {
        $is_core_exist = false;
        return;
    }

    $is_core_exist = apply_filters('wooms_xt_load', true);

    if ($is_core_exist) {
        require_once __DIR__ . '/inc/ProductAttributes.php';
        require_once __DIR__ . '/inc/ProductSingleSync.php';
        require_once __DIR__ . '/inc/ProductStocks.php';
        require_once __DIR__ . '/inc/ProductGrouped.php';

        require_once __DIR__ . '/inc/ProductVariable.php';
        require_once __DIR__ . '/inc/ProductVariableImage.php';
        require_once __DIR__ . '/inc/VariationsHider.php';

        require_once __DIR__ . '/inc/OrderSender.php';
        require_once __DIR__ . '/inc/OrderUpdateFromMoySklad.php';
        
        require_once __DIR__ . '/inc/OrderShipment.php';
        require_once __DIR__ . '/inc/OrderNotes.php';
        require_once __DIR__ . '/inc/OrderStatusesFromSite.php';

        require_once __DIR__ . '/inc/TaxSupport.php';
        require_once __DIR__ . '/inc/CategoriesFilter.php';
        require_once __DIR__ . '/inc/SalePrices.php';
        require_once __DIR__ . '/inc/SendWarehouse.php';
        require_once __DIR__ . '/inc/SiteHealthXT.php';
        require_once __DIR__ . '/inc/SiteHealthWebHooks.php';
        require_once __DIR__ . '/inc/CurrencyConverter.php';
        require_once __DIR__ . '/inc/OrderNumber.php';
    }
});

/**
 * need for migrations and disable plugins
 */
add_filter('wooms_xt_version', function ($version) {

    if (!is_admin()) {
        return $version;
    }

    $data = get_plugin_data(__FILE__);
    return $data["Version"];
});
