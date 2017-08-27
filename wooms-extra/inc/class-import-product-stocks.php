<?php

/**
 * Import products from MoySklad
 */
class WooMS_Warehouses {

  function __construct(){
    add_action( 'admin_init', array($this, 'settings_init'), 100 );

    //Use hook do_action('wooms_product_update', $product_id, $value, $data);
    add_action('wooms_product_update', [$this, 'load_data'], 10, 3);

  }


  /**
   * Load data for product
   */
  public function load_data($product_id, $value, $data) {
    // получать остаток по складу и загружать в товары

    if(empty(get_option('woomss_warehouses_sync_stock_enabled'))){
      return false;
    }

    // $url = 'https://online.moysklad.ru/api/remap/1.1/report/stock/all';
    // $url = sprintf('https://online.moysklad.ru/api/remap/1.1/report/stock/all?product.id=%s', 'b6237097-477a-11e6-7a69-9711002440b7');
    $url = sprintf('https://online.moysklad.ru/api/remap/1.1/report/stock/all?product.id=%s', $value['id']);
    // $url = sprintf('https://online.moysklad.ru/api/remap/1.1/report/stock/all?product.id=%s&store.id=%s', $id_product, $id_store);

    $data = wooms_get_data_by_url($url);

    $product = wc_get_product($product_id);

    if(empty($data['rows'][0]['stock'])){
      $product->set_stock_quantity(0);
      $product->set_stock_status('outofstock');
      $product->save();

      return false;

    } else {
      $stock = (int)$data['rows'][0]['stock'];
    }

    if($stock <= 0){
      $product->set_stock_quantity(0);
      $product->set_stock_status('outofstock');
      $product->save();

      return false;
    }


    $product->set_stock_quantity($stock);
    $product->set_stock_status('instock');
    $product->save();

    return true;

    // update_post_meta($product_id, 'wooms_stock', print_r($data['rows'], true));
    // var_dump($data['rows'][0]['stock']); exit;
  }

  /**
   * Settings UI
   */
  public function settings_init() {

    add_settings_section(
    	'woomss_section_warehouses',
    	'Склад и остатки',
    	null,
    	'mss-settings'
    );

    register_setting('mss-settings', 'woomss_warehouses_sync_stock_enabled');
    add_settings_field(
      $id = 'woomss_warehouses_sync_stock_enabled',
      $title = 'Включить синхронизацию остатков',
      $callback = [$this, 'woomss_warehouses_sync_stock_enabled_display'],
      $page = 'mss-settings',
      $section = 'woomss_section_warehouses'
    );

    register_setting('mss-settings', 'woomss_warehouses_sync_enabled');
    add_settings_field(
      $id = 'woomss_warehouses_sync_enabled',
      $title = 'Включить синхронизацию по складу',
      $callback = [$this, 'woomss_warehouses_sync_enabled_display'],
      $page = 'mss-settings',
      $section = 'woomss_section_warehouses'
    );

    register_setting('mss-settings', 'woomss_warehouse_id');
    add_settings_field(
      $id = 'woomss_warehouse_id',
      $title = 'Выбрать склад для сайта',
      $callback = [$this, 'woomss_warehouse_id_display'],
      $page = 'mss-settings',
      $section = 'woomss_section_warehouses'
    );

  }

  //Display field
  function woomss_warehouses_sync_stock_enabled_display(){
    $option = 'woomss_warehouses_sync_stock_enabled';
    printf('<input type="checkbox" name="%s" value="1" %s />', $option, checked( 1, get_option($option), false ));
  }

  //Display field
  function woomss_warehouses_sync_enabled_display(){
    $option = 'woomss_warehouses_sync_enabled';
    printf('<input type="checkbox" name="%s" value="1" %s />', $option, checked( 1, get_option($option), false ));
  }

  //Display field: select warehouse
  function woomss_warehouse_id_display(){
    $option = 'woomss_warehouse_id';

    if( empty(get_option('woomss_warehouses_sync_enabled')) ){
      echo '<p>Для выбора включите синхронизацию по складу</p>';
      return;
    }

    $url = 'https://online.moysklad.ru/api/remap/1.1/entity/store';

    $data = wooms_get_data_by_url($url);


    if(empty($data['rows'])){
      return;
    }

    $selected_wh = get_option('woomss_warehouse_id');

    ?>
    <select class="wooms_select_warehouse" name="woomss_warehouse_id">
      <option value="">Выберите склад</option>
      <?php foreach ($data['rows'] as $value) {
          printf('<option value="%s" %s>%s</option>', $value['id'], selected($value['id'], $selected_wh, false), $value['name']);
        } ?>
    </select>
    <?php

  }


}

new WooMS_Warehouses;
