<?php

namespace WooMS\Products;

if ( ! defined( 'ABSPATH' ) ) {
  exit; // Exit if accessed directly
}

/**
 * Import variants from MoySklad
 */
class Variations {

  /**
   * The init
   */
  public static function init() {

    add_action( 'wooms_product_save', array( __CLASS__, 'update_product' ), 20, 3 );
    add_filter( 'wooms_save_variation', array(__CLASS__, 'set_variation_attributes'), 10, 3);
    add_action( 'wooms_product_variant_import_row', array( __CLASS__, 'load_data_variant' ), 15, 3 );

    // Cron
    add_action( 'init', array( __CLASS__, 'add_cron_hook' ) );
    // add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
    add_action( 'wooms_cron_variation_sync', array( __CLASS__, 'walker_starter' ) );

    //Other
    add_action( 'admin_init', array( __CLASS__, 'settings_init' ), 150 );
    add_action( 'woomss_tool_actions_btns', array( __CLASS__, 'ui_for_manual_start' ), 15 );
    add_action( 'woomss_tool_actions_wooms_import_variations_manual_start', array( __CLASS__, 'start_manually' ) );
    add_action( 'woomss_tool_actions_wooms_import_variations_manual_stop', array( __CLASS__, 'stop_manually' ) );
    add_action('wooms_variants_display_state', array(__CLASS__, 'display_state'));

  }

  /**
   * Set attributes and value for variation
   *
   * @param $variation_id
   * @param $characteristics
   */
  public static function set_variation_attributes( $variation, $variant_data, $product_id ) {

    $variation_id = $variation->get_id();
    $parent_id = $variation->get_parent_id();

    $characteristics = $variant_data['characteristics'];

    $attributes = array();

    foreach ( $characteristics as $key => $characteristic ) {
      $attribute_name = $characteristic['name'];
      $attribute_taxonomy_id = wc_attribute_taxonomy_id_by_name($attribute_name);
      $taxonomy_name = wc_attribute_taxonomy_name_by_id($attribute_taxonomy_id);
      $attribute_slug = sanitize_title( $attribute_name );

      if(empty($attribute_taxonomy_id)){
        $attributes[$attribute_slug] = $characteristic['value'];
      } else {
        //Очищаем индивидуальный атрибут с таким именем если есть
        if(isset($attributes[$attribute_slug])){
            unset($attributes[$attribute_slug]);
        }
         $attributes[$taxonomy_name] = sanitize_title($characteristic['value']);
      }
    }

    $variation->set_attributes( $attributes );

    return $variation;
  }

  /**
   * Update product from source data
   */
  public static function update_product( $product, $item, $data )
  {
    if ( empty( get_option( 'woomss_variations_sync_enabled' ) ) ) {
      return $product;
    }

    if( empty( $item['modificationsCount']) ){
      return $product;
    }

    $product_id = $product->get_id();

    if ( ! $product->is_type( 'variable' )) {
      $product = new \WC_Product_Variable($product);

      do_action('wooms_logger',
        'product_variable_set',
        sprintf('Продукт изменен как вариативный %s', $product_id),
        sprintf('Данные %s', PHP_EOL . print_r($product, true))
      );
    }

    return $product;
  }

