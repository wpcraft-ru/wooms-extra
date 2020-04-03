<?php
namespace WooMS;

/**
 * Products Bundle Managment
 */
class GroupedProducts
{
  /**
   * The init
   */
  public static function init()
  {
    add_action( 'wooms_product_save', array( __CLASS__, 'update_product' ), 20, 2 );
    add_action('admin_init', array(__CLASS__, 'settings_init'), 150);
  }


  /**
   * Update product
   */
  public static function update_product($product, $value)
  {
    if (!get_option('wooms_products_bundle_enable')) {
      return $product;
    }

    //If no components - skip
    if (empty($value["components"])) {
      return $product;
    }

    if (empty($value["components"]["meta"]["href"])) {
      return $product;
    }

    $url_api = $value["components"]["meta"]["href"];


    $data_components = wooms_request($url_api);

    if (empty($data_components["rows"])) {
      return $product;
    }

    if (!$product->is_type('grouped')) {

      $product = new \WC_Product_Grouped($product);

      do_action(
        'wooms_logger',
        __CLASS__,
        sprintf('Продукт выбран как групповой %s (%s)', $product->get_id(), $product->get_name())
      );
    }

    $subproducts_ids = array();
    foreach ($data_components["rows"] as $row_component) {
      $product_uuid = str_replace('https://online.moysklad.ru/api/remap/1.1/entity/product/', '', $row_component["assortment"]["meta"]["href"]);
      $subproduct_id = self::get_product_id_by_uuid($product_uuid);

      if (empty($subproduct_id)) {
        continue;
      }

      $subproducts_ids[] = $subproduct_id;
    }

    $product->set_children($subproducts_ids);

    do_action(
      'wooms_logger',
      __CLASS__,
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
   * settings_ui
   */
  public static function settings_init()
  {
    $option_id = 'wooms_products_bundle_enable';
    register_setting('mss-settings', $option_id);
    add_settings_field(
      $id = $option_id,
      $title = 'Включить работу с групповыми продуктами (комплекты МойСклад)',
      $callback = function ($args) {
        printf('<input type="checkbox" name="%s" value="1" %s />', $args['name'], checked(1, $args['value'], false));
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

}

GroupedProducts::init();
