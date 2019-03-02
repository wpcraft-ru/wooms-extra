<?php

namespace WooMS\Orders;

/**
 * Send orders to MoySklad
 */
class Sender {

    /**
     * WooMS_Orders_Sender constructor.
     */
    public static function init() {

      add_action( 'woomss_tool_actions_btns', array( __CLASS__, 'ui_for_manual_start' ), 15 );
      add_action( 'woomss_tool_actions_wooms_orders_send', array( __CLASS__, 'ui_action' ) );
      add_action( 'wooms_cron_order_sender', array( __CLASS__, 'cron_starter_walker' ) );
      add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_date_picker' ) );
      add_action( 'admin_init', array( __CLASS__, 'settings_init' ), 40);
      add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes_order' ) );

      //Cron
      add_filter( 'cron_schedules', array(__CLASS__, 'add_schedule') );
      add_action('init', [__CLASS__, 'add_cron_hook']);

    }

 /**
  * Регистрируем интервал для wp_cron в секундах
  */
  public static function add_schedule( $schedules ) {

    $schedules['wooms_cron_order_interval'] = array(
      'interval' => apply_filters('wooms_cron_order_interval_chg', 60),
      'display' => 'WooMS Cron Order Interval'
    );

    return $schedules;
  }

  /**
   * add_cron_hook
   */
  public static function add_cron_hook(){

    if ( ! wp_next_scheduled( 'wooms_cron_order_sender' ) ) {
      wp_schedule_event( time(), 'wooms_cron_order_interval', 'wooms_cron_order_sender' );
    }
  }

  /**
   * Start by cron
   */
  public static function cron_starter_walker() {
      if ( empty( get_option( 'wooms_orders_sender_enable' ) ) ) {
          return;
      }

      self::walker();
  }

    /**
     * Main walker for send orders
     */
    public static function walker() {

      $args = array(
          'numberposts'  => apply_filters( 'wooms_orders_number', 5 ),
          'post_type'    => 'shop_order',
          'post_status'  => 'any',
          'meta_key'     => 'wooms_send_timestamp',
          'meta_compare' => 'NOT EXISTS',
      );

      if ( empty( get_option( 'wooms_orders_send_from' ) ) ) {
        $date_from = '2 day ago';
      } else {
        $date_from = get_option( 'wooms_orders_send_from' );
      }

      $args['date_query'] = array(
        'after' => $date_from,
      );

      $orders = get_posts( $args );

      if ( empty( $orders ) ) {
        false;
      }

      $result_list = [];
      foreach ( $orders as $key => $order ) {

        $check = self::send_order( $order->ID );
        if ( false != $check ) {
          update_post_meta( $order->ID, 'wooms_send_timestamp', date( "Y-m-d H:i:s" ) );
          $result_list[] = $order->ID;
        }

      }

      if ( empty( $result_list ) ) {
        false;
      }

      return $result_list;
    }

    /**
     * Send order to moysklad.ru and mark the order as sended
     *
     * @param $order_id
     *
     * @return bool
     */
    public static function send_order( $order_id ) {

        $order = wc_get_order($order_id);

        $data = self::get_data_order_for_moysklad( $order_id );

        if ( empty( $data ) ) {
          $order->update_meta_data( 'wooms_send_timestamp', date( "Y-m-d H:i:s" ) );

          do_action('wooms_logger_error', __CLASS__,
            sprintf('Ошибка подготовки данных по заказу %s', $order_id)
          );

          $logger = wc_get_logger();
          $logger->error(
            wc_print_r(array(
              'Ошибка подготовки данных по заказу: '. $order_id,
              $data
            ), true),
            array( 'source' => 'wooms-errors-orders' )
          );

          return false;
        }

        $url    = 'https://online.moysklad.ru/api/remap/1.1/entity/customerorder';
        $result = wooms_request( $url, $data );

        if ( empty( $result['id'] ) || ! isset( $result['id'] ) || isset( $result['errors'] ) ) {
            update_post_meta( $order_id, 'wooms_send_timestamp', date( "Y-m-d H:i:s" ) );
            $errors = "\n\r" . 'Код ошибки:' . $result['errors'][0]['code'] . "\n\r";
            $errors .= 'Параметр:' . $result['errors'][0]['parameter'] . "\n\r";
            $errors .= $result['errors'][0]['error'];

            $logger = wc_get_logger();
            $logger->error( $errors, array( 'source' => 'wooms-errors-orders' ) );

            do_action('wooms_logger_error', __CLASS__,
              sprintf('Ошибка передачи заказа %s: %s', $order_id, $errors)
            );

            return false;
        }

        $order->update_meta_data( 'wooms_id', $result['id'] );
        $order->save();

        do_action('wooms_logger', __CLASS__,
          sprintf('Заказ %s - отправлен ', $order_id),
          wc_print_r($result, true)
        );

        return true;
    }

