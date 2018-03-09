<?php

/**
 * Import variants from MoySklad
 */
class WooMS_Product_Variations
{

  function __construct(){
    add_action( 'admin_init', array($this, 'settings_init'), 150 );

    //Use hook do_action('wooms_product_update', $product_id, $value, $data);
    add_action('wooms_product_update', [$this, 'load_data'], 10, 3);

  }




  function load_data($product_id, $item, $data)
  {
    if( empty(get_option('woomss_variations_sync_enabled')) ){
      return;
    }

    if(empty($item['modificationsCount'])){
      $this->remove_varaitions_for_product($product_id);
    } else {
      $this->set_product_as_variable($product_id);
    }

    //Get and cache $characteristics
    if(empty($characteristics = get_transient('wooms_characteristics'))){
      $characteristics = $this->get_all_characteristics();
      if( ! empty($characteristics) ){
        set_transient('wooms_characteristics', $characteristics, DAY_IN_SECONDS);
      }
    }

    // $this->update_product_attributes($product_id);

    $url = sprintf('https://online.moysklad.ru/api/remap/1.1/entity/variant?filter=productid=%s', $item['id']);

    $data = wooms_get_data_by_url($url);

    $this->set_product_attributes_for_variation($product_id, $data);

    $this->update_variations_for_product($product_id, $data);

    return;


  }

  function update_variations_for_product($product_id, $data){

    if(empty($data["rows"])){
      return false;
    }

    foreach ($data["rows"] as $key => $value) {

        if( ! $variation_id = $this->get_variation_by_wooms_id($value['id']) ){
          $variation_id = $this->add_variation($product_id, $value);
        }

        $this->set_variation_attributes($variation_id, $value['characteristics']);

        $variation = wc_get_product($variation_id);

        $variation->set_name( $value['name'] );

        if( ! empty($value["salePrices"][0]['value'])){
          $price = $value["salePrices"][0]['value']/100;
          $variation->set_price( $price );
          $variation->set_regular_price( $price );

        }

        $variation->save();

        do_action('wooms_variation_id', $variation_id, $value, $data);
    }
  }

  /**
  * Set attributes and value for variation
  */
  function set_variation_attributes($variation_id, $characteristics){
    $attributes = [];
    foreach ($characteristics as $key => $characteristic) {
      $attribute_name = sanitize_title( $characteristic['name'] );
      $attributes[$attribute_name] = $characteristic['value'];
    }

    $variation = wc_get_product($variation_id);
    $variation->set_attributes( $attributes );
    $variation->save();

  }

  /**
  * Set attributes for variables
  */
  function set_product_attributes_for_variation($product_id, $data){

    $ms_attributes = [];

    foreach ($data['rows'] as $key => $row) {
      foreach ($row['characteristics'] as $key => $characteristic) {
        $ms_attributes[$characteristic['id']] = [
          'name' => $characteristic["name"],
          'values' => [],
        ];
      }
    }

    foreach ($data['rows'] as $key => $row) {
      foreach ($row['characteristics'] as $key => $characteristic) {
        $ms_attributes[$characteristic['id']]['values'][] = $characteristic['value'];
      }
    }

    foreach ($ms_attributes as $key => $value) {
      $ms_attributes[$key]['values'] = array_unique($value['values']);
    }

    $attributes = [];

    foreach ($ms_attributes as $key => $value) {

      $attribute_object = new WC_Product_Attribute();
      // $attribute_object->set_id( $key );
      $attribute_object->set_name( $value['name'] );
      $attribute_object->set_options( $value['values'] );
      $attribute_object->set_position( 0 );
      $attribute_object->set_visible( 1 );
      $attribute_object->set_variation( 1 );
      $attributes[] = $attribute_object;

    }

    $product = wc_get_product($product_id);
    $product->set_attributes($attributes);
    $product->save();

    // var_dump($product); exit;

  }

  function add_variation($product_id, $value){

    $variation = new WC_Product_Variation();
    $variation->set_parent_id( absint( $product_id ) );
    $variation->set_status( 'publish' );

    $variation->save();

    $variation_id = $variation->get_id();

    if( empty($variation_id) ){
      return  false;
    } else {
      update_post_meta($variation_id, 'wooms_id', $value['id']);
      do_action('wooms_add_variation', $variation_id, $product_id, $value);
      return $variation_id;
    }
  }


