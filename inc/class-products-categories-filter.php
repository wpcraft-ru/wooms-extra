<?php

namespace WooMS\Products;

if ( ! defined( 'ABSPATH' ) ) {
  exit; // Exit if accessed directly
}

/**
 * Import Product Categories from MoySklad
 */
class Categories_Filter {

  /**
   * WooMS_Import_Product_Categories constructor.
   */
  public static function init() {

    add_action( 'admin_init', array( __CLASS__, 'settings_init' ), 50 );

    if ( ! empty( get_option( 'woomss_include_categories_sync' ) ) ) {

      add_filter( 'wooms_url_get_products', array( __CLASS__, 'add_filter_by_folder' ), 10 );
      add_filter( 'wooms_url_get_variants', array( __CLASS__, 'add_filter_by_folder' ), 10 );

      add_action( 'wooms_main_walker_started', array( __CLASS__, 'delete_parent_category' ) );

      add_action( 'wooms_update_category', array( __CLASS__, 'update_meta_session_term' ) );

      add_action('wooms_recount_terms', array( __CLASS__, 'recount_terms' ));

    }

  }

  /**
   * recount_terms
   */
  public static function recount_terms(){
    $product_cats = get_terms(
      'product_cat', array(
        'hide_empty' => false,
        'fields'     => 'id=>parent',
      )
    );
    _wc_term_recount( $product_cats, get_taxonomy( 'product_cat' ), true, false );

    $product_tags = get_terms(
      'product_tag', array(
        'hide_empty' => false,
        'fields'     => 'id=>parent',
      )
    );
    _wc_term_recount( $product_tags, get_taxonomy( 'product_tag' ), true, false );
  }

  /**
   * Добавляем фильтр по папке
   * Если выбрана группа для синка
   * Use $url_api = apply_filters('wooms_url_get_products', $url);
   */
  public static function add_filter_by_folder( $url ) {
    if ( empty( self::select_category() ) ) {
      return $url;
    }

    $arg = array(
      'filter' => 'productFolder=' . self::select_category(),
    );

    $url = add_query_arg( $arg, $url );

    return $url;
  }

  /**
   * Wrapper to get the option value
   *
   * @return mixed
   */
  public static function select_category() {

    return get_option( 'woomss_include_categories_sync' );
  }

  /**
   * get_select_category_sync_id description
   */
  public static function get_uuid_selected_category_sync() {
    $uuid = '';
    if( $url = get_option( 'woomss_include_categories_sync' )){
      $uuid = str_replace(
        'https://online.moysklad.ru/api/remap/1.1/entity/productfolder/',
        '',
        $url
      );
    }

    if(empty($uuid)){
      return false;
    } else {
      return $uuid;
    }
  }

  /**
   * Skipping the update category by sync time
   *
   * @param $bool
   * @param $url
   * @param $path_name
   *
   * @since 1.8.7
   *
   * @return bool
   */
  public static function skip_update_category() {

    return false;
  }


  /**
   * We write the time stamp of the session in the meta of the term
   *
   * @param $term_id
   */
  public static function update_meta_session_term( $term_id ) {

    if ( $session_id = get_option( 'wooms_session_id' ) ) {
      update_term_meta( $term_id, 'wooms_session_id', $session_id );
    }
  }

  /**
   * Delete the parent category
   * if select specific category for sync
   */
  public static function delete_parent_category() {

    if ( empty( self::select_category() ) ) {
      return;
    }

    $session_id = get_option( 'wooms_session_id' );
    if ( empty( $session_id ) ) {
      return;
    }

    // $term_select = wooms_request( self::select_category() );
    $uuid = self::get_uuid_selected_category_sync();
    if(empty($uuid)){
      return;
    }

    $arg = array(
      'taxonomy'     => array( 'product_cat' ),
      'hide_empty' => false,
      'fields' => 'ids',
      'number' => 1,
      'meta_query'   => array(
        array(
          'key'   => 'wooms_id',
          'value' => $uuid,
        ),
      ),
    );

    $term_ids = get_terms( $arg );

    if(empty($term_ids)){
      return;
    }

    if(empty($term_ids[0])){
      return;
    }
    $term_id = $term_ids[0];

    $term_childrens = get_term_children( $term_id, 'product_cat' );
    $term_childrens = get_terms( array(
      'taxonomy' => 'product_cat',
      'number' => 100,
      'fields' => 'ids',
      'hide_empty' => false,
      'parent' => $term_id )
    );

    if ( $term_childrens and is_array( $term_childrens ) ) {
      foreach ( $term_childrens as $child_term_id ) {

        wp_update_term( $child_term_id, 'product_cat', array( 'parent' => 0 ) );
      }

      wp_update_term_count( $term_id, 'product_cat' );

    } else {
      return;
    }

  }

  /**
   * Update children terms
   *
   * @since 1.8.7
   *
   * @param $term_children
   */
  public static function update_term_children( $terms_id, $arg = array() ) {

    if ( is_array( $terms_id ) ) {
      foreach ( $terms_id as $term_id ) {
        wp_update_term( $term_id, 'product_cat', $arg );
      }
    } else {
      wp_update_term( $terms_id, 'product_cat', $arg );
    }
  }