    /**
     * Prepare data before send
     *
     * @param $order_id
     *
     * @return array|bool
     */
    public static function get_data_order_for_moysklad( $order_id ) {
      $data = array(
        "name" => self::get_data_name( $order_id ),
      );
      $data['positions'] = self::get_data_positions( $order_id );

      if ( empty( $data['positions'] ) ) {
        do_action('wooms_logger_error', __CLASS__,
          sprintf('Нет позиций для заказа %s', $order_id)
        );

        unset( $data['positions'] );
      }

      $data["organization"] = self::get_data_organization();
      $data["agent"]        = self::get_data_agent( $order_id );
      $data["moment"]       = self::get_date_created_moment( $order_id );
      $data["description"]  = self::get_date_order_description( $order_id );

      return $data;
    }

    /**
     * Get data name for send MoySklad
     *
     * @param $order_id
     *
     * @return string
     */
    public static function get_data_name( $order_id ) {
        $prefix_postfix_name  = get_option( 'wooms_orders_send_prefix_postfix' );
        $prefix_postfix_check = get_option( 'wooms_orders_send_check_prefix_postfix' );
        if ( $prefix_postfix_name ) {
            if ( 'prefix' == $prefix_postfix_check ) {
                $name_order = $prefix_postfix_name . '-' . $order_id;
            } elseif ( 'postfix' == $prefix_postfix_check ) {
                $name_order = $order_id . '-' . $prefix_postfix_name;
            }
        } else {
            $name_order = $order_id;
        }

        return apply_filters( 'wooms_order_name', (string) $name_order );
    }

    /**
     * Get data of positions the order
     *
     * @param $order_id
     *
     * @return array|bool
     */
    public static function get_data_positions( $order_id ) {
        $order = wc_get_order( $order_id );
        $items = $order->get_items();
        if ( empty( $items ) ) {
            return false;
        }
        $data = array();
        foreach ( $items as $key => $item ) {
            if ( $item['variation_id'] != 0 ) {
                $product_id   = $item['variation_id'];
                $product_type = 'variant';
            } else {
                $product_id   = $item["product_id"];
                $product_type = 'product';
            }

            $uuid = get_post_meta( $product_id, 'wooms_id', true );

            if ( empty( $uuid ) ) {
                continue;
            }

            if ( apply_filters( 'wooms_order_item_skip', false, $product_id, $item ) ) {
                continue;
            }

            $price = $item->get_total();
            $quantity = $item->get_quantity();
            if ( empty( get_option( 'wooms_orders_send_reserved' ) ) ) {
                $reserve_qty = $quantity;
            } else {
                $reserve_qty = 0;
            }

            $data[] = array(
                'quantity'   => $quantity,
                'price'      => ( $price / $quantity ) * 100,
                'discount'   => 0,
                'vat'        => 0,
                'assortment' => array(
                    'meta' => array(
                        "href"      => "https://online.moysklad.ru/api/remap/1.1/entity/{$product_type}/" . $uuid,
                        "type"      => "{$product_type}",
                        "mediaType" => "application/json",
                    ),
                ),
                'reserve'    => $reserve_qty,
            );
        }

        return $data;
    }

    /**
     * Get meta for organization
     *
     * @return array|bool
     */
    public static function get_data_organization() {
        $url  = 'https://online.moysklad.ru/api/remap/1.1/entity/organization';
        $data = wooms_request( $url );
        $meta = $data['rows'][0]['meta'];
        if ( empty( $meta ) ) {
            return false;
        }

        return array( 'meta' => $meta );
    }

