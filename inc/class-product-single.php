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


    add_shortcode('test', function(){
      self::update_variations();
    });

  }

  /**
   * update_variations
   *
   * XXX получить продукт ID wooms_id и запустить синк вариации
   */
  public static function update_variations(){
    $args = array(
      'post_type' => 'product',
      'posts_per_page' => 1,
      'meta_query' => array(
        array(
          'key'     => 'wooms_need_update_variation',
          'compare' => 'EXISTS',
        ),
      ),
    );

    $posts = get_posts($args);
    if(isset($posts[0]->ID)){
      $product_id = $posts[0]->ID;
      $product = wc_get_product($product_id);
      $wooms_id = $product->get_meta('wooms_id', true);
      // self::
      echo '<pre>';
      var_dump($wooms_id);


    }

  }


  /**
   * save
   */
  public static function save(){
    if( ! empty($_REQUEST['wooms_product_sinle_sync']) ){
      self::sync();
    }
  }

  /**
   * sync
   */
  public static function sync(){
    $post = get_post();
    $product = wc_get_product($post->ID);
    $uuid = $product->get_meta('wooms_id', true);
    if(empty($uuid)){
      return false;
    }

    $url = 'https://online.moysklad.ru/api/remap/1.1/entity/product/' . $uuid;

    $data = wooms_request($url);

    do_action( 'wooms_product_data_item', $data );

    do_action( 'wooms_product_import_row', $data, '', '' );

    $product->update_meta_data('wooms_need_update_variation', 1);
    $product->save();

  }


  /**
   * display_checkbox
   */
  public static function display_checkbox(){
    echo '<hr/>';
    printf(
      '<input id="wooms-product-single-sync" type="checkbox" name="wooms_product_sinle_sync"> <label for="wooms-product-single-sync">%s</label>',
      'Синхронизировать'
    );
  }

}

Single::init();
// add_action('plugins_loaded', array( 'WooMS\Products\Single', 'init') );
