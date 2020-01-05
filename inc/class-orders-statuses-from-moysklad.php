<?php

namespace WooMS\Orders;

use Exception;

/**
 * Send statuses from moysklad.ru to WooCommerce
 */
class Statuses_From_MoySklad {

  /**
   * The init
   */
  public static function init(){
    add_action( 'rest_api_init', array( __CLASS__, 'rest_api_init_callback_endpoint' ) );

    add_action( 'admin_init', array( __CLASS__, 'settings_init' ), 100);
  }

  /**
   * Setting
   */
  public static function settings_init() {

      register_setting( 'mss-settings', 'wooms_enable_webhooks' );
      add_settings_field(
        $id = 'wooms_enable_webhooks',
        $title = 'Передатчик Статуса:<br/> МойСклад > Сайт',
        $callback = array( __CLASS__, 'display_wooms_enable_webhooks' ),
        $page = 'mss-settings',
        $section = 'wooms_section_orders'
      );

      if(get_option('wooms_enable_webhooks')){
        register_setting( 'mss-settings', 'wooms_order_statuses_from_moysklad' );
        add_settings_field(
            $id = 'wooms_order_statuses_from_moysklad',
            $title = 'Связь статусов:<br/> от МойСклад на Сайт',
            $callback = array(__CLASS__, 'display_wooms_order_statuses_from_moysklad', ),
            $page = 'mss-settings',
            $section = 'wooms_section_orders'
        );
      }

  }