  /**
   * Set attributes for variables
   */
  public static function set_product_attributes_for_variation( $product_id, $data ) {

    $product = wc_get_product($product_id);

    $ms_attributes = [];
    foreach ( $data['characteristics'] as $key => $characteristic ) {

      $attribute_slug = sanitize_title( $characteristic["name"] );

      $ms_attributes[ $attribute_slug ] = [
        'name'   => $characteristic["name"],
        'values' => [],
      ];
    }

    foreach ( $data['characteristics'] as $key => $characteristic ) {
      $attribute_slug = sanitize_title( $characteristic["name"] );
      $values = $product->get_attribute($attribute_slug);
      $values = explode(' | ', $values);
      $values[] = $characteristic['value'];

      $ms_attributes[ $attribute_slug ]['values'] = $values;
    }

    foreach ( $ms_attributes as $key => $value ) {
      $ms_attributes[ $key ]['values'] = array_unique( $value['values'] );
    }

    $attributes = $product->get_attributes('edit');

    if(empty($attributes)){
      $attributes = array();
    }

    foreach ( $ms_attributes as $key => $value ) {
      $attribute_taxonomy_id = wc_attribute_taxonomy_id_by_name($value['name']);


      $attribute_slug = sanitize_title( $value['name'] );

      if(empty($attribute_taxonomy_id)){
        $attribute_object = new \WC_Product_Attribute();
        $attribute_object->set_name( $value['name'] );
        $attribute_object->set_options( $value['values'] );
        $attribute_object->set_position( 0 );
        $attribute_object->set_visible( 0 );
        $attribute_object->set_variation( 1 );
        $attributes[$attribute_slug] = $attribute_object;

      } else {

        //Очищаем индивидуальный атрибут с таким именем если есть
        if(isset($attributes[$attribute_slug])){
            unset($attributes[$attribute_slug]);
        }
        $taxonomy_name = wc_attribute_taxonomy_name_by_id($attribute_taxonomy_id);

        $attribute_object = new \WC_Product_Attribute();
        $attribute_object->set_id( $attribute_taxonomy_id );
        $attribute_object->set_name( $taxonomy_name );
        $attribute_object->set_options( $value['values'] );
        $attribute_object->set_position( 0 );
        $attribute_object->set_visible( 0 );
        $attribute_object->set_variation( 1 );
        $attributes[$taxonomy_name] = $attribute_object;

      }
    }

    $product->set_attributes( $attributes );

    $product->save();

    do_action('wooms_logger',
      'product_save_add_attributes_for_variations',
      sprintf('Добавлены атрибуты для продукта %s', $product_id),
      sprintf('Данные %s', PHP_EOL . print_r($attributes, true))
    );
  }

  /**
   * Installation of variations for variable product
   *
   * @param $value
   * @param $key
   * @param $data
   */
  public static function load_data_variant( $variant, $key, $data ) {

    if ( ! empty($variant['archived']) ) {
      return;
    }

    $product_href = $variant['product']['meta']['href'];
    $product_id = self::get_product_id_by_uuid( $product_href );

    if(empty($product_id)){

      do_action('wooms_logger',
        'error_variation_get_product_id',
        sprintf('Ошибка получения product_id для url %s', $product_href),
        sprintf('Данные %s', PHP_EOL . print_r($variant, true))
      );

      return;
    }
    //
    // if ( empty( get_option( 'wooms_use_uuid' ) ) ) {
    // 	if ( empty( $response['article'] ) ) {
    // 		return;
    // 	}
    // }

    self::update_variant_for_product( $product_id, $variant );

    do_action( 'wooms_product_variant', $product_id, $variant, $data );
  }

  /**
   * Get product variant ID
   *
   * @param $uuid
   *
   * @return bool
   */
  public static function get_product_id_by_uuid( $uuid ) {

    if(strpos($uuid, 'http') !== false){
      $uuid = str_replace('https://online.moysklad.ru/api/remap/1.1/entity/product/', '', $uuid);
    }

    $posts = get_posts( 'post_type=product&meta_key=wooms_id&meta_value=' . $uuid );
    if ( empty( $posts[0]->ID ) ) {
      return false;
    }

    return $posts[0]->ID;
  }

