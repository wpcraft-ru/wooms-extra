<?php
/*
Plugin Name: WooMS Extra
Version: 0.2
Plugin URI: https://wpcraft.ru/product/wooms-extra/
Description: Расширение для синхронизации МойСклад и WooCommerce
Author: WPCraft
Author URI: https://wpcraft.ru
*/

require_once 'inc/class-import-product-images.php';

class WooMS_Extra {

  private $plugin;

  function __construct() {

    $this->plugin = plugin_basename( __FILE__ );

    add_filter( "plugin_action_links_" . $this->plugin, [$this, 'plugin_add_settings_link'] );
  }


  function plugin_add_settings_link( $links ) {
      $settings_link = '<a href="options-general.php?page=mss-settings">Настройки</a>';
      array_push( $links, $settings_link );
      return $links;
  }

}
new WooMS_Extra;