    /**
     * Get data counterparty for send MoySklad
     *
     * @param $order_id
     *
     * @return array|bool
     */
    public static function get_data_agent( $order_id ) {

        $order = wc_get_order( $order_id );
        $user  = $order->get_user();
        $email = '';
        if ( empty( $user ) ) {
            if ( ! empty( $order->get_billing_email() ) ) {
                $email = $order->get_billing_email();
            }
        } else {
            $email = $user->user_email;
        }

        $name = self::get_data_order_name( $order_id );

        if ( empty( $name ) ) {
            $name = 'Клиент по заказу №' . $order->get_order_number();
        }

        $data = array(
            "name"          => $name,
            "companyType"   => self::get_data_order_company_type( $order_id ),
            "legalAddress"  => self::get_data_order_address( $order_id ),
            "actualAddress" => self::get_data_order_address( $order_id ),
            "phone"         => self::get_data_order_phone( $order_id ),
            "email"         => $email,
        );

        if ( empty( $email ) ) {
            $meta = '';
        } else {
            $meta = self::get_agent_meta_by_email( $email );
        }

        if ( empty( $meta ) ) {
            $url    = 'https://online.moysklad.ru/api/remap/1.1/entity/counterparty';
            $result = wooms_request( $url, $data );
            if ( empty( $result["meta"] ) ) {
                return false;
            }

            $meta = $result["meta"];
        } else {
            $url    = 'https://online.moysklad.ru/api/remap/1.1/entity/counterparty/' . $meta;
            $result = wooms_request( $url, $data, 'PUT' );
            if ( empty( $result["meta"] ) ) {
                return false;
            }

            $meta = $result["meta"];
        }

        return array( 'meta' => $meta );
    }

    /**
     * Get name counterparty from order
     *
     * @param $order_id
     *
     * @return string
     */
    public static function get_data_order_name( $order_id ) {
        $order = wc_get_order( $order_id );
        $name  = $order->get_billing_company();

        if ( empty( $name ) ) {
            $name = $order->get_billing_last_name();
            if ( ! empty( $order->get_billing_first_name() ) ) {
                $name .= ' ' . $order->get_billing_first_name();
            }
        }

        return $name;
    }

    /**
     * Get company type counterparty from order
     *
     * @param $order_id
     *
     * @return string
     */
    public static function get_data_order_company_type( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! empty( $order->get_billing_company() ) ) {
            $company_type = "legal";
        } else {
            $company_type = "individual";
        }