  /**
   * Update and add variables from product
   *
   * @param $product_id
   * @param $value
   */
  public static function update_variant_for_product( $product_id, $variant_data ) {

    if ( empty( $variant_data ) ) {
      return;
    }

    //добавление атрибутов к основному продукту с пометкой для вариаций
    self::set_product_attributes_for_variation($product_id, $variant_data);

    if ( ! $variation_id = self::get_variation_by_wooms_id( $product_id, $variant_data['id'] ) ) {
      $variation_id = self::add_variation( $product_id, $variant_data );
    }

    $variation = wc_get_product( $variation_id );
    $variation->set_name( $variant_data['name'] );

    $variation->set_stock_status( 'instock' );

    if ( ! empty( $variant_data["salePrices"][0]['value'] ) ) {
      $price = $variant_data["salePrices"][0]['value'] / 100;
      $variation->set_price( $price );
      $variation->set_regular_price( $price );
    }

    $product_parent = wc_get_product($product_id);
    if( ! $product_parent->is_type('variable')){
      $product_parent = new \WC_Product_Variable($product_parent);
      $product_parent->save();

      //@TODO это не нормальная ситуация и надо решить проблему
      do_action('wooms_logger',
        'product_again_save_product_as_variable',
        sprintf('Снова сохранили продукт как вариативный %s', $product_id),
        sprintf('Для продукта %s', $product_parent)
      );
    }

    $variation = apply_filters('wooms_save_variation', $variation, $variant_data, $product_id);

    if ( $session_id = get_option( 'wooms_session_id' ) ) {
      $variation->update_meta_data('wooms_session_id', $session_id);
    }

    $variation->save();

    do_action('wooms_logger',
      'product_variaton_save',
      sprintf('Сохранена вариация %s, для продукта %s', $variation_id, $product_id),
      sprintf('Данные %s', PHP_EOL . print_r($variation, true))
    );

    do_action( 'wooms_variation_id', $variation_id, $variant_data );
  }

  /**
   * Get product parent ID
   */
  public static function get_variation_by_wooms_id( $parent_id, $id ) {
    $posts = get_posts( array(
      'post_type'=>'product_variation',
      'post_parent' => $parent_id,
      'meta_key' => 'wooms_id',
      'meta_value' => $id,
    ) );

    if ( empty( $posts ) ) {
      return false;
    }

    return $posts[0]->ID;
  }

  /**
   * Add variables from product
   */
  public static function add_variation( $product_id, $value ) {

    $variation = new \WC_Product_Variation();
    $variation->set_parent_id( absint( $product_id ) );
    $variation->set_status( 'publish' );
    $variation->set_stock_status( 'instock' );
    $r = $variation->save();

    $variation_id = $variation->get_id();
    if ( empty( $variation_id ) ) {
      return false;
    }

    update_post_meta( $variation_id, 'wooms_id', $value['id'] );

    do_action( 'wooms_add_variation', $variation_id, $product_id, $value );

    return $variation_id;
  }

  /**
   * Start import manually
   */
  public static function start_manually() {
    delete_transient( 'wooms_variant_start_timestamp' );
    delete_transient( 'wooms_error_background' );
    delete_transient( 'wooms_variant_offset' );
    delete_transient( 'wooms_variant_end_timestamp' );
    delete_transient( 'wooms_variant_walker_stop' );
    set_transient( 'wooms_variant_manual_sync', 1 );
    self::walker();
    wp_redirect( admin_url( 'admin.php?page=moysklad' ) );
  }

