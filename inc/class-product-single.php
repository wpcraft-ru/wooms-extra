<?php

namespace WooMS\Products;

if ( ! defined( 'ABSPATH' ) ) {
  exit; // Exit if accessed directly
}

/**
 * Single Product Import
 */
class Single {

  /**
   * The Init
   */
  public static function init()
  {
    add_action('wooms_display_product_metabox', array(__CLASS__, 'display_checkbox') );
    add_action('save_post', array(__CLASS__, 'save') );

    add_action('wooms_product_single_update', array(__CLASS__, 'update_variations'));
    add_action( 'init', function(){
      if ( ! wp_next_scheduled( 'wooms_product_single_update' ) ) {
        wp_schedule_event( time(), 'wooms_cron_walker_shedule', 'wooms_product_single_update' );
      }
    });

    add_shortcode('test1', function(){

      $ms_api_args = array(
        'offset' => 0,
        'limit'  => 100,
        'scope'  => 'variant',
      );

      // delete_transient('test_offset');

      if($offset = get_transient('test_offset')){
        $ms_api_args['offset'] = 5555;
      }

      $url = 'https://online.moysklad.ru/api/remap/1.1/entity/assortment?filter=productFolder=https://online.moysklad.ru/api/remap/1.1/entity/productfolder/6b0dc1f1-461b-11e9-9109-f8fc0016bd2e';

      $url = add_query_arg($ms_api_args, $url);

      $data = wooms_request($url);

      if(empty($data["rows"])){
        echo 'stop';
        echo '<pre>';
        var_dump($data);

        return;
      }

      var_dump($url);

      $i = 0;
      foreach ($data["rows"] as $item) {
        $i++;
        if('product' == $item["meta"]["type"]){
          continue;
        }

        $parent_uuid = $item["product"]['meta']['href'];
        if($parent_uuid != 'https://online.moysklad.ru/api/remap/1.1/entity/product/bc583c52-4867-11e9-9ff4-34e80008cbfb'){
          continue;
        }

        echo '<pre>';
        var_dump($item);
        echo '</pre>';

        // code...
      }

      $ms_api_args['offset'] = $ms_api_args['offset'] + $i;
      set_transient('test_offset', $ms_api_args['offset'], HOUR_IN_SECONDS);


    });

  }

  /**
   * update_variations
   */
  public static function update_variations()
  {
    $product_id = get_transient('wooms_update_single_product_id');
    if(empty($product_id)){
      $product_id = '';
      $args = array(
        'post_type' => 'product',
        'posts_per_page' => 1,
        'meta_query' => array(
          array(
            'key'     => 'wooms_need_update_variations',
            'compare' => 'EXISTS',
          ),
        ),
      );

      $posts = get_posts($args);

      if(isset($posts[0]->ID)){
        $product_id = $posts[0]->ID;
        set_transient('wooms_update_single_product_id', $product_id);
      }
    }

    if(empty($product_id)){
      return false;
    }

    $product = wc_get_product($product_id);
    $wooms_id = $product->get_meta('wooms_id', true);

    $url_args = array(
      'limit' => 10,
      'offset' => 0,
    );

    if($offset = get_transient('wooms_update_single_product_offset')){
      $url_args['offset'] = $offset;
    } else {
      $offset = 0;
    }

    $url = 'https://online.moysklad.ru/api/remap/1.1/entity/variant/?filter=productid=' . $wooms_id;
    $url = add_query_arg($url_args, $url);

    $data_api = wooms_request($url);

    if(empty($data_api['rows'])){
      //finish
      delete_transient('wooms_update_single_product_id');
      delete_transient('wooms_update_single_product_offset');
      $product->delete_meta_data('wooms_need_update_variations');
      $product->save();
      return true;
    }

    $i = 0;
    foreach ($data_api['rows'] as $item) {
      $i++;
      do_action( 'wooms_products_variations_item', $item );
    }

    set_transient( 'wooms_update_single_product_offset', $offset + $i );

  }


  /**
   * save
   */
  public static function save($post_id){
    if( ! empty($_REQUEST['wooms_product_sinle_sync']) ){
      self::sync($post_id);
    }
  }

  /**
   * sync
   */
  public static function sync($post_id = ''){
    if(empty($post_id)){
      return false;
    }

    $product = wc_get_product($post_id);
    $uuid = $product->get_meta('wooms_id', true);
    if(empty($uuid)){
      return false;
    }

    // $uuid = 'bc583c52-4867-11e9-9ff4-34e80008cbfb';

    $url = 'https://online.moysklad.ru/api/remap/1.1/entity/product/' . $uuid;

    $data = wooms_request($url);


    do_action( 'wooms_product_data_item', $data );

    do_action( 'wooms_product_import_row', $data, '', '' );

    if( ! empty($data['modificationsCount']) ){
      $product->update_meta_data('wooms_need_update_variations', 1);
      $product->save();
    }

  }


  /**
   * display_checkbox
   */
  public static function display_checkbox($product_id = ''){

    $product = wc_get_product($product_id);
    $need_update_variations = $product->get_meta('wooms_need_update_variations', true);
    echo '<hr/>';
    printf('<p>%s</p>', 'Функция для тестирования');
    if(empty($need_update_variations)){
      printf(
        '<input id="wooms-product-single-sync" type="checkbox" name="wooms_product_sinle_sync"> <label for="wooms-product-single-sync">%s</label>',
        'Синхронизировать отдельно'
      );
    } else {
      printf('<p>%s</p>', 'Вариации ждут очереди на обновление');
    }

  }

}

Single::init();