  /**
   * Enable webhooks from MoySklad
   */
  public static function display_wooms_enable_webhooks() {
      $option = 'wooms_enable_webhooks';
      printf( '<input type="checkbox" name="%s" value="1" %s />', $option, checked( 1, get_option( $option ), false ) );

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
  public static function get_status_order_webhook() {

      $check    = self::check_webhooks_and_try_fix();
      $url      = 'https://online.moysklad.ru/api/remap/1.1/entity/webhook?limit=50';
      $data     = wooms_request( $url );

      $webhooks = array();

      if( ! empty($data['rows']) ){
        foreach ( $data['rows'] as $row ) {
          if ( $row['url'] == rest_url( '/wooms/v1/order-update/' ) ) {
            $webhooks[ $row['id'] ] = array(
              'entityType' => $row['entityType'],
              'url'        => $row['url'],
              'method'     => $row['method'],
              'enabled'    => $row['enabled'],
              'action'     => $row['action'],
            );
          }
        }
      }

      if ( empty( get_option( 'wooms_enable_webhooks' ) ) ) {
          if ( empty( $webhooks ) ) {
              echo "Хук на стороне МойСклад отключен в соответствии с настройкой";
          } else {
              echo "Что то пошло не так. Хук на стороне МойСклад остался включен в нарушении настроек.";
          }
      } else {
          if ( empty( $webhooks ) ) {
              echo "Что то пошло не так. Хук на стороне МойСклад отключен в нарушении настройки. Попробуйте отключить и включить снова. Если не поможет - обратитесь в техподдержку.";
          } else {
              echo "Хук на стороне МойСклад добавлен в соответствии с настройки";
          }
      }
      echo '<p><small>Ссылка для приема данных из системы МойСклад: ' . rest_url( '/wooms/v1/order-update/' ) . '</small></p>';

      if(!empty($data["errors"][0]["error"])){
          $msg = $data["errors"][0]["error"];
          printf('<p><strong>%s</strong></p>', $msg);
      }

  }

  /**
   * Check isset hook and fix if not isset
   *
   * @return bool
   */
  public static function check_webhooks_and_try_fix() {
      $url  = 'https://online.moysklad.ru/api/remap/1.1/entity/webhook';
      $data = wooms_request( $url );

      $webhooks = array();

      if ( ! empty( $data['rows'] ) ) {

        foreach ( $data['rows'] as $row ) {
            if ( $row['entityType'] != 'customerorder' ) {
                continue;
            }

            $webhooks[ $row['id'] ] = array(
                'entityType' => $row['entityType'],
                'url'        => $row['url'],
                'method'     => $row['method'],
                'enabled'    => $row['enabled'],
                'action'     => $row['action'],
            );
        }
      }


      //Проверка на включение опции и наличия хуков
      if ( empty( get_option( 'wooms_enable_webhooks' ) ) ) {

          if ( empty( $webhooks ) ) {
              return true;
          } else {
              //пытаемся удалить лишний хук
              foreach ( $webhooks as $id => $value ) {
                  $url   = 'https://online.moysklad.ru/api/remap/1.1/entity/webhook/' . $id;
                  $check = wooms_request( $url, null, 'DELETE' );
              }

              return false;
          }
      } else {

          //Если нужного вебхука нет - создаем новый
          if ( empty( $webhooks ) ) {
              // создаем веб хук в МойСклад
              $data   = array(
                  'url'        => rest_url( '/wooms/v1/order-update/' ),
                  'action'     => "UPDATE",
                  "entityType" => "customerorder",
              );
              $result = wooms_request( $url, $data );

              if ( empty( $result ) ) {
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
  public static function display_wooms_order_statuses_from_moysklad() {

      $option_key = 'wooms_order_statuses_from_moysklad';

      $statuses = wc_get_order_statuses();
      if(empty($statuses) or ! is_array($statuses)){
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

      $option_value = get_option( $option_key );
      if(empty($option_value)){
          $option_value = array();
      }

      foreach ($statuses as $status_key => $status_name) {

          if( empty($option_value[$status_key]) ){
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
              $option_key, $status_key, $option_value[$status_key], $status_name, $status_key
          );
      }
  }


  /**
   * Add endpoint /wp-json/wooms/v1/order-update/
   */
  public static function rest_api_init_callback_endpoint() {
      register_rest_route( 'wooms/v1', '/order-update/', array(
          'methods'  => \WP_REST_Server::EDITABLE,
          'callback' => array( __CLASS__, 'get_data_order_from_moysklad' ),
      ) );
  }


  /**
   * Get data from MoySkald and start update order
   *
   * @param $data_request
   *
   * @return void|WP_REST_Response
   */
  public static function get_data_order_from_moysklad( $data_request ) {

      try {
          $body = $data_request->get_body();
          $data = json_decode( $body, true );
          if ( empty( $data["events"][0]["meta"]["href"] ) ) {
              return;
          }
          $url        = $data["events"][0]["meta"]["href"];
          $data_order = wooms_request( $url );
          if ( empty( $data_order['id'] ) ) {
              return;
          }
          $order_uuid = $data_order['id'];
          $state_url  = $data_order["state"]["meta"]["href"];
          $state_data = wooms_request( $state_url );
          if ( empty( $state_data['name'] ) ) {
              return;
          }
          $state_name = $state_data['name'];
          $result     = self::check_and_update_order_status( $order_uuid, $state_name );
          if ( $result ) {
              $response = new \WP_REST_Response( array( 'success', 'Data received successfully' ) );
              $response->set_status( 200 );
              return $response;
          } else {
              throw new \Exception( "Заказ не обновился" );
          }
      } catch ( Exception $e ) {
          $response = new \WP_REST_Response( array( 'fail', $e->getMessage() ) );
          $response->set_status( 500 );
          return $response;
      }
  }

  /**
   * Update order by data from MoySklad
   *
   * @param $order_uuid
   * @param $state_name
   *
   * @return bool
   */
  public static function check_and_update_order_status( $order_uuid, $state_name ) {

      $statuses_match_default = array(
          'wc-pending' => 'Новый',
          'wc-processing' => 'Подтвержден',
          'wc-on-hold' => 'Новый',
          'wc-completed' => 'Отгружен',
          'wc-cancelled' => 'Отменен',
          'wc-refunded' => 'Возврат',
          'wc-failed' => 'Не удался',
      );

      $statuses_match = get_option('wooms_order_statuses_match', $statuses_match_default);

      $args   = array(
          'numberposts' => 1,
          'post_type'   => 'shop_order',
          'post_status' => 'any',
          'meta_key'    => 'wooms_id',
          'meta_value'  => $order_uuid,
      );
      $orders = get_posts( $args );
      if ( empty( $orders[0]->ID ) ) {
          return false;
      }
      $order_id = $orders[0]->ID;
      $order    = wc_get_order( $order_id );
      switch ( $state_name ) {
          case $statuses_match['wc-pending']:
              $check = $order->update_status( 'pending', 'Выбран статус "Новый" через МойСклад' );
              break;
          case $statuses_match['wc-processing']:
              $check = $order->update_status( 'processing', 'Выбран статус "Подтвержден" через МойСклад' );
              break;
          case $statuses_match['wc-on-hold']:
              $check = $order->update_status( 'on-hold', 'Выбран статус "На удержании" через МойСклад' );
              break;
          case $statuses_match['wc-cancelled']:
              $check = $order->update_status( 'cancelled', 'Отменен через МойСклад' );
              break;
          case $statuses_match['wc-failed']:
              $check = $order->update_status( 'failed', 'Выбран статус "Не удался" через МойСклад' );
              break;
          case $statuses_match['wc-refunded']:
              $check = $order->update_status( 'refunded', 'Статус "Возврат" через МойСклад' );
              break;
          case $statuses_match['wc-completed']:
              $check = $order->update_status( 'completed', 'Выбран статус "Отгружен" через МойСклад' );
              break;
          default:
              $check = false;
      }
      $check = apply_filters( 'wooms_order_status_chg', $check, $order, $state_name );
      if ( $check ) {
          return true;
      } else {
        do_action('wooms_logger_error', __CLASS__,
          sprintf('Ошибка обновления статуса "%s", для заказа "%s"', $state_name, $order_id)
        );

        return false;
      }
  }

}

Statuses_From_MoySklad::init();
