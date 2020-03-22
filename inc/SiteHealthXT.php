<?php

namespace WooMS;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}


/**
 * Import Product Images
 */
class SiteHealthXT
{
 
    public static function init()
    {
        add_filter('site_status_tests', [__CLASS__, 'new_health_tests']);

        add_filter('debug_information', [__CLASS__, 'add_info_to_debug']);
    
    }

    /**
     * adding hooks for site health
     *
     * @param [type] $tests
     * @return void
     */
    public static function new_health_tests($tests)
    {

        $tests['direct']['wooms_check_base_plugin'] = [
            'test'  => [__CLASS__,'wooms_check_base_plugin'],
        ];

        return $tests;
    }

    /**
     * check_base_plugin
     */
    public static function wooms_check_base_plugin()
    {
        if ( ! function_exists('get_plugin_data') ) {
            require_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }

        
        $result = [
            'label' => 'Для работы плагина WooMS XT требуется основной плагин WooMS',
            'status'      => 'good',
            'badge'       => [
                'label' => 'Уведомление WooMS',
                'color' => 'blue',
            ],
            'description' => sprintf('Все хорошо! Спасибо что выбрали наш плагин %s', '🙂'),
            'test' => 'wooms_check_base_plugin' // this is only for class in html block
        ];

        if (!is_plugin_active('wooms/wooms.php')) {
            $result['status'] = 'critical';
            $result['badge']['color'] = 'red';
            $result['actions'] = sprintf(
                '<p><a href="%s" target="_blank">%s</a></p>',
                '//wordpress.org/plugins/wooms/',
                sprintf("Установить плагин")
            );
            $result['description'] = 'Для работы плагина WooMS XT требуется основной плагин WooMS.';
        }


        return $result;
    }

    /**
     * debuging and adding to debug sections of health page
     *
     * @param [type] $debug_info
     * @return void
     */
    public static function add_info_to_debug($debug_info)
    {

        if (is_plugin_active('wooms/wooms.php')) {
            return $debug_info;
        }

        $debug_info['wooms-plugin-debug'] = [
            'label'    => 'Wooms',
            'fields'   => [
                'Wooms Error' => [
                    'label'    => 'Wooms Version ',
                    'value'   => sprintf('Для работы плагина WooMS XT требуется основной плагин WooMS. %s', '❌'),
                ],
            ],
        ];

        $debug_info = apply_filters('add_wooms_plugin_debug', $debug_info);

        return $debug_info;
    }

}

SiteHealthXT::init();
