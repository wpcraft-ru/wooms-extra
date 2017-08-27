<?php


/**
 * Update attributes for products
 */
class WooMS_Product_Attributes
{

  function __construct()
  {
    add_action( 'admin_init', array($this, 'settings_init'), 100 );

    //Use hook do_action('wooms_product_update', $product_id, $value, $data);
    add_action('wooms_product_update', [$this, 'load_data'], 10, 3);

    add_filter('wooms_attributes', [$this, 'update_attributes'], 10, 3);
    add_filter('wooms_attributes', [$this, 'update_country'], 10, 3);

  }

  function update_country($product_attributes, $product_id, $value){


    if( empty($value['country']["meta"]["href"]) ) {
      return $product_attributes;
    } else {
      $url = $value['country']["meta"]["href"];
    }

    $data_api = wooms_get_data_by_url($url);

    if(empty($data_api["name"])){
      return $product_attributes;
    } else {
      $country = sanitize_meta($data_api["name"]);

      $product_attributes['country'] = [
        'name' => 'Страна',
        'value' => $country,
        'position' => 0,
        'is_visible' => 1,
        'is_variation' => 0,
        'is_taxonomy' => 0
      ];
    }

    return $product_attributes;

  }


  function update_attributes($product_attributes, $product_id, $value)
  {

    if(empty($value['attributes'])){
      return $product_attributes;
    }

    // $product_attributes_current = get_post_meta($product_id, '_product_attributes', true);

    foreach ($value['attributes'] as $key => $value) {
      $product_attributes[$value['id']] = [
        'name' => $value['name'],
        'value' => $value['value'],
        'position' => 0,
        'is_visible' => 1,
        'is_variation' => 0,
        'is_taxonomy' => 0
      ];
    }

    return $product_attributes;

  }

  /**
   * Sync attributes
   */
  public function load_data($product_id, $item, $data)
  {

      if(empty(get_option('wooms_attr_enabled'))){
        return false;
      }

      $atts = $this->get_atts($item);

      if(empty($atts)){
        return false;
      }

      $product_attributes = array();

      foreach ($atts as $key => $value) {
        $product_attributes[$key] = array(
            'name' => $value['name'],
            'value' => $value['value'],
            'position' => 0,
            'is_visible' => 1,
            'is_variation' => 0,
            'is_taxonomy' => 0
        );

      }

      $product_attributes = apply_filters('wooms_attributes', $product_attributes, $product_id, $item, $data);

      update_post_meta($product_id, '_product_attributes', $product_attributes);

   }

   //Prepare array attributes from data
   function get_atts($data){

     $atts = array();

     if( ! empty($data['weight'])){
       $atts['weight'] = [
         'value' => $data['weight'],
         'name' => "Вес",
       ];
     }

     if( ! empty($data['volume'])){
       $atts['volume'] = [
         'value' => $data['volume'],
         'name' => "Объем",
       ];
     }

    return $atts;

  }


  /**
   * Settings UI
   */
  public function settings_init()
  {
    add_settings_section(
      'woomss_section_attributes',
      'Атрибуты',
      null,
      'mss-settings'
    );

    register_setting('mss-settings', 'wooms_attr_enabled');
    add_settings_field(
      $id = 'wooms_attr_enabled',
      $title = 'Включить синхронизацию атрибутов',
      $callback = [$this, 'wooms_attr_enabled_display'],
      $page = 'mss-settings',
      $section = 'woomss_section_attributes'
    );

  }

  /**
   * Field display
   */
  public function wooms_attr_enabled_display()
  {
    $option = 'wooms_attr_enabled';
    printf('<input type="checkbox" name="%s" value="1" %s />', $option, checked( 1, get_option($option), false ));
  }
}

new WooMS_Product_Attributes;