  /**
   * Delete relationship product and term
   *
   * @since 1.8.6
   * @version 1.8.8
   * @param     $term
   *
   */
  public static function delete_relationship( $term ) {

    $args = array(
      'post_type'              => 'product',
      'post_status'            => 'publish',
      'numberposts'            => -1,
      'fields'                 => 'ids',
      'offset'                 => 0,
      'tax_query'              => array(
        array(
          'taxonomy' => 'product_cat',
          'field'    => 'ids',
          'terms'    => $term->term_id,
        ),
      ),
      'no_found_rows'          => 1,
      'update_post_term_cache' => 0,
      'update_post_meta_cache' => 0,
      'cache_results'          => 0,
    );

    $products = get_posts( $args );

    if ( $products ) {
      foreach ( $products as $product ) {
        wp_remove_object_terms( $product, $term->term_id, 'product_cat' );
      }
    }
  }

  /**
   * Requests category to settings
   */
  public static function setting_request_category() {

    $offset      = 0;
    $limit       = 100;
    $ms_api_args = apply_filters( 'wooms_product_ms_api_arg_category', array(
      'offset' => $offset,
      'limit'  => $limit,
    ) );
    $url     = 'https://online.moysklad.ru/api/remap/1.1/entity/productfolder';
    $url     = add_query_arg( $ms_api_args, $url );

    if( $path_filter = get_option('wooms_filter_for_select_group') ){
      $url     = add_query_arg( 'filter=pathName', $path_filter, $url );
    } else {
      $url     = add_query_arg( 'filter=pathName', '=', $url );
    }

    $url     = apply_filters( 'wooms_product_ms_api_url_category', $url );

    $data = wooms_request( $url );

    if( ! empty($data["errors"][0]["error"]) ){
      $error_msg = $data["errors"][0]["error"];
      $error_code = $data["errors"][0]["code"];
      return new \WP_Error($error_code, $error_msg);
    }

    if ( empty( $data['rows'] ) ) {
      return false;
    }

    return $data['rows'];
  }

  /**
   * Settings UI
   */
  public static function settings_init()
  {

    register_setting( 'mss-settings', 'woomss_include_categories_sync' );
    add_settings_field(
      'woomss_include_categories_sync',
      'Ограничить группой товаров',
      array(__CLASS__, 'display_woomss_include_categories_sync' ),
      'mss-settings',
      'wooms_product_cat'
    );

    register_setting( 'mss-settings', 'wooms_filter_for_select_group' );
    add_settings_field(
      $id = 'wooms_filter_for_select_group',
      $title = 'Название группы для фильтрации дочерних групп',
      $callback = array(__CLASS__, 'display_wooms_filter_for_select_group'),
      $page = 'mss-settings',
      $section = 'wooms_product_cat'
    );

  }

  /**
   * display_woomss_include_categories_sync
   */
  public static function display_woomss_include_categories_sync()
  {
    $checked_choice   = get_option( 'woomss_include_categories_sync' );
    $request_category = self::setting_request_category();

    if ( is_wp_error($request_category) ) {

      printf('<p><strong>%s</strong></p>', $request_category->get_error_message());

    } elseif ( $request_category && is_array( $request_category ) ) {

      echo '<select class="woomss_include_categories_sync" name="woomss_include_categories_sync">';
      echo '<option value="">Выберите группу</option>';
      foreach ( $request_category as $value ) {
        if ( ! empty( $value['pathName'] ) ) {
          $path_name = explode( '/', $value['pathName'] );
        } else {
          $path_name        = '';
          $path_name_margin = '';
        }

        if ( is_array( $path_name ) && ( count( $path_name ) == 1 ) ) {
          $path_name_margin = '&mdash;&nbsp;';
        } elseif ( is_array( $path_name ) && ( count( $path_name ) >= 2 ) ) {
          $path_name_margin = '&mdash;&mdash;&nbsp;';
        }
        printf( '<option value="%s" %s>%s</option>', esc_attr( $value['meta']['href'] ), selected( $checked_choice, $value['meta']['href'], false ), $path_name_margin . $value['name'] );

      }
      echo '</select>';

    } else {
      echo '<p><small>Сервер не отвечает. Требуется подождать. Обновить страницу через некоторое время</small></p>';
    }

    ?>
    <p>
      <small>После включения опции, старые товары будут помечаться как скрытые из каталога.
          Чтобы они пропали с сайта нужно убедиться, что в виджете категорий стоит опция скрывать пустые категории</small>
    </p>
    <?php

  }

  /**
   * Display field
   */
  public static function display_wooms_filter_for_select_group() {

    $option = 'wooms_filter_for_select_group';
    printf( '<input type="text" name="%s" value="%s" />', $option, get_option( $option ) );
    ?>
    <p><small>Напишите тут название группы, если нужно отфильтровать группы ниже первого уровня.</small></p>
    <p><small>Пример 1: если нужная группа "Телефоны" находится внутри группы "Электроника", то тут нужно написать: <strong>Электроника</strong></small></p>
    <p><small>Пример 2: если нужная группа "Аксессуары" находится внутри групп "Компьютеры/Ноутбуки", то тут нужно написать: <strong>Компьютеры/Ноутбуки</strong></small></p>
    <p><small>После этого в поле ограничения по группе отобразится список подгрупп с учетом данного фильтра.</small></p>
    <?php

  }

}

Categories_Filter::init();
