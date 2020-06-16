<?php

namespace WooMS;

defined('ABSPATH') || exit;

/**
 * Get and save order number from MoySklad
 * 
 * issue https://github.com/wpcraft-ru/wooms/issues/319
 */
class OrderNumber
{
    public static function init()
    {
        add_filter('wooms_order_data', [__CLASS__, 'disable_order_number'], 50, 2);
        add_filter('woocommerce_order_number', array(__CLASS__, 'get_order_number'), 10, 2);
        add_filter('wooms_order_update', array(__CLASS__, 'set_order_number'), 10, 2);

        add_action('admin_init', array(__CLASS__, 'add_settings'), 50);
    }

    /**
     * set_order_number
     * 
     * use hook $order = apply_filters('wooms_order_update', $order, $result);
     */
    public static function set_order_number($order, $data_api)
    {
        if (!self::is_enable()) {
            return $order;
        }

        if(!empty($data_api['name'])){
            $order = wc_get_order($order);

            $order->update_meta_data('_order_number', $data_api['name']);
        }

        return $order;
    }


    public static function get_order_number($order_number, $order)
    {
        if (!self::is_enable()) {
            return $order_number;
        }

        if ($ms_order_number = $order->get_meta('_order_number', true, 'edit')) {
            $order_number = $ms_order_number;
        }

        return $order_number;
    }

    /**
     * 
     * use hook $data = apply_filters('wooms_order_data', $data, $order_id);
     */
    public static function disable_order_number($data, $order_id)
    {
        if (!self::is_enable()) {
            return $data;
        }

        unset($data['name']);
        // dd($data);

        return $data;
    }


    public static function is_enable()
    {
        if (get_option('wooms_order_number_from_moysklad')) {
            return true;
        }

        return false;
    }

    /**
     * Settings UI
     */
    public static function add_settings()
    {

        $option_name = 'wooms_order_number_from_moysklad';
        register_setting('mss-settings', $option_name);
        add_settings_field(
            $id = $option_name,
            $title = 'Номер Заказа брать из МойСклад',
            $callback = function ($args) {
                printf('<input type="checkbox" name="%s" value="1" %s />', $args['key'], checked(1, $args['value'], false));
                printf(
                    '<p><small>%s</small></p>',
                    'Включите эту опцию, чтобы номер Заказа брался из МойСклад'
                );
                printf(
                    '<p><small>%s</small></p>',
                    'Подробнее: <a href="https://github.com/wpcraft-ru/wooms/issues/319" target="_blank">https://github.com/wpcraft-ru/wooms/issues/319</a>'
                );
            },
            $page = 'mss-settings',
            $section = 'wooms_section_orders',
            $args = [
                'key' => $option_name,
                'value' => get_option($option_name)
            ]
        );
    }
}

OrderNumber::init();
