<?php
/**
 * Update attributes for products from custom fields MoySklad
 */
class WooMS_Product_Attributes
{
  /**
   * The Init
   */
  public static function init()
  {
    //Use hook do_action('wooms_product_update', $product_id, $value, $data);
    add_action('wooms_product_update', array(__CLASS__, 'update_data'), 10, 3);

    add_filter('wooms_attributes', array(__CLASS__, 'update_country'), 10, 3);
    add_filter('wooms_attributes', array(__CLASS__, 'save_other_attributes'), 10, 3);

    add_action( 'admin_init', array(__CLASS__, 'settings_init'), 150 );
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

      if( ! empty($item['weight']) ){
          $product->set_weight($item[weight]);
      }

      if( ! empty($item['attributes']) ){
          foreach ($item['attributes'] as $attribute) {
              if(empty($attribute['name'])){
                  continue;
              }

              if($attribute['name'] == 'Ширина'){
                  $product->set_width($attribute['value']);
                  continue;
              }

              if($attribute['name'] == 'Высота'){
                  $product->set_height($attribute['value']);
                  continue;
              }

              if($attribute['name'] == 'Длина'){
                  $product->set_length($attribute['value']);
                  continue;
              }

          }
      }


      $product_attributes = $product->get_attributes('edit');

      if(empty($product_attributes)){
        $product_attributes = array();
      }

      $product_attributes = apply_filters('wooms_attributes', $product_attributes, $product_id, $item, $data);

      $product->set_attributes( $product_attributes );
      $product->save();

   }


  /**
  * Сохраняем прочие атрибуты, не попавшивае под базовые условия
  */
  public static function save_other_attributes($product_attributes, $product_id, $value)
  {
      if( ! empty($value['attributes']) ){
          foreach ($value['attributes'] as $attribute) {
              if(empty($attribute['name'])){
                  continue;
              }

              if(in_array($attribute['name'], array('Ширина', 'Высота', 'Длина', 'Страна') )){
                  continue;
              }

              //Если это не число и не строка - пропуск, тк другие типы надо обрабатывать иначе
              if( ! in_array($attribute['type'], array('string', 'number')) ){
                  continue;
              }

              $attribute_object = new WC_Product_Attribute();
              $attribute_object->set_name( $attribute['name'] );
              $attribute_object->set_options( array($attribute['value']) );
              $attribute_object->set_position( 0 );
              $attribute_object->set_visible( 1 );
              $attribute_object->set_variation( 0 );
              $product_attributes[] = $attribute_object;
          }
      }
      return $product_attributes;
  }

  /**
  * Country - update
  */
  public static function update_country($product_attributes, $product_id, $value)
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
  public static function settings_init()
  {
    register_setting('mss-settings', 'wooms_attr_enabled');
    add_settings_field(
      $id = 'wooms_attr_enabled',
      $title = 'Включить синхронизацию доп. полей как атрибутов',
      $callback = [__CLASS__, 'wooms_attr_enabled_display'],
      $page = 'mss-settings',
      $section = 'woomss_section_other'
    );
  }

  /**
   * Field display
   */
  public static function wooms_attr_enabled_display()
  {
    $option = 'wooms_attr_enabled';
    printf('<input type="checkbox" name="%s" value="1" %s />', $option, checked( 1, get_option($option), false ));
    echo '<p>Позволчет синхронизировать доп поля МойСклад как атрибуты продукта. Вес, ДВШ - сохраняются в базовые поля продукта, остальные поля как индивидуальные атрибуты.</p>';
    echo '<p><strong>Тестовый режим. Не включайте эту функцию на реальном сайте, пока не проверите ее на тестовой копии сайта.</strong></p>';
  }
}

WooMS_Product_Attributes::init();
