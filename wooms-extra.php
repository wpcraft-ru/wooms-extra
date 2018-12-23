<?php
/**
 * Plugin Name: WooMS XT
 * Plugin URI: https://wpcraft.ru/product/wooms-extra/
 * Description: Расширение для синхронизации МойСклад и WooCommerce
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
 * Version: 3.7
 */


if ( ! defined( 'ABSPATH' ) ) {
  exit; // Exit if accessed directly
}


add_action( 'admin_notices', 'wooms_check_base_plugin' );
function wooms_check_base_plugin() {
  if ( ! function_exists( 'get_plugin_data' ) ) {
    require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
  }
  $wooms_version = get_file_data( __FILE__, array( 'wooms_ver' => 'WooMS requires at least' ) );

  if ( ! is_plugin_active( 'wooms/wooms.php' ) ) {

    $error_text = 'Для работы плагина WooMS XT требуется основной плагин <strong><a href="//wordpress.org/plugins/wooms/" target="_blank">WooMS</a></strong>';

    set_transient( 'wooms_extra_activation_error_message', $error_text, 60 );

  } elseif ( version_compare( WOOMS_PLUGIN_VER, $wooms_version['wooms_ver'], '<' ) ) {

    $error_text = 'Для работы плагина WooMS XT требуется основной плагин <strong><a href="//wordpress.org/plugins/wooms/" target="_blank">WooMS</a> версии ' .
                  $wooms_version['wooms_ver'] . '</strong> или выше';

    set_transient( 'wooms_extra_activation_error_message', $error_text, 60 );

  }

  $message = get_transient( 'wooms_extra_activation_error_message' );

  if ( ! empty( $message ) ) {
    echo '<div class="notice notice-error">
            <p><strong>Внимание!</strong> ' . $message . '</p>
        </div>';

    delete_transient( 'wooms_extra_activation_error_message' );
  }
}

require_once 'inc/class-cron.php';
require_once 'inc/class-import-product-stocks.php';
require_once 'inc/class-import-product-attributes.php';
require_once 'inc/class-import-product-variants.php';
require_once 'inc/class-products-bundles.php';
require_once 'inc/class-orders-sending.php';
require_once 'inc/class-import-sale-prices.php';
require_once 'inc/class-import-product-choice-categories.php';
require_once 'inc/class-hide-old-variables.php';