        return $company_type;
    }

    /**
     * Get address counterparty from order
     *
     * @param $order_id
     *
     * @return string
     */
    public static function get_data_order_address( $order_id ) {
        $order   = wc_get_order( $order_id );
        $address = '';

        if ( $order->get_billing_postcode() ) {
            $address .= $order->get_billing_postcode();
        }

        if ( $order->get_billing_state() ) {
            $address .= ', ' . $order->get_billing_state();
        }

        if ( $order->get_billing_city() ) {
            $address .= ', ' . $order->get_billing_city();
        }

        if ( $order->get_billing_address_1() || $order->get_billing_address_2() ) {
            $address .= ', ' . $order->get_billing_address_1();
            if ( ! empty( $order->get_billing_address_2() ) ) {
                $address .= ', ' . $order->get_billing_address_2();
            }
        }

        return $address;
    }

    /**
     * Get phone counterparty from order
     *
     * @param $order_id
     *
     * @return string
     */
    public static function get_data_order_phone( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( $order->get_billing_phone() ) {
            $phone = preg_replace( "/[^0-9]/", '', $order->get_billing_phone() );
        } else {
            $phone = '';
        }

        return $phone;
    }

    /**
     * Get meta by email agent
     *
     * @param string $email
     *
     * @return bool
     */
    public static function get_agent_meta_by_email( $email = '' ) {
        $url_search_agent = 'https://online.moysklad.ru/api/remap/1.1/entity/counterparty?filter=email=' . $email;
        $data_agents      = wooms_request( $url_search_agent );
        if ( empty( $data_agents['rows'][0]['meta'] ) ) {
            return false;
        }

        return $data_agents['rows'][0]['id'];
    }


    /**
     * Get data customerorder date created for send MoySklad
     *
     * @param $order_id
     *
     * @return string
     */
    public static function get_date_created_moment( $order_id ) {
        $order = wc_get_order( $order_id );

        return $order->get_date_created()->date( 'Y-m-d H:i:s' );
    }

    /**
     * Get data customerorder description created for send MoySklad
     *
     * @param $order_id
     *
     * @return string
     */
    public static function get_date_order_description( $order_id ) {
        $order         = wc_get_order( $order_id );
        $customer_note = '';
        if ( $order->get_customer_note() ) {
            $customer_note .= "Комментарий к заказу:\n" . $order->get_customer_note() . "\n\r";
        }
        if ( $order->get_shipping_method() ) {
            $customer_note .= "Метод доставки: " . $order->get_shipping_method() . "\n\r";
        }
        if ( $order->get_payment_method_title() ) {
            $customer_note .= "Метод оплаты: " . $order->get_payment_method_title() . "\n";
            if ( $order->get_transaction_id() ) {
                $customer_note .= "Транзакция №" . $order->get_transaction_id() . "\n";
            }
        }

        $customer_note .= "\n\r" . 'Посмотреть заказ на сайте '. admin_url( 'post.php?post=' . absint( $order->get_order_number() ) . '&action=edit' );

        return $customer_note;
    }


    /**
     * Start manual send orders to MoySklad
     */
    public static function ui_for_manual_start() {

        if ( empty( get_option( 'wooms_orders_sender_enable' ) ) ) {
            return;
        }

        ?>
        <h2>Заказы</h2>
        <p>Для отправки ордеров в МойСклад - нажмите на кнопку</p>
        <p><strong>Внимание!</strong> Отправка новых заказов происходит автоматически раз в минуту.</p>
        <a href="<?php echo add_query_arg( 'a', 'wooms_orders_send', admin_url( 'admin.php?page=moysklad' ) ) ?>" class="button button-primary">Выполнить</a>
        <?php

    }

    /**
     * Setting
     */
    public static function settings_init() {

        add_settings_section( 'wooms_section_orders', 'Заказы - передача в МойСклад', '', 'mss-settings' );

        register_setting( 'mss-settings', 'wooms_orders_sender_enable' );
        add_settings_field(
          $id = 'wooms_orders_sender_enable',
          $title = 'Включить синхронизацию заказов в МойСклад',
          $callback = array( __CLASS__, 'display_wooms_orders_sender_enable' ),
          $page = 'mss-settings',
          $section = 'wooms_section_orders'
        );

        register_setting( 'mss-settings', 'wooms_orders_send_from' );
        add_settings_field(
          $id = 'wooms_orders_send_from',
          $title = 'Дата, с которой берутся Заказы для отправки',
          $callback = array( __CLASS__, 'display_wooms_orders_send_from' ),
          $page = 'mss-settings',
          $section = 'wooms_section_orders'
        );

        register_setting( 'mss-settings', 'wooms_orders_send_prefix_postfix' );
        add_settings_field(
          $id = 'wooms_orders_send_prefix_postfix',
          $title = 'Префикс или постфикс к номеру заказа',
          $callback = array( __CLASS__, 'display_wooms_orders_send_prefix_postfix' ),
          $page = 'mss-settings',
          $section = 'wooms_section_orders'
        );

        register_setting( 'mss-settings', 'wooms_orders_send_check_prefix_postfix' );
        add_settings_field(
          $id = 'wooms_orders_send_check_prefix_postfix',
          $title = 'Использовать как префикс или как постфикс',
          $callback = array( __CLASS__, 'display_wooms_orders_send_check_prefix_postfix' ),
          $page = 'mss-settings',
          $section = 'wooms_section_orders'
        );

        register_setting( 'mss-settings', 'wooms_orders_send_reserved' );
        add_settings_field(
          $id = 'wooms_orders_send_reserved',
          $title = 'Выключить резервирование товаров',
          $callback = array( __CLASS__, 'display_wooms_orders_send_reserved' ),
          $page = 'mss-settings',
          $section = 'wooms_section_orders'
        );
    }

    /**
     * Send statuses from MoySklad
     */
    public static function display_wooms_orders_sender_enable() {
        $option = 'wooms_orders_sender_enable';
        printf( '<input type="checkbox" name="%s" value="1" %s />', $option, checked( 1, get_option( $option ), false ) );

    }

    /**
     *
     */
    public static function display_wooms_orders_send_from() {
        $option_key = 'wooms_orders_send_from';
        printf( '<input type="text" name="%s" value="%s" />', $option_key, get_option( $option_key ) );
        echo '<p><small>Если дата не выбрана, то берутся заказы сегодняшнего и вчерашнего дня. Иначе берутся все новые заказы с указанной даты.</small></p>';
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function () {
                jQuery('input[name=wooms_orders_send_from]').datepicker();
            });
        </script>
        <?php
    }

    /**
     *
     */
    public static function display_wooms_orders_send_prefix_postfix() {
        $option_key = 'wooms_orders_send_prefix_postfix';
        printf( '<input type="text" name="%s" value="%s" />', $option_key, get_option( $option_key ) );
        echo '<p><small>Укажите тут уникальную приставку к номеру заказа. Например - IMP</small></p>';
    }

    /**
     *
     */
    public static function display_wooms_orders_send_check_prefix_postfix() {
        $selected_prefix_postfix = get_option( 'wooms_orders_send_check_prefix_postfix' );
        ?>
        <select class="check_prefix_postfix" name="wooms_orders_send_check_prefix_postfix">
            <?php
            printf( '<option value="%s" %s>%s</option>', 'prefix', selected( 'prefix', $selected_prefix_postfix, false ), 'перед номером заказа' );
            printf( '<option value="%s" %s>%s</option>', 'postfix', selected( 'postfix', $selected_prefix_postfix, false ), 'после номера заказа' );
            ?>
        </select>
        <?php
        echo '<p><small>Выберите как выводить уникальную приставку: перед номером заказа (префикс) или после номера заказа (постфикс)</small></p>';
    }

    /**
     *
     */
    public static function display_wooms_orders_send_reserved() {
        $option = 'wooms_orders_send_reserved';
        $desc   = '<small>При включении данной настройки, резеревирование товаров на складе будет отключено</small>';
        printf( '<input type="checkbox" name="%s" value="1" %s /> %s', $option, checked( 1, get_option( $option ), false ), $desc );

    }

    /**
     * UI for manual start send data of order
     */
    public static function ui_action() {
        $result_list = self::walker();
        echo '<br/><hr>';
        if ( empty( $result_list ) ) {
            printf( '<p>Все <strong>новые</strong> заказы уже переданы в МойСклад. Если их там нет, то сообщите в <a href="%s" target="_blank">тех поддержку</a>.</p>', '//wpcraft.ru/contacts/' );
        } else {
            foreach ( $result_list as $key => $value ) {
                printf( '<p>Передан заказ <a href="%s">№%s</a></p>', get_edit_post_link( $value ), $value );
            }
        }
    }

    /**
     * Add metaboxes
     */
    public static function add_meta_boxes_order() {
        add_meta_box( 'metabox_order', 'МойСклад', array( __CLASS__, 'add_meta_box_data_order' ), 'shop_order', 'side', 'low' );
    }

    /**
     * Meta box in order
     */
    public static function add_meta_box_data_order() {
        $post    = get_post();
        $data_id = get_post_meta( $post->ID, 'wooms_id', true );
        if ( $data_id ) {
            $meta_data = sprintf( '<div>ID заказа в МойСклад: <div><strong>%s</strong></div></div>', $data_id );
            $meta_data .= sprintf( '<p><a href="https://online.moysklad.ru/app/#customerorder/edit?id=%s" target="_blank">Посмотреть заказ в МойСклад</a></p>', $data_id );
        } else {
            $meta_data = 'Заказ не передан в МойСклад';
            $meta_data .= sprintf( '<p><a href="%s">Отправить в МойСклад</a></p>', admin_url( 'admin.php?page=moysklad' ) );
        }
        echo $meta_data;

    }

    /**
     * Add jQuery date picker for select start-date sending orders to MoySklad
     */
    public static function enqueue_date_picker() {
        $screen = get_current_screen();
        if ( empty( $screen->id ) or 'settings_page_mss-settings' != $screen->id ) {
            return;
        }
        wp_enqueue_script( 'jquery-ui-datepicker' );
        $wp_scripts = wp_scripts();
        wp_enqueue_style( 'jquery-ui', '//ajax.googleapis.com/ajax/libs/jqueryui/' . $wp_scripts->registered['jquery-ui-core']->ver .
                                       '/themes/flick/jquery-ui.css', false, $wp_scripts->registered['jquery-ui-core']->ver, false );
    }

}

Sender::init();