  function get_variation_by_wooms_id($id){
    // $posts = get_posts('post_type=product_variation' );
    $posts = get_posts('post_type=product_variation&meta_key=wooms_id&meta_value=' . $id );

    if(empty($posts)){
      return false;
    } else {
      return $posts[0]->ID;
    }

  }














  function set_product_as_variable($product_id){

    $product = wc_get_product($product_id);

    if( ! $product->is_type('variable')){
      $r = wp_set_post_terms( $product_id, 'variable', 'product_type' );
      return true;
    } else {
      return true;
    }

  }


  function get_all_characteristics(){
    $url = 'https://online.moysklad.ru/api/remap/1.1/entity/variant/metadata';
    $data = wooms_get_data_by_url($url);

    if(empty($data["characteristics"])){
      return false;
    }

    $data_result = [];
    foreach ($data["characteristics"] as $key => $value) {
      $data_result[] = [
        'id' => $value["id"],
        'name' => $value["name"],
        'type' => $value["type"],
        'required' => $value["required"]
      ];
    }

    if(empty($data_result)){
      return false;
    } else {
      return $data_result;
    }

  }

  function update_product_attributes($product_id){

    $uuid = get_post_meta($product_id, 'wooms_id', true);

    if(empty($uuid)){
      return false;
    }


    $product = wc_get_product($product_id);
  }



  function remove_varaitions_for_product($product_id){
    //todo make remove variation for product
    return true;
  }




  function prepare_product_attributes_and_characteristic($characteristic, $product_variation_id){

    $variation = wc_get_product($product_variation_id);

    $product_id = $variation->get_parent_id();

    if(empty($product_id)){
      return false;
    }

    $product = wc_get_product($product_id);

    if(empty($product)){
      return false;
    }

    $attributes = $product->get_attributes();


  }



  function load_data_v0(){

    echo '<p>load data start...</p>';

    $offset = 0;

    if( ! empty($_REQUEST['offset'])){
      $offset = intval($_REQUEST['offset']);
    }

    $url_get = add_query_arg(
                  array(
                    'offset' => $offset,
                    'limit' => 25
                  ),
                  'https://online.moysklad.ru/api/remap/1.1/entity/variant/');



    $data = $this->get_data_by_url( $url_get );
    $rows = $data['rows'];

    printf('<p>Объем записей: %s</p>', $data['meta']['size']);

    foreach ($rows as $key => $row) {
      printf('<h2>%s</h2>', $row['name']);


      $product_data = $this->get_data_by_url($row['product']['meta']['href']);

      if(empty($product_data['article'])){
        continue;
      } else {
        $article = $product_data['article'];
      }

      $product_id = intval(wc_get_product_id_by_sku($article));

      if(empty($product_id)){
        echo '<p>no found products</p>';
        continue;
      }



      $product_type = get_the_terms( $product_id, 'product_type' );

      //Convert product type from array as string
      if( ! empty($product_type)){
        $product_type = $product_type[0]->name;
      }

      if($product_type != 'variable'){
        wp_set_object_terms( $product_id, 'variable', 'product_type' );

        printf('<p>+ Set product as: %s</p>', 'variable');
      }

      $this->save_variations_data($product_id, $row);

    }

  }



