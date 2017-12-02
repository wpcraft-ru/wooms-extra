<?php
/**
 * Update attributes for products
 */
class WooMS_Product_Attributes
{
  function __construct()
  {
    //Use hook do_action('wooms_product_update', $product_id, $value, $data);
    add_action('wooms_product_update', [$this, 'update_data'], 10, 3);

    add_filter('wooms_attributes', [$this, 'update_base_attributes'], 10, 3);
    add_filter('wooms_attributes', [$this, 'update_custtom_attributes'], 10, 3);
    add_filter('wooms_attributes', [$this, 'update_country'], 10, 3);

    add_action( 'admin_init', array($this, 'settings_init'), 150 );
  }

  /**
   * Sync attributes
   */
  public function update_data($product_id, $item, $data)
  {
      if(empty(get_option('wooms_attr_enabled'))){
        return;
      }

      $product = wc_get_product($product_id);
      $product_attributes = $product->get_attributes('edit');

      if(empty($product_attributes)){
        $product_attributes = array();
      }

      $product_attributes = apply_filters('wooms_attributes', $product_attributes, $product_id, $item, $data);

      $product->set_attributes( $product_attributes );
      $product->save();

   }

  //Update base attributes from MoySklad
  function update_base_attributes($product_attributes, $product_id, $value){

     if( ! empty($value['weight'])){
       $attribute_object = new WC_Product_Attribute();
  		 $attribute_object->set_name( "Вес" );
  		 $attribute_object->set_options( array($value['weight']) );
  		 $attribute_object->set_position( '0' );
  		 $attribute_object->set_visible( 1 );
  		 $attribute_object->set_variation( 0 );
  		 $product_attributes[] = $attribute_object;
     }

     if( ! empty($value['volume'])){
       $attribute_object = new WC_Product_Attribute();
  		 $attribute_object->set_name( "Объем" );
  		 $attribute_object->set_options( array($value['volume']) );
  		 $attribute_object->set_position( '0' );
  		 $attribute_object->set_visible( 1 );
  		 $attribute_object->set_variation( 0 );
  		 $product_attributes[] = $attribute_object;
     }

    return $product_attributes;

  }

  // Custom fields update from MoySklad
  function update_custtom_attributes($product_attributes, $product_id, $value)
  {

    if(empty($value['attributes'])){
      return $product_attributes;
    }

    foreach ($value['attributes'] as $key => $value) {
      $attribute_object = new WC_Product_Attribute();
      $attribute_object->set_name( "Объем" );
      $attribute_object->set_options( array($value['value']) );
      $attribute_object->set_position( '0' );
      $attribute_object->set_visible( 1 );
      $attribute_object->set_variation( 0 );
      $product_attributes[] = $attribute_object;
    }

    return $product_attributes;

  }

   /**
   * Country - update
   */
  function update_country($product_attributes, $product_id, $value)
  {
    if( empty($value['country']["meta"]["href"]) ) {
      return $product_attributes;
    } else {
      $url = $value['country']["meta"]["href"];
    }

    $data_api = wooms_get_data_by_url($url);

    if(empty($data_api["name"])){
      return $product_attributes;
    } else {
      $country = sanitize_text_field($data_api["name"]);

      $attribute_object = new WC_Product_Attribute();
      $attribute_object->set_name( "Страна" );
      $attribute_object->set_options( array($country) );
      $attribute_object->set_position( '0' );
      $attribute_object->set_visible( 1 );
      $attribute_object->set_variation( 0 );
      $product_attributes[] = $attribute_object;
    }

    return $product_attributes;
  }

  /**
   * Settings UI
   */
  public function settings_init()
  {
    register_setting('mss-settings', 'wooms_attr_enabled');
    add_settings_field(
      $id = 'wooms_attr_enabled',
      $title = 'Включить синхронизацию атрибутов',
      $callback = [$this, 'wooms_attr_enabled_display'],
      $page = 'mss-settings',
      $section = 'woomss_section_other'
    );
  }

  /**
   * Field display
   */
  public function wooms_attr_enabled_display()
  {
    $option = 'wooms_attr_enabled';
    printf('<input type="checkbox" name="%s" value="1" %s />', $option, checked( 1, get_option($option), false ));
    echo '<p><strong>Тестовый режим. Не включайте эту функцию на реальном сайте, пока не проверите ее на тестовой копии сайта.</strong></p>';
  }
}

new WooMS_Product_Attributes;
