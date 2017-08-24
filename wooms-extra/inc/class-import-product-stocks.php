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


    /**
    * Test
    */

    // $url = 'https://online.moysklad.ru/api/remap/1.1/entity/uom/19f1edc0-fc42-4001-94cb-c9ec9c62ec10';
    //
    // $data = wooms_get_data_by_url($url);
    //
    // var_dump($data); exit;
  }

  /**
   * Settings UI
   */
  public function settings_init() {

    add_settings_section(
    	'woomss_section_warehouses',
    	'Склад',
    	null,
    	'mss-settings'
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
