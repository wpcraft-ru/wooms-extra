<?php
namespace WooMS;

defined( 'ABSPATH' ) || exit;

/**
 * Adds an option to select an item in the order
 * as a shipment on the MoySklad side
 */
class OrderShipment
{
    public static function init(){
        add_action( 'admin_init', array( __CLASS__, 'add_settings' ), 50 );
        add_filter( 'wooms_order_send_data', array(__CLASS__, 'chg_order_data'), 10, 2);

        /**
         * fix https://github.com/wpcraft-ru/wooms/issues/186
         */
        add_filter( 'wooms_order_update_data', array(__CLASS__, 'chg_order_data'), 10, 2);
    }

    /**
     * chg_order_data
     */
    public static function chg_order_data($data, $order_id)
    {
        if(! $order_shipment_item_code = get_option('wooms_order_shipment_item_code')){
            return $data;
        }

        if( ! $meta = self::get_meta_for_shipment_item($order_shipment_item_code)){
            return $data;
        }

        $order = wc_get_order($order_id);

        $data['positions'][] = array(
            'quantity'   => 1,
            'price'      => $order->get_shipping_total() * 100,
            'assortment' => array(
                'meta' => $meta,
            ),
            'reserve'    => 0,
        );

        return $data;
    }

    /**
     * get meta for shipment item
     *
     * @param $order_shipment_item_code
     */
    public static function get_meta_for_shipment_item($order_shipment_item_code){
        $url = 'https://online.moysklad.ru/api/remap/1.1/entity/service';
        $url = add_query_arg('filter=code', $order_shipment_item_code, $url);
        $data = wooms_request($url);

        if(empty($data['rows'][0]['meta'])){
            return false;
        }

        $meta = $data['rows'][0]['meta'];
        return $meta;
    }

    /**
     * Settings UI
     */
    public static function add_settings() {

        $order_shipment_item_key = 'wooms_order_shipment_item_code';
        register_setting( 'mss-settings', $order_shipment_item_key );
        add_settings_field(
            $id = $order_shipment_item_key,
            $title = 'Код позиции для передачи стоимости доставки',
            $callback = function($args){
                printf('<input type="text" name="%s" value="%s" />', $args['key'], $args['value']);
                printf(
                    '<p><small>%s</small></p>',
                    'Если нужно передавать стоимость доставки, укажите тут код соответствующей услуги из МойСклад (поле Код в карточке Услуги)'
                );
            },
            $page = 'mss-settings',
            $section = 'wooms_section_orders',
            $args = [
                'key' => $order_shipment_item_key,
                'value' => get_option($order_shipment_item_key)
            ]
        );
    }
}

OrderShipment::init();