  /**
   * Walker for data variant product from MoySklad
   */
  public static function walker() {

    //Check stop tag and break the walker
    if ( self::check_stop_manual() ) {
      return false;
    }

    $count = apply_filters( 'wooms_variant_iteration_size', 20 );
    if ( ! $offset = get_transient( 'wooms_variant_offset' ) ) {
      $offset = 0;
      set_transient( 'wooms_variant_offset', $offset );
      update_option( 'wooms_variant_session_id', date( "YmdHis" ), 'no' );
      delete_transient( 'wooms_count_variant_stat' );
    }

    $ms_api_args = array(
      'offset' => $offset,
      'limit'  => $count,
    );

    $url_api = add_query_arg( $ms_api_args, 'https://online.moysklad.ru/api/remap/1.1/entity/variant' );

    try {

      delete_transient( 'wooms_variant_end_timestamp' );
      set_transient( 'wooms_variant_start_timestamp', time() );
      $data = wooms_request( $url_api );

      do_action('wooms_logger',
        'request_variations',
        sprintf('Отправлен запрос на вариации: %s', $url_api),
        sprintf('Данные: %s', PHP_EOL . print_r($data, true))
      );

      //Check for errors and send message to UI
      if ( isset( $data['errors'] ) ) {
        $error_code = $data['errors'][0]["code"];
        if ( $error_code == 1056 ) {
          $msg = sprintf( 'Ошибка проверки имени и пароля. Код %s, исправьте в <a href="%s">настройках</a>', $error_code, admin_url( 'options-general.php?page=mss-settings' ) );
          throw new \Exception( $msg );
        } else {
          throw new \Exception( $error_code . ': ' . $data['errors'][0]["error"] );
        }
      }
      //If no rows, that send 'end' and stop walker
      if ( empty( $data['rows'] ) ) {
        self::walker_finish();

        return false;
      }

      $i = 0;
      foreach ( $data['rows'] as $key => $variant ) {
        do_action( 'wooms_product_variant_import_row', $variant, $key, $data );
        $i ++;
      }

      if ( $count_saved = get_transient( 'wooms_count_variant_stat' ) ) {
        set_transient( 'wooms_count_variant_stat', $i + $count_saved );
      } else {
        set_transient( 'wooms_count_variant_stat', $i );
      }

      set_transient( 'wooms_variant_offset', $offset + $i );

      return;
    } catch ( Exception $e ) {
      delete_transient( 'wooms_variant_start_timestamp' );
      set_transient( 'wooms_error_background', $e->getMessage() );
    }
  }

  /**
   * Check for stopping imports from MoySklad
   */
  public static function check_stop_manual() {
    if ( get_transient( 'wooms_variant_walker_stop' ) ) {
      delete_transient( 'wooms_variant_start_timestamp' );
      delete_transient( 'wooms_variant_offset' );
      delete_transient( 'wooms_variant_walker_stop' );

      return true;
    } else {
      return false;
    }
  }

  /**
   * Stopping walker imports from MoySklad
   */
  public static function walker_finish() {
    delete_transient( 'wooms_variant_start_timestamp' );
    delete_transient( 'wooms_variant_offset' );
    delete_transient( 'wooms_variant_manual_sync' );
    //Отключаем обработчик или ставим на паузу
    if ( empty( get_option( 'woomss_walker_cron_enabled' ) ) ) {
      $timer = 0;
    } else {
      $timer = 60 * 60 * intval( get_option( 'woomss_walker_cron_timer', 24 ) );
    }

    set_transient( 'wooms_variant_end_timestamp', date( "Y-m-d H:i:s" ), $timer );

    do_action('wooms_logger',
      'variations_walker_finish',
      'Обработчик вариаций финишировал',
      sprintf('Данные: %s', PHP_EOL . print_r($timer, true))
    );

    return true;
  }

  /**
   * Stop import manually
   */
  public static function stop_manually() {
    set_transient( 'wooms_variant_walker_stop', 1, 60 * 60 );
    delete_transient( 'wooms_variant_start_timestamp' );
    delete_transient( 'wooms_variant_offset' );
    delete_transient( 'wooms_variant_end_timestamp' );
    delete_transient( 'wooms_variant_manual_sync' );
    wp_redirect( admin_url( 'admin.php?page=moysklad' ) );
  }

  /**
   * Add cron pramametrs
   * XXX удалить
   */
  public static function add_schedule( $schedules ) {

    $schedules['wooms_cron_worker_variations'] = array(
      'interval' => apply_filters('wooms_cron_interval', 60),
      'display'  => 'WooMS Cron Load Variations 60 sec',
    );

    return $schedules;
  }

