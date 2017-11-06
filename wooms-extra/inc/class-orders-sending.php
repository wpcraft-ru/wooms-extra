<?php

/**
 * Send orders to MoySklad
 */
class WooMS_Orders_Sender  {

  function __construct(){
    add_action( 'admin_init', array($this, 'settings_init'), 100 );

    add_action('woomss_tool_actions_btns', [$this, 'ui_for_manual_start'], 15);
    add_action('woomss_tool_actions_wooms_orders_send', [$this, 'ui_action']);

    add_action('wooms_cron_order_sender',[$this, 'cron_starter_walker']);

  }


  function ui_action(){
    $result_list = $this->walker();

    echo '<br/><hr>';

    if(empty($result_list)){
      echo "<p>Нет заказов для передачи в МойСклад</p>";
    } else {
      foreach ($result_list as $key => $value) {
        printf('<p>Передан заказ <a href="%s">№%s</a></p>', get_edit_post_link($value), $value);
      }
    }
  }

  function walker(){
    $args = array(
      'post_type' => 'shop_order',
      'post_status' => 'any',
      'meta_key' => 'wooms_send_timestamp',
      'meta_compare' => 'NOT EXISTS',
    );
    $orders = get_posts($args);

    $result_list = [];
    foreach ($orders as $key => $order) {
      $check = $this->send_order($order->ID);

      if($check){
        update_post_meta($order->ID, 'wooms_send_timestamp', date("Y-m-d H:i:s"));
        $result_list[] = $order->ID;
      }
    }

    if( ! empty($result_list)){
      return $result_list;
    } else {
      false;
    }

  }

  function send_order($order_id){

    $data = $this->get_data_order_for_moysklad($order_id);

    $url = 'https://online.moysklad.ru/api/remap/1.1/entity/customerorder';

    $result = $this->send_data($url, $data);


    if(empty($result['id'])){
      return false;
    }

    update_post_meta($order_id, 'wooms_id', $result['id']);



    return true;
  }



  function get_data_order_for_moysklad($order_id){
    $data = [
      "name" => apply_filters('wooms_order_name', (string)$order_id),
    ];

    $data["organization"] = $this->get_data_organization();

    $data["agent"] = $this->get_data_agent($order_id);

    $data['positions'] = $this->get_data_positions($order_id);

    return $data;

  }

  function get_data_positions($order_id){

    $order = wc_get_order($order_id);
    $items = $order->get_items();

    if(empty($items)){
      return false;
    }

    $data = [];
    foreach ($items as $key => $item) {

      $product_id = $item["product_id"];
      $uuid = get_post_meta($product_id, 'wooms_id', true);

      if(empty($uuid)){
        continue;
      }

      if(apply_filters('wooms_order_item_skip', false, $product_id, $item)){
        continue;
      }

      $price = $item->get_total();

      $quantity = $item->get_quantity();

      $data[] = [
        'quantity' => $quantity,
        'price' => ($price / $quantity) * 100,
        'discount' => 0,
        'vat' => 0,
        'assortment' => [
          'meta' => [
            "href" => "https://online.moysklad.ru/api/remap/1.1/entity/product/" . $uuid,
            "type" => "product",
            "mediaType" => "application/json",
          ]
        ],
        'reserve' => 0
      ];
    }

    if(empty($data)){
      return false;
    } else {
      return $data;
    }

  }


  function get_data_agent($order_id){

    $order = wc_get_order($order_id);

    $user = $order->get_user();

    if( ! empty($user)){
      // for exist user
    }

    $name = $order->get_billing_company();
    if(empty($name)){
      $name = $order->get_billing_last_name();
      if( ! empty($order->get_billing_first_name())){
        $name .= ' ' . $order->get_billing_first_name();
      }
    }

    if(empty($name)){
      $name = "Клиент по заказу №" . $order_id;
    }

    $data = [
      "name" => $name,
    ];

    if( ! empty($order->get_billing_email()) ){
      $data["email"] = $order->get_billing_email();
    }

    $url = 'https://online.moysklad.ru/api/remap/1.1/entity/counterparty';

    $result = $this->send_data($url, $data);

    if(empty($result["meta"])){
      return false;
    }

    $meta = $result["meta"];
    return array('meta' => $meta);

  }

  function get_data_organization(){
    $url = 'https://online.moysklad.ru/api/remap/1.1/entity/organization';
    $data = wooms_get_data_by_url($url);

    if(empty($data['rows'][0]['meta'])){
      return false;
    } else {
      $meta = $data['rows'][0]['meta'];
      return array('meta' => $meta);
    }
  }

  function ui_for_manual_start(){

    if( empty(get_option('woomss_images_sync_enabled')) ){
      return;
    }

    ?>
    <h2>Отправка заказов в МойСклад</h2>
    <p>Для отправки ордеров в МойСклад - нажмите на кнопку</p>
    <a href="<?php echo add_query_arg('a', 'wooms_orders_send', admin_url('tools.php?page=moysklad')) ?>" class="button">Выполнить</a>
    <?php
  }



  function settings_init(){

    register_setting('mss-settings', 'wooms_orders_sender_enable');
    add_settings_field(
      $id = 'wooms_orders_sender_enable',
      $title = 'Включить синхронизацию заказов в МойСклад',
      $callback = [$this, 'wooms_orders_sender_enable_display_field'],
      $page = 'mss-settings',
      $section = 'woomss_section_other'
    );
  }

  function wooms_orders_sender_enable_display_field(){
    $option = 'wooms_orders_sender_enable';
    printf('<input type="checkbox" name="%s" value="1" %s />', $option, checked( 1, get_option($option), false ));

  }


  function send_data($url, $data){

    if(is_array($data)){
      $data = json_encode( $data );
    } else {
      return false;
    }

    $args = array(
      'timeout' => 45,
      'headers' => array(
        "Content-Type" => 'application/json',
        'Authorization' => 'Basic ' . base64_encode( get_option( 'woomss_login' ) . ':' . get_option( 'woomss_pass' ) )
      ),
      'body' => $data
    );

    $response = wp_remote_post($url, $args);
    $response_code = wp_remote_retrieve_response_code( $response );
    $response_body = wp_remote_retrieve_body( $response );
    $result = json_decode( $response_body, TRUE );

    if(empty($result)){
      return false;
    } else {
      return $result;
    }

  }

  //Start by cron
  function cron_starter_walker(){
    $this->walker();
  }


}

new WooMS_Orders_Sender;
