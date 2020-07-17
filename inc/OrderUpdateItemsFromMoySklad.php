<?php

namespace WooMS;

use Exception;
use WC_Order_Item;
use WC_Order_Item_Product;

/**
 * Send statuses from moysklad.ru to WooCommerce
 */
class OrderUpdateItemsFromMoySklad
{

    /**
     * The init
     */
    public static function init()
    {
        add_action('init', function () {
            if (!isset($_GET['dd'])) {
                return;
            }

            echo '<pre>';

            $order = wc_get_order(26379);
            $data_order = self::get_test_data_order();

            $order = self::update_order_data_positions($order, $data_order);

            dd($order);

            die(0);
        });

        add_action('rest_api_init', array(__CLASS__, 'rest_api_init_callback_endpoint'));

        add_action('admin_init', array(__CLASS__, 'add_settings'), 100);
        add_filter('wooms_order_update_from_moysklad_action', array(__CLASS__, 'update_order_status'), 10, 3);
        add_filter('wooms_order_update_from_moysklad_filter', array(__CLASS__, 'update_order_data_positions'), 10, 2);
    }

    public static function get_test_data_order()
    {
        $json = '
                {
                "meta": {
                    "href": "https://online.moysklad.ru/api/remap/1.2/entity/customerorder/9037e83f-bfbf-11ea-0a80-058800015f9f",
                    "metadataHref": "https://online.moysklad.ru/api/remap/1.2/entity/customerorder/metadata",
                    "type": "customerorder",
                    "mediaType": "application/json",
                    "uuidHref": "https://online.moysklad.ru/app/#customerorder/edit?id=9037e83f-bfbf-11ea-0a80-058800015f9f"
                },
                "id": "9037e83f-bfbf-11ea-0a80-058800015f9f",
                "accountId": "1f2036af-9192-11e7-7a69-97110001d249",
                "owner": {
                    "meta": {
                    "href": "https://online.moysklad.ru/api/remap/1.2/entity/employee/1f2ec2de-9192-11e7-7a69-97110016a92b",
                    "metadataHref": "https://online.moysklad.ru/api/remap/1.2/entity/employee/metadata",
                    "type": "employee",
                    "mediaType": "application/json",
                    "uuidHref": "https://online.moysklad.ru/app/#employee/edit?id=1f2ec2de-9192-11e7-7a69-97110016a92b"
                    }
                },
                "shared": false,
                "group": {
                    "meta": {
                    "href": "https://online.moysklad.ru/api/remap/1.2/entity/group/1f206231-9192-11e7-7a69-97110001d24a",
                    "metadataHref": "https://online.moysklad.ru/api/remap/1.2/entity/group/metadata",
                    "type": "group",
                    "mediaType": "application/json"
                    }
                },
                "updated": "2020-07-06 22:35:26.843",
                "name": "5564",
                "description": "Посмотреть заказ на сайте: https://wooms.wpcraft.ru/wp-admin/post.php?post=23639&action=edit",
                "externalCode": "q7f78thpi3RITfqTK7cVl3",
                "moment": "2020-07-06 22:30:00.000",
                "applicable": true,
                "rate": {
                    "currency": {
                    "meta": {
                        "href": "https://online.moysklad.ru/api/remap/1.2/entity/currency/1f3ac651-9192-11e7-7a69-97110016a959",
                        "metadataHref": "https://online.moysklad.ru/api/remap/1.2/entity/currency/metadata",
                        "type": "currency",
                        "mediaType": "application/json",
                        "uuidHref": "https://online.moysklad.ru/app/#currency/edit?id=1f3ac651-9192-11e7-7a69-97110016a959"
                    }
                    }
                },
                "sum": 1.3169811E9,
                "agent": {
                    "meta": {
                    "href": "https://online.moysklad.ru/api/remap/1.2/entity/counterparty/a9e7f977-6230-11ea-0a80-019d00284b89",
                    "metadataHref": "https://online.moysklad.ru/api/remap/1.2/entity/counterparty/metadata",
                    "type": "counterparty",
                    "mediaType": "application/json",
                    "uuidHref": "https://online.moysklad.ru/app/#company/edit?id=a9e7f977-6230-11ea-0a80-019d00284b89"
                    }
                },
                "organization": {
                    "meta": {
                    "href": "https://online.moysklad.ru/api/remap/1.2/entity/organization/1f39fc1a-9192-11e7-7a69-97110016a952",
                    "metadataHref": "https://online.moysklad.ru/api/remap/1.2/entity/organization/metadata",
                    "type": "organization",
                    "mediaType": "application/json",
                    "uuidHref": "https://online.moysklad.ru/app/#mycompany/edit?id=1f39fc1a-9192-11e7-7a69-97110016a952"
                    }
                },
                "state": {
                    "meta": {
                    "href": "https://online.moysklad.ru/api/remap/1.2/entity/customerorder/metadata/states/1f47c079-9192-11e7-7a69-97110016a96e",
                    "metadataHref": "https://online.moysklad.ru/api/remap/1.2/entity/customerorder/metadata",
                    "type": "state",
                    "mediaType": "application/json"
                    }
                },
                "created": "2020-07-06 22:33:26.738",
                "files": {
                    "meta": {
                    "href": "https://online.moysklad.ru/api/remap/1.2/entity/customerorder/9037e83f-bfbf-11ea-0a80-058800015f9f/files",
                    "type": "files",
                    "mediaType": "application/json",
                    "size": 0,
                    "limit": 1000,
                    "offset": 0
                    }
                },
                "positions": {
                    "meta": {
                    "href": "https://online.moysklad.ru/api/remap/1.2/entity/customerorder/9037e83f-bfbf-11ea-0a80-058800015f9f/positions",
                    "type": "customerorderposition",
                    "mediaType": "application/json",
                    "size": 3,
                    "limit": 1000,
                    "offset": 0
                    }
                },
                "vatEnabled": true,
                "vatIncluded": true,
                "vatSum": 1.18333333E8,
                "payedSum": 0.0,
                "shippedSum": 0.0,
                "invoicedSum": 0.0,
                "reservedSum": 1316980000
                }

        ';

        $array = json_decode($json, true);
        return $array;
    }

