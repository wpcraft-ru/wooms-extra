<?php

namespace WooMS;

if ( ! defined( 'ABSPATH' ) ) {
  exit; // Exit if accessed directly
}

/**
 * Update attributes for products from custom fields MoySklad
 */
class ProductAttributes
{
  /**
   * The Init
   */
    public static function init()
    {
        add_action('wooms_product_save', array(__CLASS__, 'update_product'), 10, 3);

        add_filter('wooms_attributes', array(__CLASS__, 'update_country'), 10, 3);
        add_filter('wooms_attributes', array(__CLASS__, 'save_other_attributes'), 10, 3);

        add_action('admin_init', array(__CLASS__, 'settings_init'), 150);
    }

  /**
   * Get attribute id by label
   * or false
   */
  public static function get_attribute_id_by_label($label = ''){
    if(empty($label)){
      return false;
    }

    $attr_taxonomies = wc_get_attribute_taxonomies();
    if(empty($attr_taxonomies)){
      return false;
    }

    if( ! is_array($attr_taxonomies) ){
      return false;
    }

    foreach ($attr_taxonomies as $attr) {
      if($attr->attribute_label == $label){
        return (int)$attr->attribute_id;
      }
    }

    return false;
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
              $allow_data_type_for_attribures = array('string', 'number', 'customentity');

              /**
               * add new type for attributes
               *
               * @issue https://github.com/wpcraft-ru/wooms/issues/184
               */
              $allow_data_type_for_attribures = apply_filters('wooms_allow_data_types_for_attributes', $allow_data_type_for_attribures);
              if( ! in_array($attribute['type'], $allow_data_type_for_attribures) ){
                  continue;
              }

              if( ! empty($attribute['value']['name'])){
                $value = $attribute['value']['name'];
              } else {
                $value = $attribute['value'];
              }

              $attribute_name = $attribute['name'];

              $attribute_taxonomy_id = self::get_attribute_id_by_label($attribute_name);
              if($attribute_taxonomy_id){
                $taxonomy_slug = wc_attribute_taxonomy_name_by_id($attribute_taxonomy_id);
              }

              $attribute_slug = sanitize_title( $attribute_name );

              if(empty($attribute_taxonomy_id)){

                $attribute_object = new \WC_Product_Attribute();
                $attribute_object->set_name( $attribute_name );
                $attribute_object->set_options( array($value) );
                $attribute_object->set_position( 0 );
                $attribute_object->set_visible( 1 );
                $product_attributes[$attribute_slug] = $attribute_object;

              } else {

                //Очищаем индивидуальный атрибут с таким именем если есть
                if(isset($product_attributes[$attribute_slug])){
                    unset($product_attributes[$attribute_slug]);
                }

                $attribute_object = new \WC_Product_Attribute();
                $attribute_object->set_id( $attribute_taxonomy_id );
                $attribute_object->set_name( $taxonomy_slug );
                $attribute_object->set_options( array($value) );
                $attribute_object->set_position( 0 );
                $attribute_object->set_visible( 1 );
                $product_attributes[$taxonomy_slug] = $attribute_object;
              }

          }
      }
      return $product_attributes;
  }

  /**
   * Update product
   */
  public static function update_product( $product, $item, $data )
  {
    if(empty(get_option('wooms_attr_enabled'))){
      return $product;
    }
    $product_id = $product->get_id();


    if( ! empty($item['weight']) ){
        $product->set_weight($item['weight']);
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

    return $product;
  }

  /**
   * Sync attributes
   *
   * TODO - delete
   */
  public static function update_data($product_id, $item, $data)
  {
      if(empty(get_option('wooms_attr_enabled'))){
        return;
      }

      $product = wc_get_product($product_id);

      if( ! empty($item['weight']) ){
          $product->set_weight($item['weight']);
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
  * Country - update
  */
  public static function update_country($product_attributes, $product_id, $value)
  {
    if( empty($value['country']["meta"]["href"]) ) {
      return $product_attributes;
    } else {
      $url = $value['country']["meta"]["href"];
    }

    $data_api = wooms_request($url);

    if(empty($data_api["name"])){
      return $product_attributes;
    } else {
      $country = sanitize_text_field($data_api["name"]);

      $attribute_object = new \WC_Product_Attribute();
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
      $callback = [__CLASS__, 'display_wooms_attr_enabled'],
      $page = 'mss-settings',
      $section = 'woomss_section_other'
    );
  }

  /**
   * Field display
   */
  public static function display_wooms_attr_enabled()
  {
    $option = 'wooms_attr_enabled';
    printf('<input type="checkbox" name="%s" value="1" %s />', $option, checked( 1, get_option($option), false ));
    echo '<p>Позволчет синхронизировать доп поля МойСклад как атрибуты продукта. Вес, ДВШ - сохраняются в базовые поля продукта, остальные поля как индивидуальные атрибуты.</p>';
    echo '<p><strong>Тестовый режим. Не включайте эту функцию на реальном сайте, пока не проверите ее на тестовой копии сайта.</strong></p>';
  }
}

ProductAttributes::init();
