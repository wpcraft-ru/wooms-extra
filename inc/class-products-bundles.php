<?php
/**
 * Products Bundle Managment
 */
class WooMS_Products_Bundle
{

  function __construct()
  {
    add_action('admin_init', array($this, 'settings_ui'), 150);

    add_action('woomss_tool_actions_btns', [$this, 'ui_for_manual_start'], 15);
    add_action('woomss_tool_actions_wooms_import_product_bundles', [$this, 'ui_action']);

    //Use do_action('wooms_product_update', $product_id, $value, $data);
    add_action('wooms_product_update', array($this, 'update_product_data'), 20, 3);

    add_action('init', array($this,'cron_init'));

    add_action('wooms_bundles_cron_starter', array($this, 'walker'));

  }

  function walker()
  {
    if(empty(get_option('wooms_products_bundle_enable'))){
      return 0;
    }

    try {
      set_transient('wooms_bundles_start_timestamp', time());

      $args_ms_api = [
        'offset' => 0,
        'limit' => 10
      ];

      $url_api = add_query_arg($args_ms_api, 'https://online.moysklad.ru/api/remap/1.1/entity/bundle');

      $data = wooms_get_data_by_url( $url_api );

      //Check for errors and send message to UI
      if (isset($data['errors'])) {
          $error_code = $data['errors'][0]["code"];

          if ($error_code == 1056) {
              $msg = sprintf('Ошибка проверки имени и пароля. Код %s, исправьте в <a href="%s">настройках</a>', $error_code, admin_url('options-general.php?page=mss-settings'));
              throw new Exception($msg);
          } else {
              throw new Exception($error_code . ': '. $data['errors'][0]["error"]);
          }
      }

      if (empty($data['rows'])) {
        delete_transient('wooms_bundles_start_timestamp');
      }

      $i = 0;
      foreach ($data['rows'] as $key => $value) {
          do_action('wooms_product_import_row', $value, $key, $data);
          $i++;
      }

      delete_transient('wooms_bundles_start_timestamp');

      return $i;


    } catch (Exception $e) {
        delete_transient('wooms_bundles_start_timestamp');
        set_transient('wooms_error_background', $e->getMessage());
    }

}


  /**
  * Cron task restart
  */
  function cron_init()
  {
    if(empty('wooms_products_bundle_enable')){
      return;
    }

    if ( ! wp_next_scheduled( 'wooms_cron_walker' ) ) {
      wp_schedule_event( time(), 'wooms_bundles_cron_starter', 'wooms_cron_walker' );
    }
  }

  function update_product_data($product_id, $value, $data){

    //If no components - skip
    if( empty($value["components"])){
      return;
    }

    $product = wc_get_product($product_id);

    if( ! $product->is_type( 'grouped' )){
      wp_set_post_terms( $product_id, 'grouped', 'product_type' );
      $product = wc_get_product($product_id);
    }

    if(empty($value["components"]["meta"]["href"])){
      return;
    } else {
      $url_api = $value["components"]["meta"]["href"];
    }

    $data_components = wooms_get_data_by_url($url_api);

    if(empty($data_components["rows"])){
      return;
    }

    foreach ($data_components["rows"] as $row_component) {
      $product_uuid = str_replace('https://online.moysklad.ru/api/remap/1.1/entity/product/', '', $row_component["assortment"]["meta"]["href"]);
      $subproduct_id = wooms_get_product_id_by_uuid($product_uuid);

      if(empty($subproduct_id)){
        continue;
      }

      $product->set_children($subproduct_id);
      $product->save();

    }

  }

  function data_set($key = '', $value = '', $timer = '')
  {
    if(empty($key) or empty($value)){
      return false;
    }

    $key = 'wooms_bundles_' . $key;

    set_transient($key, $value, $timer);
  }

  function data_get($key = '')
  {
    if(empty($key)){
      return false;
    }

    $key = 'wooms_bundles_' . $key;

    return get_transient($key);
  }

  function data_delete($key = '')
  {
    if(empty($key)){
      return false;
    }

    $key = 'wooms_bundles_' . $key;

    return delete_transient($key);
  }


  function ui_for_manual_start()
  {
    if( empty(get_option('wooms_products_bundle_enable')) ){
      return;
    }

    ?>
    <h2>Импорт комплектов из МойСклад</h2>
    <p>Ручной запуск импорта комплектов из МойСклад в групповые продукты WooCommerce</p>
    <a href="<?php echo add_query_arg('a', 'wooms_import_product_bundles', admin_url('admin.php?page=moysklad')) ?>" class="button">Выполнить</a>
    <?php
  }

  function ui_action()
  {
    echo '<br/><hr>';
    echo "<p>Старт обработчика</p>";

    $first_rest = $this->walker();

    printf('<p>Обработано строк за первый обход: %s</p>', $first_rest);

  }


  function settings_ui()
  {
    register_setting('mss-settings', 'wooms_products_bundle_enable');
    add_settings_field(
      $id = 'wooms_products_bundle_enable',
      $title = 'Включить работу с групповыми продуктами (комплекты МойСклад)',
      $callback = [$this, 'wooms_products_bundle_enable_display'],
      $page = 'mss-settings',
      $section = 'woomss_section_other'
    );
  }

  function wooms_products_bundle_enable_display()
  {
    $option = 'wooms_products_bundle_enable';
    printf('<input type="checkbox" name="%s" value="1" %s />', $option, checked( 1, get_option($option), false ));
    echo "<p><strong>Тестовый режим. Не включайте эту функцию на реальном сайте, пока не проверите ее на тестовой копии сайта.</strong></p>";
  }

}
new WooMS_Products_Bundle;
