<?php
namespace WooMS;
defined( 'ABSPATH' ) || exit;

final class TaxSupport
{
    public static function init(){

        //disable for live
        if(empty(getenv('LOCAL'))){
            return;
        } 
        
        add_action('init', function(){
            // if(!isset($_GET['dd'])){
            //     return;
            // }

            // echo '<pre>';

            // // $url = 'https://online.moysklad.ru/api/remap/1.1/entity/customerorder/1080a7da-edfb-11e9-0a80-03c4001121bb';
            // // $d = wooms_request($url);
            // // var_dump($d['positions']);

            // // exit;
            // OrderSender::update_order(23);

            // var_dump('end'); exit;
        });

        add_filter('wooms_order_data', [__CLASS__, 'add_tax'], 11, 2);

        add_action('admin_init', array(__CLASS__, 'add_settings'), 40);

    }

    public static function add_tax($data_order, $order_id){

        if(empty(get_option('wooms_tax_support'))){
            return $data_order;
        }

        // var_dump($data_order);
        // exit;
        return $data_order;
    }

    public static function add_settings()
    {
        $section_key = 'wooms_section_orders';
        $option_key = 'wooms_tax_support';
        register_setting('mss-settings', $option_key);
        add_settings_field(
            $id = $option_key . '_input',
            $title = 'Включить работу с налогами',
            $callback = function($args){
                printf(
                    '<input type="checkbox" name="%s" value="1" %s />',
                    $args['key'], checked(1, $args['value'], $echo = false)
                );
            },
            $page = 'mss-settings',
            $section_key,
            $args = [
                'key' => $option_key,
                'value' => get_option($option_key),
            ]
        );
    }

}

TaxSupport::init();