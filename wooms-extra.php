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
 * Version: 4.4
 */


if ( ! defined( 'ABSPATH' ) ) {
  exit; // Exit if accessed directly
}

/**
 * Description class
 */
class WooMS_XT {

  /**
   * activate wooms
   */
  public static $is_core_exist = false;
  /**
   * The init
   */
  public static function init(){
      add_action( 'admin_notices', array(__CLASS__, 'check_base_plugin') );
      add_action( 'plugins_loaded', array(__CLASS__, 'load') );
  }

  /**
   * load files
   */
  public static function load(){

    if( ! class_exists('WooMS_Core')){
      self::$is_core_exist = false;
      return;
    }

    self::$is_core_exist = apply_filters('wooms_xt_load', true);

    if(self::$is_core_exist){
      require_once 'inc/class-products-stocks.php';
      require_once 'inc/class-import-product-attributes.php';
      require_once 'inc/class-import-product-variants.php';
      require_once 'inc/class-products-bundles.php';
      require_once 'inc/class-orders-sending.php';
      require_once 'inc/class-import-sale-prices.php';
      require_once 'inc/class-import-product-choice-categories.php';
      require_once 'inc/class-hide-old-variables.php';
    }
  }

  /**
   * check_base_plugin
   */
  public static function check_base_plugin() {
    if ( ! function_exists( 'get_plugin_data' ) ) {
      require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
    }
    $wooms_version = get_file_data( __FILE__, array( 'wooms_ver' => 'WooMS requires at least' ) );

    $error_text = '';

    if ( ! is_plugin_active( 'wooms/wooms.php' ) ) {

      $error_text = 'Для работы плагина WooMS XT требуется основной плагин <strong><a href="//wordpress.org/plugins/wooms/" target="_blank">WooMS</a></strong>';

    }

    /**
     * hook for change error message
     */
    $error_text = apply_filters('wooms_xt_error_msg', $error_text);

    if ( ! empty( $error_text ) ) {
      printf('
        <div class="notice notice-error">
            <p><strong>Внимание!</strong> %s</p>
        </div>',
        $error_text);
    }
  }

}

WooMS_XT::init();
