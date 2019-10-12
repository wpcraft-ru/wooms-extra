<?php

namespace WooMS;

if ( ! defined( 'ABSPATH' ) ) {
  exit; // Exit if accessed directly
}

/**
 * Single Product Import
 */
class ProductSingleSync {

    /**
     * The Init
     */
    public static function init()
    {
        add_action('wooms_display_product_metabox', array(__CLASS__, 'display_checkbox'));
        add_action('save_post', array(__CLASS__, 'save'));

        add_action('wooms_product_single_update', array(__CLASS__, 'update_variations'));

        add_action('init', function () {
            if ( ! wp_next_scheduled('wooms_product_single_update')) {
                wp_schedule_event(time(), 'wooms_cron_walker_shedule', 'wooms_product_single_update');
            }
        });

    }

    /**
     * update_variations
     */
    public static function update_variations()
    {
        $product_id = get_transient('wooms_update_single_product_id');
        if (empty($product_id)) {
            $product_id = '';
            $args       = array(
                'post_type'      => 'product',
                'post_status'      => 'any',
                'posts_per_page' => 1,
                'meta_query'     => array(
                    array(
                        'key'     => 'wooms_need_update_variations',
                        'compare' => 'EXISTS',
                    ),
                ),
            );

            $posts = get_posts($args);

            if (isset($posts[0]->ID)) {
                $product_id = $posts[0]->ID;
                set_transient('wooms_update_single_product_id', $product_id);
            }
        }

        if (empty($product_id)) {
            return false;
        }

        $product  = wc_get_product($product_id);
        $wooms_id = $product->get_meta('wooms_id', true);

        $url_args = array(
            'limit'  => 10,
            'offset' => 0,
        );

        if ($offset = get_transient('wooms_update_single_product_offset')) {
            $url_args['offset'] = $offset;
        } else {
            $offset = 0;
        }

        $url = 'https://online.moysklad.ru/api/remap/1.1/entity/variant/?filter=productid=' . $wooms_id;
        $url = add_query_arg($url_args, $url);

        do_action('wooms_logger',
            __CLASS__,
            sprintf('API запрос на вариации: %s (продукт ID %s)', $url, $product_id)
        );

        $data_api = wooms_request($url);

        if (empty($data_api['rows'])) {
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
            do_action('wooms_products_variations_item', $item);
        }

        set_transient('wooms_update_single_product_offset', $offset + $i);
        return true;
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

ProductSingleSync::init();
