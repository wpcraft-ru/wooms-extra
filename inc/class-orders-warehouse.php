<?php
namespace WooMS\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * Send waerhouse in order if set in options
 */
final class Send_Warehouse {

  /**
   * The init
   */
  public static function init()
  {
    add_action( 'admin_init', array( __CLASS__, 'settings_init' ), 100);

    add_filter( 'wooms_order_send_data', array(__CLASS__, 'add_data_to_order'), 10, 2);
  }

  /**
   * add_data_to_order
   */
  public static function add_data_to_order($data, $order_id)
  {
    if(empty(get_option('woomss_warehouse_id'))){
      return $data;
    }

    $warehouse_id = get_option('woomss_warehouse_id');

    if(empty(get_option('wooms_orders_send_warehouse'))){
      return $data;
    }

    $data['store']['meta'] = array(
      'href' => 'https://online.moysklad.ru/api/remap/1.1/entity/store/' . $warehouse_id,
      "type" => "store",
    );

    return $data;
  }

  /**
   * Setting
   */
  public static function settings_init() {

      register_setting( 'mss-settings', 'wooms_orders_send_warehouse' );

      if(get_option('woomss_warehouse_id')){
        add_settings_field(
            $id = 'wooms_orders_send_warehouse',
            $title = 'Отправлять выбранный склад в Заказе',
            $callback = array(__CLASS__, 'display_wooms_orders_send_warehouse', ),
            $page = 'mss-settings',
            $section = 'wooms_section_orders'
        );
      }

  }

  /**
   * display_wooms_orders_send_warehouse
   */
  public static function display_wooms_orders_send_warehouse(){
    $option = 'wooms_orders_send_warehouse';
    printf( '<input type="checkbox" name="%s" value="1" %s />', $option, checked( 1, get_option( $option ), false ) );
  }
}

Send_Warehouse::init();
