<?php

/**
 * Send orders to MoySklad
 */
class WooMS_Orders_Sender
{

  function __construct(){
    add_action( 'admin_init', array($this, 'settings_init'), 100 );

    add_action('woomss_tool_actions_btns', array($this, 'ui_for_manual_start'), 15);
    add_action('woomss_tool_actions_wooms_orders_send', array($this, 'ui_action'));

    add_action('wooms_cron_order_sender', array($this, 'cron_starter_walker'));

    add_action( 'admin_enqueue_scripts', array($this, 'enqueue_date_picker') );

  }

  function walker(){



    $args = array(
      'numberposts' => apply_filters('wooms_orders_number', 5),
      'post_type' => 'shop_order',
      'post_status' => 'any',
      'meta_key' => 'wooms_send_timestamp',
      'meta_compare' => 'NOT EXISTS',
    );

    if(empty(get_option('wooms_orders_send_from'))){
      $date_from = '2 day ago';
    } else {
      $date_from = get_option('wooms_orders_send_from');
    }

    $args['date_query'] = array(
      'after' => $date_from
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

    if(empty($data)){
      update_post_meta($order_id, 'wooms_send_timestamp', date("Y-m-d H:i:s"));
      return false;
    }

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

    $data['positions'] = $this->get_data_positions($order_id);
    if(empty($data['positions'])){
      return false;
    }

    $data["organization"] = $this->get_data_organization();

    $data["agent"] = $this->get_data_agent($order_id);


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

  /**
  * Получаем данные об контрагенте для передачи в МойСклад
  */
  function get_data_agent($order_id){

    $order = wc_get_order($order_id);

    $user = $order->get_user();

    $email = '';

    if( empty($user)){
      if( ! empty($order->get_billing_email()) ){
        $email = $order->get_billing_email();
      }
    } else {
      $email = $user->user_email;
    }

    if(empty($email)){
      do_action('u7logger', 'class-orders-sending.php - empty email');
      return false;
    }

    $meta = $this->get_agent_meta_by_email($email);

    if(empty($meta)){

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

      $data = array(
        "name" => $name,
      );

      if( ! empty($email)){
        $data['email'] = $email;
      }

      $url = 'https://online.moysklad.ru/api/remap/1.1/entity/counterparty';

      $result = $this->send_data($url, $data);

      if(empty($result["meta"])){
        return false;
      }

      $meta = $result["meta"];

    }

    return array('meta' => $meta);

  }

  /**
  * Get meta by email agent
  */
  function get_agent_meta_by_email($email = ''){
    $url_search_agent = 'https://online.moysklad.ru/api/remap/1.1/entity/counterparty?filter=email=' . $email;
    $data_agents = wooms_get_data_by_url($url_search_agent);

    if(empty($data_agents['rows'][0]['meta'])){
      return false;
    } else {
      return $data_agents['rows'][0]['meta'];
    }
  }

  /**
  * Get meta for organization
  */
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

  function ui_for_manual_start(){

    ?>
    <h2>Отправка заказов в МойСклад</h2>
    <p>Для отправки ордеров в МойСклад - нажмите на кнопку</p>
    <a href="<?php echo add_query_arg('a', 'wooms_orders_send', admin_url('tools.php?page=moysklad')) ?>" class="button">Выполнить</a>
    <?php
  }



  function settings_init(){

    add_settings_section('wooms_section_orders', 'Заказы - передача в МойСклад', '', 'mss-settings' );

    register_setting('mss-settings', 'wooms_orders_sender_enable');
    add_settings_field(
      $id = 'wooms_orders_sender_enable',
      $title = 'Включить синхронизацию заказов в МойСклад',
      $callback = [$this, 'display_wooms_orders_sender_enable'],
      $page = 'mss-settings',
      $section = 'wooms_section_orders'
    );

    register_setting('mss-settings', 'wooms_orders_send_from');
    add_settings_field(
      $id = 'wooms_orders_send_from',
      $title = 'Дата, с которой берутся Заказы для отправки',
      $callback = [$this, 'display_wooms_orders_send_from'],
      $page = 'mss-settings',
      $section = 'wooms_section_orders'
    );
  }

  function display_wooms_orders_sender_enable()
  {
    $option = 'wooms_orders_sender_enable';
    printf('<input type="checkbox" name="%s" value="1" %s />', $option, checked( 1, get_option($option), false ));
  }
  function display_wooms_orders_send_from()
  {
    $option_key = 'wooms_orders_send_from';
    printf('<input type="text" name="%s" value="%s" />', $option_key, get_option($option_key) );
    echo '<p><small>Если дата не выбрана, то берутся заказы сегодняшнего и вчерашнего дня. Иначе берутся все новые заказы с указанной даты.</small></p>';

    ?>
    <script type="text/javascript">
        jQuery(document).ready(function(){
          jQuery('input[name=wooms_orders_send_from]').datepicker();
        });
    </script>
    <?php

  }

  /**
  * UI for manual start send data of order
  */
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

  /**
  * Add jQuery date picker for select start-date sending orders to MoySklad
  */
  function enqueue_date_picker()
  {
    $screen = get_current_screen();
    if(empty($screen->id) or 'settings_page_mss-settings' != $screen->id){
      return;
    }

    wp_enqueue_script( 'jquery-ui-datepicker' );

    $wp_scripts = wp_scripts();
    wp_enqueue_style(
      'jquery-ui',
      'http://ajax.googleapis.com/ajax/libs/jqueryui/' . $wp_scripts->registered['jquery-ui-core']->ver . '/themes/smoothness/jquery-ui.css',
      false,
      $wp_scripts->registered['jquery-ui-core']->ver,
      false
    );
  }

}

new WooMS_Orders_Sender;
