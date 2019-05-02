<?php

namespace WooMS\Orders;

/**
 * Send orders to MoySklad
 */
class Sender {

  /**
   * The init
   */
  public static function init()
  {
    add_action( 'wooms_cron_order_sender', array( __CLASS__, 'cron_starter_walker' ) );
    add_action( 'admin_init', array( __CLASS__, 'settings_init' ), 40);

    //Cron
    add_filter( 'cron_schedules', array(__CLASS__, 'add_schedule') );
    add_action( 'init', array(__CLASS__, 'add_cron_hook'));

    add_action( 'save_post', array(__CLASS__, 'save_data_form'));
    add_action( 'add_meta_boxes', function(){
      add_meta_box( 'metabox_order', 'МойСклад', array( __CLASS__, 'display_metabox' ), 'shop_order', 'side', 'low' );
    });

    add_action( 'woocommerce_new_order', array(__CLASS__, 'auto_add_order_for_send'), 20 );

  }

  /**
   * Auto add meta for send order by cron
   */
  public static function auto_add_order_for_send( $order_id )
  {
  	if(get_option('wooms_orders_sender_enable')){
      $order = wc_get_order($order_id);
      $order->add_meta_data('wooms_order_sync', 1);
      $order->save();
    }
  }

  /**
   * Description
   */
  public static function save_data_form($post_id)
  {
    if ( "shop_order" != get_post_type($post_id) ) {
      return;
    }

    if ( wp_is_post_revision( $post_id ) ){
      return;
    }

    if( ! isset($_POST['wooms_order_sync'])){
      return;
    }

    $order_id = $post_id;
    if(empty($_POST['wooms_order_sync'])){
      delete_post_meta($order_id, 'wooms_order_sync');
    } else {
      update_post_meta($order_id, 'wooms_order_sync', 1);
      self::update_order($order_id);
    }
  }

