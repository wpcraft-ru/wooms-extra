<?php

namespace WooMS;

if (!defined('ABSPATH')) {
  exit; // Exit if accessed directly
}

/**
 * Single Product Import
 */
class ProductSingleSync
{

  public static $state_key = 'wooms_product_single_sync_state';

  /**
   * The Init
   */
  public static function init()
  {
    add_action('wooms_display_product_metabox', array(__CLASS__, 'display_checkbox'));
    add_action('save_post', array(__CLASS__, 'save'));

    add_action('wooms_product_single_update_schedule', array(__CLASS__, 'update_variations'));

    add_action('init', [__CLASS__, 'add_schedule_hook']);
  }

  /**
   * Cron task restart
   */
  public static function add_schedule_hook()
  {

    if(!self::need_update_variations_product_id()){
      return;
    }

    if (!as_next_scheduled_action('wooms_product_single_update_schedule', [], 'ProductWalker')) {
      // Adding schedule hook
      as_schedule_single_action(
        time() + 60,
        'wooms_product_single_update_schedule',
        [],
        'ProductWalker'
      );
    }

  }


  /**
   * get_state
   */
  public static function get_state($key = ''){
    $state = get_transient(self::$state_key);
    if(empty($key)){
      return $state;
    }

    if(isset($state[$key])){
      return $state[$key];
    }

    return null;
  }


  /**
   * set_state
   */
  public static function set_state($key = '', $value = ''){

    $state = get_transient(self::$state_key);

    if( is_array($state) ){
      $state[$key] = $value;
    } else {
      $state = [
        $key => $value
      ];
    }

    set_transient(self::$state_key, $state);

    return $state;
  }


  /**
   * update_variations
   */
  public static function update_variations($product_id = 0)
  {
    if(empty($product_id)){
      $product_id = self::get_state('product_id');
    }

    if (empty($product_id)) {
      $product_id = self::need_update_variations_product_id();
    }

    if (empty($product_id)) {
      return false;
    }

    $product  = wc_get_product($product_id);
    $wooms_id = $product->get_meta('wooms_id', true);

    $url_args = array(
      'limit'  => 20,
      'offset' => 0,
    );

    if ($offset = self::get_state('offset')) {
      $url_args['offset'] = $offset;
    } 

    $url = 'https://online.moysklad.ru/api/remap/1.2/entity/variant/?filter=productid=' . $wooms_id;
    $url = add_query_arg($url_args, $url);

    do_action(
      'wooms_logger',
      __CLASS__,
      sprintf('API запрос на вариации: %s (продукт ID %s)', $url, $product_id)
    );

    $data_api = wooms_request($url);

    if (empty($data_api['rows'])) {
      //finish
      self::set_state('product_id', 0);
      self::set_state('offset', 0);
      $product->delete_meta_data('wooms_need_update_variations');
      $product->save();

      return true;
    }

    $i = 0;
    foreach ($data_api['rows'] as $item) {
      $i++;

      do_action('wooms_products_variations_item', $item);
    }

    self::set_state('offset', self::get_state('offset') + $i);

    return true;
  }

  /**
   * Find the product that need to be updated
   *
   * @return void
   */
  public static function need_update_variations_product_id()
  {

    $args = [
      'post_type'      => 'product',
      'post_status'      => 'any',
      'posts_per_page' => 1,
      'meta_query'     => [
        [
          'key'     => 'wooms_need_update_variations',
          'compare' => 'EXISTS',
        ],
      ],
    ];

    $posts = get_posts($args);

    if (empty($posts)) {
      return false;
    }

    if (isset($posts[0]->ID)) {
      $product_id = $posts[0]->ID;
      self::set_state('product_id', $product_id);

      
      return $product_id;
    }

      return false;
  }


  /**
   * save
   */
  public static function save($post_id)
  {
    if (!empty($_REQUEST['wooms_product_sinle_sync'])) {
      self::sync($post_id);
    }
  }

  /**
   * sync
   */
  public static function sync($post_id = '')
  {
    if (empty($post_id)) {
      return false;
    }

    $product = wc_get_product($post_id);
    $uuid = $product->get_meta('wooms_id', true);
    if (empty($uuid)) {
      return false;
    }

    $url = 'https://online.moysklad.ru/api/remap/1.2/entity/product/' . $uuid;

    $data = wooms_request($url);


    do_action('wooms_product_data_item', $data);

    if (!empty($data['modificationsCount'])) {
      $product->update_meta_data('wooms_need_update_variations', 1);
      $product->save();
    }
  }


  /**
   * display_checkbox
   */
  public static function display_checkbox($product_id = '')
  {

    $product = wc_get_product($product_id);
    $need_update_variations = $product->get_meta('wooms_need_update_variations', true);
    echo '<hr/>';
    printf('<p>%s</p>', 'Функция для тестирования');
    if (empty($need_update_variations)) {
      printf(
        '<input id="wooms-product-single-sync" type="checkbox" name="wooms_product_sinle_sync"> <label for="wooms-product-single-sync">%s</label>',
        'Синхронизировать отдельно'
      );
    } else {
      printf('<p>%s</p>', 'Вариации ждут очереди на обновление');
    }
  }
}

ProductSingleSync::init();