  /**
   * add_cron_hook
   */
  public static function add_cron_hook() {
    if ( empty( get_option( 'woomss_variations_sync_enabled' ) ) ) {
      return;
    }

    if ( ! wp_next_scheduled( 'wooms_cron_variation_sync' ) ) {
      wp_schedule_event( time(), 'wooms_cron_walker_shedule', 'wooms_cron_variation_sync' );
    }

  }

  /**
   * Starting walker from cron
   */
  public static function walker_starter() {

    if ( self::can_cron_start() ) {
      self::walker();
    }
  }

  /**
   * Can cron start
   *
   * @return bool
   */
  public static function can_cron_start() {
    if ( ! empty( get_transient( 'wooms_variant_manual_sync' ) ) ) {
      return true;
    }

    if ( empty( get_option( 'woomss_walker_cron_enabled' ) ) ) {
      return false;
    }

    if ( empty( get_option( 'woomss_variations_sync_enabled' ) ) ) {
      return false;
    }

    /**
     * Если не завершен обмен по базовым товарам - то вариации не должны работать
     */
    if( empty(get_transient('wooms_end_timestamp')) ){
      return false;
    }

    if ( $end_stamp = get_transient( 'wooms_variant_end_timestamp' ) ) {

      $interval_hours = get_option( 'woomss_walker_cron_timer' );
      $interval_hours = (int) $interval_hours;
      if ( empty( $interval_hours ) ) {
        return false;
      }
      $now        = new \DateTime();
      $end_stamp  = new \DateTime( $end_stamp );
      $end_stamp  = $now->diff( $end_stamp );
      $diff_hours = $end_stamp->format( '%h' );
      if ( $diff_hours > $interval_hours ) {
        return true;
      } else {
        return false;
      }
    } else {
      return true;
    }
  }

  /**
   * Checking for variable product
   *
   * @return bool
   */
  public static function check_availability_of_variations() {

    $variants = get_posts( array(
      'post_type'   => 'product',
      'numberposts' => 10,
      'fields'      => 'ids',
      'tax_query'   => array(
        'relation' => 'AND',
        array(
          'taxonomy' => 'product_type',
          'terms'    => 'variable',
          'field'    => 'slug',
        ),
      ),
      'meta_query'  => array(
        array(
          'key'     => 'wooms_id',
          'compare' => 'EXISTS',
        ),
      ),
    ) );

    $timestamp = get_transient( 'wooms_end_timestamp' );
    if ( empty( $variants ) && (  empty($timestamp) || ! isset( $timestamp ) )) {
      return false;
    } else {
      return true;
    }
  }

  /**
   * Stopping the import of variational goods during verification
   */
  public static function stop_manually_to_check() {
    set_transient( 'wooms_variant_walker_stop', 1, 60 * 60 );
    delete_transient( 'wooms_variant_start_timestamp' );
    delete_transient( 'wooms_variant_offset' );
    delete_transient( 'wooms_variant_end_timestamp' );
    delete_transient( 'wooms_variant_manual_sync' );
  }

  /**
   * Manual start variations
   */
  public static function ui_for_manual_start() {
    if ( empty( get_option( 'woomss_variations_sync_enabled' ) ) ) {
      return;
    }

    echo '<h2>Вариации (Модификации)</h2>';

    do_action('wooms_variants_display_state');

    if ( empty( get_transient( 'wooms_variant_start_timestamp' ) ) ) {
      echo "<p>Нажмите на кнопку ниже, чтобы запустить синхронизацию данных о вариативных товарах вручную</p>";
      echo "<p><strong>Внимание!</strong> Синхронизацию вариативных товаров необходимо поводить <strong>после</strong> общей синхронизации товаров</p>";
      if (empty( get_transient( 'wooms_start_timestamp' ) )){
        printf( '<a href="%s" class="button button-primary">Выполнить</a>', add_query_arg( 'a', 'wooms_import_variations_manual_start', admin_url( 'admin.php?page=moysklad' ) ) );
      } else {
        printf( '<span href="%s" class="button button-secondary" style="display:inline-block">Выполнить</span>', add_query_arg( 'a', 'wooms_import_variations_manual_start', admin_url( 'admin.php?page=moysklad' ) ) );
      }

    } else {
      printf( '<a href="%s" class="button button-secondary">Остановить</a>', add_query_arg( 'a', 'wooms_import_variations_manual_stop', admin_url( 'admin.php?page=moysklad' ) ) );
    }
  }

