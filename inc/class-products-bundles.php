<?php
namespace WooMS\Products;

/**
 * Products Bundle Managment
 */
class Bundle
{
  /**
   * The init
   */
  public static function init()
  {
    add_action( 'wooms_product_save', array( __CLASS__, 'update_product' ), 20, 3 );

    add_action('admin_init', array(__CLASS__, 'settings_ui'), 150);

    add_action('woomss_tool_actions_btns', array(__CLASS__, 'ui_for_manual_start'), 15);
    add_action('woomss_tool_actions_wooms_import_product_bundles', array(__CLASS__, 'ui_action'));

    add_action('init', array(__CLASS__,'cron_init'));

    add_action('wooms_bundles_cron_starter', array(__CLASS__, 'walker'));

  }

  /**
   * Update product
   */
  public static function update_product( $product, $value, $data )
  {
    if( ! get_option('wooms_products_bundle_enable') ){
      return $product;
    }

    //If no components - skip
    if( empty($value["components"])){
      return $product;
    }

    $product_id = $product->get_id();

    if(empty($value["components"]["meta"]["href"])){
      return $product;
    } else {
      $url_api = $value["components"]["meta"]["href"];
    }

    $data_components = wooms_request($url_api);


    if(empty($data_components["rows"])){
      return $product;
    }

    if( ! $product->is_type( 'grouped' )){
      $product = new \WC_Product_Grouped($product);
    }

    $subproducts_ids = array();
    foreach ($data_components["rows"] as $row_component) {
      $product_uuid = str_replace('https://online.moysklad.ru/api/remap/1.1/entity/product/', '', $row_component["assortment"]["meta"]["href"]);
      $subproduct_id = self::get_product_id_by_uuid($product_uuid);

      if(empty($subproduct_id)){
        continue;
      }

      $subproducts_ids[] = $subproduct_id;
    }

    $product->set_children($subproducts_ids);

    do_action('wooms_logger', __CLASS__,
      sprintf('Продукт выбран как групповой %s', $product_id)
    );

    return $product;
  }


    /**
     * get_product_id_by_uuid
     */
    public static function get_product_id_by_uuid($uuid = '')
    {
        if (empty($uuid)) {
            return false;
        }

        $posts = get_posts('post_type=product&meta_key=wooms_id&meta_value=' . $uuid);
        if (empty($posts[0]->ID)) {
            return false;
        } else {
            return $posts[0]->ID;
        }
    }

  /**
   * walker
   */
  public static function walker()
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

      $data = wooms_request( $url_api );

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
        do_action('wooms_logger_error', __CLASS__,
          $e->getMessage()
        );
    }

  }


  /**
  * Cron task restart
  */
  public static function cron_init()
  {
    if(empty(get_option('wooms_products_bundle_enable'))){
      return;
    }

    if ( ! wp_next_scheduled( 'wooms_cron_walker' ) ) {
      wp_schedule_event( time(), 'wooms_bundles_cron_starter', 'wooms_cron_walker' );
    }
  }

  /**
   * ui_for_manual_start
   */
  public static function ui_for_manual_start()
  {
    if( empty(get_option('wooms_products_bundle_enable')) ){
      return;
    }

    ?>
    <h2>Комплекты</h2>
    <p>Ручной запуск импорта комплектов из МойСклад в групповые продукты WooCommerce</p>
    <a href="<?php echo add_query_arg('a', 'wooms_import_product_bundles', admin_url('admin.php?page=moysklad')) ?>" class="button button-primary">Выполнить</a>
    <?php
  }

  /**
   * ui_action
   */
  public static function ui_action()
  {
    echo '<br/><hr>';
    echo "<p>Старт обработчика</p>";

    $first_rest = self::walker();

    printf('<p>Обработано строк за первый обход: %s</p>', $first_rest);

  }

  /**
   * settings_ui
   */
  public static function settings_ui()
  {
    register_setting('mss-settings', 'wooms_products_bundle_enable');
    add_settings_field(
      $id = 'wooms_products_bundle_enable',
      $title = 'Включить работу с групповыми продуктами (комплекты МойСклад)',
      $callback = array(__CLASS__, 'wooms_products_bundle_enable_display'),
      $page = 'mss-settings',
      $section = 'woomss_section_other'
    );
  }

  /**
   * wooms_products_bundle_enable_display
   */
  public static function wooms_products_bundle_enable_display()
  {
    $option = 'wooms_products_bundle_enable';
    printf('<input type="checkbox" name="%s" value="1" %s />', $option, checked( 1, get_option($option), false ));
    echo "<p><strong>Тестовый режим. Не включайте эту функцию на реальном сайте, пока не проверите ее на тестовой копии сайта.</strong></p>";
  }
}

Bundle::init();