    /**
     * update_order_data_positions
     * 
     * use hook apply_filters('wooms_order_update_from_moysklad', $order, $data_order );
     */
    public static function update_order_data_positions($order, $data_order)
    {
        $order = wc_get_order($order->get_id());

        $data_json = json_encode($data_order, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $order->update_meta_data('wooms_data', $data_json);

        if (empty($data_order['positions']['meta']['href'])) {
            return $order;
        }

        $url = $data_order['positions']['meta']['href'];

        $data = wooms_request($url);

        if (empty($data['rows'])) {
            return $order;
        }



        if (!$items_from_order = self::get_items_from_order($order)) {
            return $order;
        }

        if (!$items_from_api = self::get_items_from_api($data)) {
            return $order;
        }

        //цикл чтобы удалить часть продуктов
        foreach ($items_from_order as $key => $item_from_order) {

            if (empty($items_from_api[$key])) {
                unset($items_from_order[$key]);
            }
        }

        //цикл чтобы добавить часть продуктов
        foreach ($items_from_api as $uuid => $item_from_api) {
            if (empty($items_from_order[$uuid])) {
                if (!$product_id = wooms_get_product_id_by_uuid($uuid)) {
                    continue;
                }
                if (!$uuid = get_post_meta($product_id, 'wooms_id', true)) {
                    continue;
                }
                $product = wc_get_product($product_id);

                $order_item = new \WC_Order_Item_Product();

                $order_item->set_product($product);
                $order_item->set_order_id($order->get_id());

                if (isset($item_from_api['quantity'])) {
                    $order_item->set_quantity($item_from_api['quantity']);
                }

                $order_item->set_total(11111);
                $order_item->set_subtotal(11111);

                $order->add_item($order_item);
                dd($order_item);

                //XXX что то не работает!!!! надо разобраться!!! 
                // $dd = wooms_request('https://online.moysklad.ru/api/remap/1.2/entity/assortment?filter=id=' . 'efe3834c-b12f-11e9-9ff4-34e80019cfdb');
            }
        }

        // dd($items_from_order);


        // dd($items_from_order);

        //цикл чтобы поменять данные о части продуктов
        //цикл чтобы получить список для сохранения


        // $order->set_


        //XXX
        return $order;
    }

    /**
     * Enable webhooks from MoySklad
     */
    public static function display_wooms_enable_webhooks()
    {
        $option = 'wooms_enable_webhooks';
        printf('<input type="checkbox" name="%s" value="1" %s />', $option, checked(1, get_option($option), false));

        printf(
            '<p><small>%s</small></p>',
            'Передатчик статусов из Мой Склад может работать только с 1 сайтом и только на платных тарифах сервиса Мой склад. Если вы используете платные тарифы, включите данную опцию.'
        );

        self::get_status_order_webhook();
        /*
      if ( get_option( 'wooms_enable_webhooks' ) ) {
          ?>
          <div>
              <hr>
              <div><?php self::get_status_order_webhook() ?></div>
          </div>
          <?php
      } else {
          ?>


          <div><?php self::get_status_order_webhook() ?></div>
          <?php
      }*/
    }

    /**
     * get_status_order_webhook
     */
    public static function get_status_order_webhook()
    {

        $check    = self::check_webhooks_and_try_fix();
        $url      = 'https://online.moysklad.ru/api/remap/1.2/entity/webhook?limit=50';
        $data     = wooms_request($url);

        $webhooks = array();

        if (!empty($data['rows'])) {
            foreach ($data['rows'] as $row) {
                if ($row['url'] == rest_url('/wooms/v1/order-update/')) {
                    $webhooks[$row['id']] = array(
                        'entityType' => $row['entityType'],
                        'url'        => $row['url'],
                        'method'     => $row['method'],
                        'enabled'    => $row['enabled'],
                        'action'     => $row['action'],
                    );
                }
            }
        }

        if (empty(get_option('wooms_enable_webhooks'))) {
            if (empty($webhooks)) {
                echo "Хук на стороне МойСклад отключен в соответствии с настройкой";
            } else {
                echo "Что то пошло не так. Хук на стороне МойСклад остался включен в нарушении настроек.";
            }
        } else {
            if (empty($webhooks)) {
                echo "Что то пошло не так. Хук на стороне МойСклад отключен в нарушении настройки. Попробуйте отключить и включить снова. Если не поможет - обратитесь в техподдержку.";
            } else {
                echo "Хук на стороне МойСклад добавлен в соответствии с настройки";
            }
        }
        echo '<p><small>Ссылка для приема данных из системы МойСклад: ' . rest_url('/wooms/v1/order-update/') . '</small></p>';

        if (!empty($data["errors"][0]["error"])) {
            $msg = $data["errors"][0]["error"];
            printf('<p><strong>%s</strong></p>', $msg);
        }
    }

    /**
     * Check isset hook and fix if not isset
     *
     * @return bool
     */
    public static function check_webhooks_and_try_fix()
    {
        $url  = 'https://online.moysklad.ru/api/remap/1.2/entity/webhook';
        $data = wooms_request($url);

        $webhooks = array();

        if (!empty($data['rows'])) {

            foreach ($data['rows'] as $row) {
                if ($row['entityType'] != 'customerorder') {
                    continue;
                }

                $webhooks[$row['id']] = array(
                    'entityType' => $row['entityType'],
                    'url'        => $row['url'],
                    'method'     => $row['method'],
                    'enabled'    => $row['enabled'],
                    'action'     => $row['action'],
                );
            }
        }


        //Проверка на включение опции и наличия хуков
        if (empty(get_option('wooms_enable_webhooks'))) {

            if (empty($webhooks)) {
                return true;
            } else {
                //пытаемся удалить лишний хук
                foreach ($webhooks as $id => $value) {
                    $url   = 'https://online.moysklad.ru/api/remap/1.2/entity/webhook/' . $id;
                    $check = wooms_request($url, null, 'DELETE');
                }

                return false;
            }
        } else {

            //Если нужного вебхука нет - создаем новый
            if (empty($webhooks)) {
                // создаем веб хук в МойСклад
                $data   = array(
                    'url'        => rest_url('/wooms/v1/order-update/'),
                    'action'     => "UPDATE",
                    "entityType" => "customerorder",
                );
                $result = wooms_request($url, $data);

                if (empty($result)) {
                    return false;
                } else {
                    return true;
                }
            } else {
                return true;
            }
        }
    }


    /**
     * Match statuses from Site to MoySkald
     */
    public static function display_wooms_order_statuses_from_moysklad()
    {

        $option_key = 'wooms_order_statuses_from_moysklad';

        $statuses = wc_get_order_statuses();
        if (empty($statuses) or !is_array($statuses)) {
            printf(
                '<p>%s</p>',
                __('Что то пошло не так, сообщите о проблеме в тех поддержку', 'wooms')
            );
            return;
        }

        printf(
            '<p>%s</p>',
            __('Нужно написать какие статусы указывать в МойСклад, при смене статуса Заказов на Сайте, названия должны совпадать со статусами в МойСклад.', 'wooms')
        );

        $option_value = get_option($option_key);
        if (empty($option_value)) {
            $option_value = array();
        }

        foreach ($statuses as $status_key => $status_name) {

            if (empty($option_value[$status_key])) {
                switch ($status_key) {
                    case 'wc-pending':
                        $option_value[$status_key] = 'Новый';
                        break;

                    case 'wc-processing':
                        $option_value[$status_key] = 'Подтвержден';
                        break;

                    case 'wc-on-hold':
                        $option_value[$status_key] = 'Новый';
                        break;

                    case 'wc-completed':
                        $option_value[$status_key] = 'Отгружен';
                        break;

                    case 'wc-cancelled':
                        $option_value[$status_key] = 'Отменен';
                        break;

                    case 'wc-refunded':
                        $option_value[$status_key] = 'Возврат';
                        break;

                    case 'wc-failed':
                        $option_value[$status_key] = 'Не удался';
                        break;

                    default:
                        $option_value[$status_key] = 'Новый';
                        break;
                }
            }


            printf(
                '<p><input type="text" name="%s[%s]" value="%s" /> > %s (%s)</p>',
                $option_key,
                $status_key,
                $option_value[$status_key],
                $status_name,
                $status_key
            );
        }
    }


    /**
     * Add endpoint /wp-json/wooms/v1/order-update/
     */
    public static function rest_api_init_callback_endpoint()
    {
        register_rest_route('wooms/v1', '/order-update/', array(
            'methods'  => \WP_REST_Server::EDITABLE,
            'callback' => array(__CLASS__, 'get_data_order_from_moysklad'),
        ));
    }


    /**
     * Get data from MoySkald and start update order
     *
     * @param $data_request
     *
     * @return void|WP_REST_Response
     */
    public static function get_data_order_from_moysklad($data_request)
    {

        try {
            $body = $data_request->get_body();
            $data = json_decode($body, true);
            if (empty($data["events"][0]["meta"]["href"])) {
                return;
            }
            $url        = $data["events"][0]["meta"]["href"];
            $data_order = wooms_request($url);
            if (empty($data_order['id'])) {
                return;
            }
            $order_uuid = $data_order['id'];

            $args   = array(
                'numberposts' => 1,
                'post_type'   => 'shop_order',
                'post_status' => 'any',
                'meta_key'    => 'wooms_id',
                'meta_value'  => $order_uuid,
            );
            $orders = get_posts($args);
            if (empty($orders[0]->ID)) {
                return false;
            }
            $order_id = $orders[0]->ID;

            do_action('wooms_order_update_from_moysklad_action', $order_id, $data_order, $order_uuid);

            $order    = wc_get_order($order_id);
            $order = apply_filters('wooms_order_update_from_moysklad_filter', $order, $data_order, $order_uuid);
            $order->save();

            // $state_url  = $data_order["state"]["meta"]["href"];
            // $state_data = wooms_request($state_url);
            // if (empty($state_data['name'])) {
            //     return;
            // }
            // $state_name = $state_data['name'];
            // $result     = self::check_and_update_order_status($order_uuid, $state_name);
            $result     = true;
            if ($result) {
                $response = new \WP_REST_Response(array('success', 'Data received successfully'));
                $response->set_status(200);
                return $response;
            } else {
                throw new \Exception("Заказ не обновился");
            }
        } catch (Exception $e) {
            $response = new \WP_REST_Response(array('fail', $e->getMessage()));
            $response->set_status(500);
            return $response;
        }
    }


    public static function get_items_from_api($data_api = [])
    {
        $items_from_api = [];
        foreach ($data_api['rows'] as $row) {
            //делаем проверку на uuid и сохранение
            $item_data_api = [
                'quantity' => $row['quantity'],
                'price' => $row['price'],
                'discount' => $row['discount'],
                'vat' => $row['vat'],
                'href' => $row['assortment']['meta']['href'],
            ];

            if (!$uuid = self::get_uuid_from_href($row['assortment']['meta']['href'])) {
                continue;
            }

            $items_from_api[$uuid] = $item_data_api;
        }

        if (empty($items_from_api)) {
            return false;
        }

        return $items_from_api;
    }

    public static function get_items_from_order($order = [])
    {
        if (empty($order)) {
            return false;
        }

        $items = $order->get_items();
        $data = [];
        foreach ($items as $item) {

            $wooms_id = $item->get_meta('wooms_id', true);
            if (empty($wooms_id)) {
                $product_id = $item->get_product_id();
                $wooms_id = get_post_meta($product_id, 'wooms_id', true);
            }
            if (empty($wooms_id)) {
                continue;
            }

            if (!is_a($item, 'WC_Order_Item_Product')) {
                continue;
            }

            $data[$wooms_id] = $item;
        }

        if (empty($data)) {
            return false;
        }

        return $data;
    }

    public static function get_uuid_from_href($href = '')
    {

        if (empty($href)) {
            return false;
        }

        $data = explode('/', $href);
        $uuid = array_pop($data);

        return $uuid;
    }

    /**
     * update_order_status
     * 
     * use hook do_action('wooms_order_update_from_moysklad_action', $order, $data_order );
     */
    public static function update_order_status($order_id, $data_order, $order_uuid)
    {
        if (!self::is_enable()) {
            return false;
        }

        $state_url  = $data_order["state"]["meta"]["href"];
        $state_data = wooms_request($state_url);
        if (empty($state_data['name'])) {
            return false;
        }
        $state_name = $state_data['name'];

        $statuses_match_default = array(
            'wc-pending' => 'Новый',
            'wc-processing' => 'Подтвержден',
            'wc-on-hold' => 'Новый',
            'wc-completed' => 'Отгружен',
            'wc-cancelled' => 'Отменен',
            'wc-refunded' => 'Возврат',
            'wc-failed' => 'Не удался',
        );

        $statuses_match = get_option('wooms_order_statuses_from_moysklad', $statuses_match_default);

        // $args   = array(
        //     'numberposts' => 1,
        //     'post_type'   => 'shop_order',
        //     'post_status' => 'any',
        //     'meta_key'    => 'wooms_id',
        //     'meta_value'  => $order_uuid,
        // );
        // $orders = get_posts($args);
        // if (empty($orders[0]->ID)) {
        //     return false;
        // }
        // $order_id = $orders[0]->ID;
        $order    = wc_get_order($order_id);

        $check = false;

        foreach ($statuses_match as $status_key => $status_name) {

            if ($status_name == $state_name) {
                $check = $order->update_status($status_key, sprintf('Выбран статус "%s" через МойСклад', $status_name));
            }
        }

        $check = apply_filters('wooms_order_status_chg', $check, $order, $state_name);
        if ($check) {
            return true;
        } else {
            do_action(
                'wooms_logger_error',
                __CLASS__,
                sprintf('Ошибка обновления статуса "%s", для заказа "%s"', $state_name, $order_id)
            );

            return false;
        }

        // $result     = self::check_and_update_order_status($order_id, $state_name);

        //XXX
        return true;
    }

    /**
     * Update order by data from MoySklad
     *
     * @param $order_uuid
     * @param $state_name
     *
     * @return bool
     */
    public static function check_and_update_order_status($order_id, $state_name)
    {
        //XXX delete
    }

    /**
     * is_enable
     */
    public static function is_enable()
    {
        if (get_option('wooms_enable_webhooks')) {
            return true;
        }

        return false;
    }

    /**
     * Setting
     */
    public static function add_settings()
    {

        register_setting('mss-settings', 'wooms_enable_webhooks');
        add_settings_field(
            $id = 'wooms_enable_webhooks',
            $title = 'Передатчик Статуса и данных по Заказу:<br/> МойСклад > Сайт',
            $callback = array(__CLASS__, 'display_wooms_enable_webhooks'),
            $page = 'mss-settings',
            $section = 'wooms_section_orders'
        );

        if (get_option('wooms_enable_webhooks')) {
            register_setting('mss-settings', 'wooms_order_statuses_from_moysklad');
            add_settings_field(
                $id = 'wooms_order_statuses_from_moysklad',
                $title = 'Связь статусов:<br/> от МойСклад на Сайт',
                $callback = array(__CLASS__, 'display_wooms_order_statuses_from_moysklad',),
                $page = 'mss-settings',
                $section = 'wooms_section_orders'
            );
        }
    }
}

OrderUpdateItemsFromMoySklad::init();