  /**
   * Settings import variations
   */
  public static function settings_init() {
    register_setting( 'mss-settings', 'woomss_variations_sync_enabled' );
    add_settings_field(
      $id = 'woomss_variations_sync_enabled',
      $title = 'Включить синхронизацию вариаций',
      $callback = array(__CLASS__, 'display_variations_sync_enabled'),
      $page = 'mss-settings',
      $section = 'woomss_section_other'
    );
  }

  /**
   * Option import variations
   */
  public static function display_variations_sync_enabled() {
    $option = 'woomss_variations_sync_enabled';
    printf( '<input type="checkbox" name="%s" value="1" %s />', $option, checked( 1, get_option( $option ), false ) );
    ?>
    <p><strong>Тестовый режим. Не включайте эту функцию на реальном сайте, пока не проверите ее на тестовой копии
        сайта.</strong></p>
    <?php
  }

  /**
   * Получаем данные таксономии по id глобального артибута
   */
  public static function get_attribute_taxonomy_by_id( $id = 0 ) {

    if(empty($id)){
        return false;
    }

    $taxonomy = null;
    $attribute_taxonomies = wc_get_attribute_taxonomies();

    foreach ( $attribute_taxonomies as $key => $tax ) {
        if ( $id == $tax->attribute_id ) {
            $taxonomy = $tax;
            $taxonomy->slug = 'pa_' . $tax->attribute_name;

            break;
        }
    }

    return $taxonomy;
  }

  /**
   * display_state
   */
  public static function display_state(){
    $time_stamp = get_transient( 'wooms_variant_start_timestamp' );
    $diff_sec    = time() - $time_stamp;
    $time_string = date( 'Y-m-d H:i:s', $time_stamp );

    $variation_count = get_transient( 'wooms_count_variant_stat' );
    if(empty($variation_count)){
      $variation_count = 'пока выполняется';
    }

    $state = '<strong>Выполняется</strong>';

    $finish_timestamp = get_transient( 'wooms_variant_end_timestamp' );
    if(empty($finish_timestamp)){
      $finish_timestamp = '';
    } else{
      $state = 'Выполнено';
    }

    if( empty(get_transient('wooms_end_timestamp'))){
      $state = 'Работа заблокирована до окончания обмена по основным товарам';
    }

    $cron_on = get_option('woomss_walker_cron_enabled');

    ?>
    <div class="wrap">
      <div id="message" class="notice notice-warning">
        <p>Статус: <?= $state ?></p>
        <?php if($finish_timestamp): ?>
          <p>Последняя успешная синхронизация (отметка времени): <?= $finish_timestamp ?></p>
        <?php endif; ?>
        <p>Количество обработанных записей: <?= $variation_count ?></p>
        <?php if( ! $cron_on): ?>
          <p>Обмен по расписанию отключен</p>
        <?php endif; ?>
        <?php if( ! empty($time_stamp) ): ?>
          <p>Отметка времени о последней итерации: <?= $time_string ?></p>
          <p>Секунд прошло: <?= $diff_sec ?>.<br/> Следующая серия данных должна отправиться примерно через
          минуту. Можно обновить страницу для проверки результатов работы.</p>
        <?php endif; ?>
      </div>
    </div>
    <?php
  }
}

Variations::init();
