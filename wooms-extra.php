<?php
/**
 * Plugin Name: WooMS Extra
 * Plugin URI: https://wpcraft.ru/product/wooms-extra/
 * Description: Расширение для синхронизации МойСклад и WooCommerce
 * Author: WPCraft
 * Author URI: https://wpcraft.ru/
 * Developer: WPCraft
 * Developer URI: https://wpcraft.ru/
 * Version: 1.7.3
 * Text Domain: wooms-extra
 * Domain Path: /languages
 * WC requires at least: 3.0
 * WC tested up to: 3.3.5
 *
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
add_action( 'admin_init', 'wooms_check_base_plugin' );
add_action( 'admin_notices', 'wooms_extra_show_notices' );
function wooms_check_base_plugin() {
	$wooms_version = '2.0.5';
	if ( ! is_plugin_active( 'wooms/wooms.php' ) ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		if ( isset( $_GET['activate'] ) ) {
			unset( $_GET['activate'] );
		}
		$error_text = 'Для корректной работы требуется плагин <strong><a href="//wordpress.org/plugins/wooms/" target="_blank">WooMS</a></strong>';
		set_transient( 'wooms_extra_activation_error_message', $error_text, 60 );
	} elseif ( version_compare( WOOMS_PLUGIN_VER, $wooms_version, '<' ) ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		if ( isset( $_GET['activate'] ) ) {
			unset( $_GET['activate'] );
		}
		$error_text = 'Для корректной работы требуется плагин <strong><a href="//wordpress.org/plugins/wooms/" target="_blank">WooMS</a> версии '. $wooms_version .'</strong> или выше';
		set_transient( 'wooms_extra_activation_error_message', $error_text, 60 );
	} else {
		register_activation_hook( __FILE__, 'wooms_extra_activate_plugin' );
	}
}

function wooms_extra_show_notices() {
	$message = get_transient( 'wooms_extra_activation_error_message' );
	if ( ! empty( $message ) ) {
		echo '<div class="notice notice-error">
            <p><strong>Плагин WooMS Extra не активирован!</strong> ' . $message . '</p>
        </div>';
		delete_transient( 'wooms_extra_activation_error_message' );
	}
}

function wooms_extra_activate_plugin() {
	require_once 'inc/class-cron.php';
	require_once 'inc/class-import-product-stocks.php';
	require_once 'inc/class-import-product-variants.php';
	require_once 'inc/class-products-bundles.php';
	require_once 'inc/class-orders-sending.php';
}