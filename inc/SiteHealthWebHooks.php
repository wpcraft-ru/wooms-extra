<?php 
namespace WooMS;

class SiteHealthWebHooks
{
    public static function init()
    {
        add_filter('site_status_tests', function($tests){

            if ( ! get_option('wooms_enable_webhooks')) {
                return $tests;
            }

            $tests['async']['wooms_check_webhooks'] = [
                'test'  => 'check_webhooks',
            ];
    
            return $tests;
        });

        add_action('wp_ajax_health-check-check-webhooks', [__CLASS__, 'check_webhooks']);

    }


    /**
     * Check can we add webhooks
     *
     * @return bool
     */
    public static function check_webhooks()
    {
        $url  = 'https://online.moysklad.ru/api/remap/1.2/entity/webhook';

        $employee_url = 'https://online.moysklad.ru/api/remap/1.1/context/employee';

        // создаем веб хук в МойСклад
        $data   = array(
            'url'        => rest_url('/wooms/v1/order-update/'),
            'action'     => "UPDATE",
            "entityType" => "customerorder",
        );
        $api_result = wooms_request($url, $data);

        $result = [
            'label' => "Проверка подписки МойСклад",
            'status'      => 'good',
            'badge'       => [
                'label' => 'Уведомление WooMS',
                'color' => 'blue',
            ],
            'description' => sprintf("Все хорошо! Спасибо что используете наш плагин %s", '🙂'),
            'test' => 'wooms_check_weebhooks' // this is only for class in html block
        ];


        if (!empty($api_result['errors'])) {

            $result['status'] = 'critical';
            $result['badge']['color'] = 'red';
            $result['description'] = sprintf("%s %s", $api_result['errors'][0]['error'], '❌');
        }

        // Checking permissions too
        $data_api_p = wooms_request($employee_url, [], 'GET');

        foreach ($data_api_p['permissions']['webhook'] as $permission) {
            if (!$permission) {
                $description = "У данного пользователя не хватает прав для работы с вебхуками";
                $result['description'] = sprintf('%s %s', $description, '❌');
                if (!empty($api_result['errors'])) {
                    $result['description'] = sprintf("1. %s 2. %s %s", $api_result['errors'][0]['error'], $description, '❌');
                }
            }

            // Добовляем значение для вывода ошибки в здаровье сайта
            set_transient('wooms_check_moysklad_tariff', $result['description'], 60 * 60);
        }

        wp_send_json_success($result);
    }
}

SiteHealthWebHooks::init();