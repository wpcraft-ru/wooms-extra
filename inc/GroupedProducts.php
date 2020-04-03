<?php
namespace WooMS;

/**
 * Products Bundle Managment
 */
class GroupedProducts
{

  public static $transient_key = 'wooms_grouped_action_args';

  /**
   * The init
   */
  public static function init()
  {
  

    add_action( 'wooms_product_save', array( __CLASS__, 'update_product' ), 20, 2 );
    // add_action('wooms_main_walker_finish', [__CLASS__, 'restart_after_main_walker']);
    // add_action('wooms_main_walker_started', [__CLASS__, 'wait_main_walker']);

    add_action('admin_init', array(__CLASS__, 'settings_init'), 150);

    // add_action('woomss_tool_actions_btns', array(__CLASS__, 'render_ui'), 15);
    // add_action('woomss_tool_actions_wooms_import_product_bundles', array(__CLASS__, 'start_manual'));

    // add_action('init', array(__CLASS__, 'reload_action_sheduler'));

    // add_action('wooms_groups_walker', array(__CLASS__, 'walker'), 10, 2);


  }

  
  /**
   * Update product
   */
  public static function update_product( $product, $value )
  {
    if( ! get_option('wooms_products_bundle_enable') ){
      return $product;
    }

    //If no components - skip
    if( empty($value["components"])){
      return $product;
    }

    if(empty($value["components"]["meta"]["href"])){
      return $product;
    } 
    
    $url_api = $value["components"]["meta"]["href"];


    $data_components = wooms_request($url_api);

    if(empty($data_components["rows"])){
      return $product;
    }

    if( ! $product->is_type( 'grouped' )){

      $product = new \WC_Product_Grouped($product);

      do_action('wooms_logger', __CLASS__,
        sprintf('Продукт выбран как групповой %s (%s)', $product->get_id(), $product->get_name())
      );
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
      sprintf('Сгруппированный продукт %s (%s). Выбор компонентов...', $product->get_id(), $product->get_name()),
      $subproducts_ids
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
  public static function walker($args = [])
  {
    if( ! self::is_enable() ){
      return;
    }

    $args = self::get_state();

    //reset state
    if(empty($args['timestamp'])){
      self::set_state('timestamp', time());
      self::set_state('finish_timestamp', 0);
      self::set_state('count', 0);

      $query_args = [
        'offset' => 0,
        'limit' => 1,
      ];
      self::set_state('query_args', $query_args);

      $args = self::get_state();
    }
            
    $query_args = $args['query_args'];

    $url = 'https://online.moysklad.ru/api/remap/1.1/entity/bundle';

    $url = add_query_arg($query_args, $url);

    $url = apply_filters('wooms_product_bundle_url', $url);

    do_action(
      'wooms_logger',
      __CLASS__,
      sprintf('Групповые продукты. Старт очереди обновления - %s', date("Y-m-d H:i:s")),
      [$url, $args]
    );

    try {

      $data = wooms_request( $url );

      if(isset($data['errors'][0]['error'])){

      }
      var_dump($data); exit;

      if (empty($data['rows'])) {
        self::walker_finish();
        return;
      }

      foreach ($data['rows'] as $key => $value) {
          do_action('wooms_product_import_row', $value, $key, $data);
      }

      $query_args['offset'] = $query_args['offset'] + count($data['rows']);

      self::set_state('count', $args['count'] + count($data['rows']));
      self::set_state('query_args', $query_args);

      self::reload_action_sheduler(true);

    } catch (\Exception $e) {
        do_action('wooms_logger_error', __CLASS__,
          $e->getMessage()
        );
    }
  }


  /**
   * Walker reload
   */
  public static function reload_action_sheduler($force = false)
  {
    if( ! self::is_enable() ){
      return false;
    }

    if (as_next_scheduled_action('wooms_groups_walker') && ! $force) {
      return false;
    }

    if( ! self::walker_can_start()){
      return false;
    }

    if(as_schedule_single_action( time() + 11, 'wooms_groups_walker', self::get_state(), 'WooMS' )){
      return true;
    }

    return false;
  }


  /**
   * Check is enable
   */
  public static function is_enable(){
    if(empty(get_option('wooms_products_bundle_enable'))){
      return false;
    }

    return true;
  }


  /**
   * check walker can start
   */
  public static function walker_can_start()
  {
    $can = false;

    if( empty(self::get_state('finish_timestamp'))){
      $can = true;
    }

    //XXX надо что то тут сделать
    // if( ! empty(self::get_state('wait_main_walker')) ){
    //   $can = false;
    // }

    return $can;
  }


  /**
   * Walker finish
   */
  public static function walker_finish()
  {
    self::set_state('finish_timestamp', time());

    do_action(
      'wooms_logger',
      __CLASS__,
      sprintf('Групповые продукты. Обновление завершено - %s', date("Y-m-d H:i:s")),
      self::get_state()
    );

    // delete_transient(self::$transient_key);
  }


  /**
   * restart_after_main_walker
   */
  public static function restart_after_main_walker()
  {
    self::set_state('timestamp', 0);
    self::set_state('wait_main_walker', 0);
    self::set_state('finish_timestamp', 0);
    self::reload_action_sheduler();
  }


  /**
   * wait_main_walker
   */
  public static function wait_main_walker()
  {
    self::set_state('wait_main_walker', 1);
  }


  /**
   * render_ui
   */
  public static function render_ui()
  {
    if( empty(get_option('wooms_products_bundle_enable')) ){
      return;
    }

    $state = self::get_state();
    $strings = [];


    $strings[] = sprintf('Очередь задач: <a href="%s">открыть</a>', admin_url('admin.php?page=wc-status&tab=action-scheduler&s=wooms_groups_walker&orderby=schedule&order=desc'));
    
    if(defined('WC_LOG_HANDLER') && 'WC_Log_Handler_DB' == WC_LOG_HANDLER){
      $strings[] = sprintf('Журнал обработки: <a href="%s">открыть</a>', admin_url('admin.php?page=wc-status&tab=logs&source=wooms-WooMS-GroupedProducts'));
    } else {
      $strings[] = sprintf('Журнал обработки: <a href="%s">открыть</a>', admin_url('admin.php?page=wc-status&tab=logs'));
    }
    
    $strings[] = sprintf('Количество обработанных записей: %s', empty($state['count']) ? 0 : $state['count']);


    if( empty($state['finish_timestamp']) && ! empty($state['timestamp']) ){
      $strings[] = sprintf('Обработка выполняется. Время старта: %s', date("Y-m-d H:i:s", $state['timestamp']));
    }

    if( ! empty($state['finish_timestamp'])){
      $strings[] = sprintf('Последнее успешное завершение: %s', date("Y-m-d H:i:s", $state['finish_timestamp']));
    }

    ?>
    <h2>Сгруппированные продукты (Комплекты)</h2>

    <div class="wrap">
      <div id="message" class="notice notice-warning">
        <?php foreach($strings as $string){
          printf('<p>%s</p>', $string);
        } ?>
      </div>
    </div>

    <p>Ручной запуск импорта комплектов из МойСклад в групповые продукты WooCommerce</p>
    <a href="<?php echo add_query_arg('a', 'wooms_import_product_bundles', admin_url('admin.php?page=moysklad')) ?>" class="button button-primary">Выполнить</a>
    <?php
  }


  /**
   * start_manual
   */
  public static function start_manual()
  {
    self::set_state('timestamp', 0);
    self::set_state('finish_timestamp', 0);
    self::set_state('count', 0);
    self::set_state('query_args', 0);
    self::reload_action_sheduler();

    wp_redirect(admin_url('admin.php?page=moysklad')); exit;
  }


  /**
   * settings_ui
   */
  public static function settings_init()
  {
    $option_id = 'wooms_products_bundle_enable';
    register_setting('mss-settings', $option_id);
    add_settings_field(
      $id = $option_id,
      $title = 'Включить работу с групповыми продуктами (комплекты МойСклад)',
      $callback = function($args){
        printf('<input type="checkbox" name="%s" value="1" %s />', $args['name'], checked( 1, $args['value'], false ));
        printf('<p><strong>%s</strong></p>', 'Тестовый режим. Не включайте эту функцию на реальном сайте, пока не проверите ее на тестовой копии сайта.');
      },
      $page = 'mss-settings',
      $section = 'woomss_section_other',
      $args = [
        'name' => $option_id,
        'value' => @get_option($option_id),
      ]
    );

  }

  /**
   * get state data
   */
  public static function get_state($key = '')
  {
    if( ! $state = get_transient(self::$transient_key)){
      $state = [];
      set_transient(self::$transient_key, $state);
    }

    if(empty($key)){
      return $state;
    }

    if(empty($state[$key])){
      return null;
    }

    return $state[$key];
    
  }

  /**
   * set state data
   */
  public static function set_state($key, $value){

    if( ! $state = get_transient(self::$transient_key)){
      $state = [];
    }

    if(is_array($state)){
      $state[$key] = $value;
    } else {
      $state = [];
      $state[$key] = $value;
    }

    set_transient(self::$transient_key, $state);
  }


}

GroupedProducts::init();