  /**
   * update_order
   */
  public static function update_order($order_id)
  {
    $order = wc_get_order($order_id);
    $wooms_id = $order->get_meta('wooms_id', true);

    /**
     * Send order if no wooms_id
     */
    if(empty($wooms_id)){
      $check = self::send_order($order_id);
      if($check){
        // delete_post_meta($order_id, 'wooms_order_sync');
        $order->delete_meta_data('wooms_order_sync');
        $order->save();
        return true;
      } else {
        return false;
      }
    }

    /**
     * Preparation the data for update an existing order
     */
    $data = array(
      "name" => self::get_data_name( $order_id ),
    );
    $data['positions'] = self::get_data_positions( $order_id );

    if(empty($data['positions'])){
      do_action('wooms_logger_error', __CLASS__,
        sprintf('При передаче Заказа %s - нет позиций', $order_id)
      );
    }

    $data = apply_filters('wooms_order_data', $data, $order_id);
    $data = apply_filters('wooms_order_update_data', $data, $order_id);

    $url = 'https://online.moysklad.ru/api/remap/1.1/entity/customerorder/' . $wooms_id;
    $result = wooms_request( $url, $data, 'PUT' );

    if(empty($result["id"])){
      do_action('wooms_logger_error', __CLASS__,
        sprintf('При передаче Заказа %s - данные не переданы', $order_id)
      );
    } else {
      $order->delete_meta_data('wooms_order_sync');
      $order->save();
      return true;
    }

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
    public static function walker()
    {
      $args = array(
        'numberposts'  => apply_filters( 'wooms_orders_number', 5 ),
        'post_type'    => 'shop_order',
        'post_status'  => 'any',
        'meta_key'     => 'wooms_order_sync',
        'meta_compare' => 'EXISTS',
      );

      $orders = get_posts( $args );

      if ( empty( $orders ) ) {
        return false;
      }

      do_action('wooms_logger', __CLASS__,
        sprintf('Старт очереди отправки заказов - %s', date( "Y-m-d H:i:s" ))
      );

      $result_list = [];
      foreach ( $orders as $order ) {
        $check = self::update_order( $order->ID );
        // $check = self::send_order( $order->ID );
        if ( false != $check ) {
          update_post_meta( $order->ID, 'wooms_send_timestamp', date( "Y-m-d H:i:s" ) );
          $result_list[] = $order->ID;
        }
      }

      if ( empty( $result_list ) ) {
        return false;
      }

      return $result_list;
    }

    /**
     * Send order to moysklad.ru and mark the order as sended
     */
    public static function send_order( $order_id ) {

        $order = wc_get_order($order_id);

        $data = self::get_data_order_for_moysklad( $order_id );

        if ( empty( $data ) ) {
          $order->update_meta_data( 'wooms_send_timestamp', date( "Y-m-d H:i:s" ) );

          do_action('wooms_logger_error', __CLASS__,
            sprintf('Ошибка подготовки данных по заказу %s', $order_id)
          );

          return false;
        }

        $data = apply_filters('wooms_order_data', $data, $order_id);
        $data = apply_filters('wooms_order_send_data', $data, $order_id);

        $url    = 'https://online.moysklad.ru/api/remap/1.1/entity/customerorder';
        $result = wooms_request( $url, $data );

        if ( empty( $result['id'] ) || ! isset( $result['id'] ) || isset( $result['errors'] ) ) {
            update_post_meta( $order_id, 'wooms_send_timestamp', date( "Y-m-d H:i:s" ) );
            $errors = "\n\r" . 'Код ошибки:' . $result['errors'][0]['code'] . "\n\r";
            $errors .= 'Параметр:' . $result['errors'][0]['parameter'] . "\n\r";
            $errors .= $result['errors'][0]['error'];

            do_action('wooms_logger_error', __CLASS__,
              sprintf('Ошибка передачи заказа %s: %s', $order_id, $errors)
            );

            return false;
        }

        $order->update_meta_data( 'wooms_id', $result['id'] );
        $order->delete_meta_data('wooms_order_sync');

        $order->save();

        if(empty($result['positions'])){
          $positions_count = 0;
        } else {
          $positions_count = count($result['positions']);
        }

        if($positions_count == 0){
          do_action('wooms_logger_error', __CLASS__,
            sprintf('В заказе %s передано 0 позиций', $order_id, $positions_count)
          );
        }

        do_action('wooms_logger', __CLASS__,
          sprintf('Заказ %s - отправлен (позиций: %s)', $order_id, $positions_count)
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
    public static function get_data_order_for_moysklad( $order_id )
    {
      $data = array(
        "name" => self::get_data_name( $order_id ),
      );
      $data['positions'] = self::get_data_positions( $order_id );

      if ( empty( $data['positions'] ) ) {
        do_action('wooms_logger_error', __CLASS__,
          sprintf('Нет позиций для заказа %s', $order_id)
        );

        unset( $data['positions'] );
        return false;
      }

      if($meta_organization = self::get_data_organization()){
        $data["organization"] = $meta_organization;
      } else {
        return false;
      }

      $data["agent"]        = self::get_data_agent( $order_id );
      $data["moment"]       = self::get_date_created_moment( $order_id );
      $data["description"]  = self::get_date_order_description( $order_id );

      return $data;
    }

    /**
     * Get data name for send MoySklad
     */
    public static function get_data_name( $order_id )
    {
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
        // echo '<pre>';
        // var_dump($item); exit;

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
     */
    public static function get_data_organization()
    {
      $url  = 'https://online.moysklad.ru/api/remap/1.1/entity/organization';
      $data = wooms_request( $url );

      if ( empty( $data['rows'][0]['meta'] ) ) {
        do_action('wooms_logger_error', __CLASS__,
          'Нет юр лица в базе для отправки Заказа'
        );
        return false;
      }

      $meta = '';
      if( $org_name_site = get_option('wooms_org_name') ) {
        foreach ($data['rows'] as $row) {
          if($org_name_site == $row['name']){
            $meta = $row["meta"];
          }
        }

        if(empty($meta)){
          do_action('wooms_logger_error', __CLASS__,
            sprintf('Для указанного наименования юр лица не найдены данные в МойСклад: %s', $org_name_site)
          );
        }
      }

      if( empty($meta) ){
        $meta = $data['rows'][0]['meta'];
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
     * Setting
     */
    public static function settings_init() {

        add_settings_section( 'wooms_section_orders', 'Заказы - передача в МойСклад', '', 'mss-settings' );

        register_setting( 'mss-settings', 'wooms_orders_sender_enable' );
        add_settings_field(
          $id = 'wooms_orders_sender_enable',
          $title = 'Включить автоматическую синхронизацию заказов в МойСклад',
          $callback = array( __CLASS__, 'display_wooms_orders_sender_enable' ),
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

        register_setting( 'mss-settings', 'wooms_org_name' );
        add_settings_field(
          $id = 'wooms_org_name',
          $title = 'Наименование юр лица для Заказов',
          $callback = array( __CLASS__, 'display_wooms_org_name' ),
          $page = 'mss-settings',
          $section = 'wooms_section_orders'
        );
    }

    /**
     * display_wooms_org_name
     */
    public static function display_wooms_org_name(){
      $option_key = 'wooms_org_name';
      printf( '<input type="text" name="%s" value="%s" />', $option_key, get_option( $option_key ) );
      printf(
        '<p><small>%s</small></p>',
        'Тут можно указать краткое наименование юр лица из МойСклад. Если пусто, то берется первое из списка. Иначе будет выбор указанного юр лица.'
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
     * display_wooms_orders_send_prefix_postfix
     */
    public static function display_wooms_orders_send_prefix_postfix() {
        $option_key = 'wooms_orders_send_prefix_postfix';
        printf( '<input type="text" name="%s" value="%s" />', $option_key, get_option( $option_key ) );
        echo '<p><small>Укажите тут уникальную приставку к номеру заказа. Например - IMP</small></p>';
    }

    /**
     * display_wooms_orders_send_check_prefix_postfix
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
     * display_wooms_orders_send_reserved
     */
    public static function display_wooms_orders_send_reserved() {
        $option = 'wooms_orders_send_reserved';
        $desc   = '<small>При включении данной настройки, резеревирование товаров на складе будет отключено</small>';
        printf( '<input type="checkbox" name="%s" value="1" %s /> %s', $option, checked( 1, get_option( $option ), false ), $desc );
    }

    /**
     * Meta box in order
     */
    public static function display_metabox()
    {
        $post    = get_post();
        $data_id = get_post_meta( $post->ID, 'wooms_id', true );
        if ( $data_id ) {
            $meta_data = sprintf( '<div>ID заказа в МойСклад: <div><strong>%s</strong></div></div>', $data_id );
            $meta_data .= sprintf( '<p><a href="https://online.moysklad.ru/app/#customerorder/edit?id=%s" target="_blank">Посмотреть заказ в МойСклад</a></p>', $data_id );
        } else {
            $meta_data = 'Заказ не передан в МойСклад';
        }
        echo $meta_data;

        $need_update = get_post_meta($post->ID, 'wooms_order_sync', true);
        echo '<hr/>';
        printf(
          '<input id="wooms-order-sync" type="checkbox" name="wooms_order_sync" %s>
          <label for="wooms-order-sync">%s</label>',
          checked($need_update, 1, false),
          'Синхронизировать'
        );

    }

}

Sender::init();
