<?php

namespace WooMS;
defined('ABSPATH') || exit;



/**
 * AssortmentSync
 */
class AssortmentSync
{

  /**
   * The Init
   */
  public static function init()
  {

    // add_action('init', function () {
    //   if (!isset($_GET['dd'])) {
    //     return;
    //   }

    //   // dd(get_transient('wooms_end_timestamp'));
    //   self::set_state('timestamp', 0);

    //   self::batch_handler();

    //   dd(0);
    // });

    add_filter( 'wooms_product_save', array( __CLASS__, 'add_task_to_queue' ) );

    // add_action('init', [__CLASS__, 'add_schedule_hook']);

  }


  public static function add_task_to_queue($product){

    $product->update_meta_data('wooms_assortment_sync', 1);

    return $product;
  }

  
}

// AssortmentSync::init();