  function save_variations_data($product_id = 0, $data_variation){

    if(empty($product_id))
      return;

    printf('<p>for product id: %s, name: %s</p>', $product_id, get_the_title( $product_id ));
    printf('<p><a href="%s">edit link</a></p>', get_edit_post_link( $product_id, '' ));

    // printf('<hr><pre>%s</pre><hr>', print_r($data_variation, true));

    $product = wc_get_product($product_id);

    $characteristics = $data_variation['characteristics'];

    printf('<p># try update characteristics. count: %s</p>', count($characteristics));
    $this->save_characteristics_as_attributes($product_id, $characteristics);


    if ( $product->is_type( 'variable' ) && $product->has_child() ) {
      $variations = $product->get_children();
    }

    printf('<p># Check and get isset variation for %s</p>', $data_variation['id']);

    //Isset variation?
    $check_variations = get_posts( array(
      'meta_key' => 'woomss_id',
      'meta_value' => esc_textarea($data_variation['id']),
      'include' => $variations,
      'post_parent'  => $product_id,
      'post_type' => 'product_variation'
    ));


    if( empty($check_variations) ){

      //create variation from data
      $variation_post_title = esc_textarea($data_variation['name']);

      $new_variation = array(
        'post_title'   => $variation_post_title,
        'post_content' => '',
        'post_status'  => 'publish',
        'post_author'  => get_current_user_id(),
        'post_parent'  => $product_id,
        'post_type'    => 'product_variation'
      );

      $variation_id = wp_insert_post( $new_variation );

      update_post_meta( $variation_id, 'woomss_id', esc_textarea($data_variation['id']) );

      // $key_pa = 'pa_' . esc_textarea($characteristic['id']);
      // update_post_meta( $variation_id, $key_pa, esc_textarea($characteristic['value']) );


      printf('<p>+ Added variation: %s</p>', $data_variation['name']);


    } else {
      //Get variation id
      $variation_id = $check_variations[0]->ID;
      $variation = $product->get_child($variation_id);

      printf('<p>- Isset variation: %s</p>', $variation_id);

    }

    printf('<p># Try update data for variation %s</p>', $variation_id);

    foreach ($characteristics as $characteristic) {
      $attribute_key           = 'attribute_' . sanitize_title( $characteristic['name'] );
      if( update_post_meta( $variation_id, $attribute_key, $characteristic['value']) ){
        printf('<p>+ Update attribute key: %s</p>', $attribute_key);
      } else {
        printf('<p>- Attribute key isset: %s</p>', $attribute_key);
      }
    }

    $status_update = wc_update_product_stock_status( $variation_id, 'instock' );
    printf('<p>+ Stock status update: %s</p>', 'ok');


    // printf('<pre>%s</pre>', print_r($data_variation, true));


    $product = (array)$product;
    unset($product['post']->post_content);


    return true;
  }


  /**
   * Save attributes after check from data MS
   *
   * @param integer $product_id
   * @return return type
   */
  function save_characteristics_as_attributes($product_id, $characteristics){

        $product = wc_get_product($product_id);


        //Check and save characteristics as attributes with variation tag
        foreach ($characteristics as $characteristic) {


          $key_pa = 'pa_' . $characteristic['id'];
          $attributes = $product->get_attributes();

          $saved_value = $product->get_attribute( $key_pa );

          if(empty($saved_value)){
            $attributes[$key_pa] = array(
              'name' => esc_textarea($characteristic['name']),
              'value' => esc_textarea($characteristic['value']),
              'position' => 0,
              'is_visible' => 0,
              'is_variation' => 1,
              'is_taxonomy' => 0
            );
            printf('<p>+ Attribute "%s". Added with value: %s</p>', $characteristic['name'], $characteristic['value']);
            //Save $attributes in metafield
            update_post_meta($product_id, '_product_attributes', $attributes);


          } else {
            //Если атрибут есть, но значение не совпадает

            $values_array = array_map('trim', explode("|", $saved_value));

            if ( ! in_array(esc_textarea($characteristic['value']), $values_array) ){
              $attributes[$key_pa]['value'] .= ' | ' . esc_textarea($characteristic['value']);
              printf('<p>+ Attribute "%s". Saved value: %s</p>', $characteristic['name'], $attributes[$key_pa]['value']);

              //Save $attributes in metafield
              update_post_meta($product_id, '_product_attributes', $attributes);

            }
          }


        }
  }


  function settings_init()
  {
    register_setting('mss-settings', 'woomss_variations_sync_enabled');
    add_settings_field(
      $id = 'woomss_variations_sync_enabled',
      $title = 'Включить синхронизацию вариаций',
      $callback = [$this, 'woomss_variations_sync_enabled_display'],
      $page = 'mss-settings',
      $section = 'woomss_section_other'
    );
  }

  function woomss_variations_sync_enabled_display(){
    $option = 'woomss_variations_sync_enabled';
    printf('<input type="checkbox" name="%s" value="1" %s />', $option, checked( 1, get_option($option), false ));
    ?>
    <p><strong>Тестовый режим. Не включайте эту функцию на реальном сайте, пока не проверите ее на тестовой копии сайта.</strong></p>
    <?php
  }

}

new WooMS_Product_Variations;
