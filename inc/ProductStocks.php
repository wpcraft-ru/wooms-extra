<?php

namespace WooMS;

if ( ! defined( 'ABSPATH' ) ) {
  exit; // Exit if accessed directly
}

/**
 * Synchronization the stock of goods from MoySklad
 */
class ProductStocks {

  /**
   * The init
   */
  public static function init() {

    add_filter( 'wooms_product_save', array( __CLASS__, 'update_product' ), 30, 3 );

    add_filter( 'wooms_save_variation', array(__CLASS__, 'update_variation'), 30, 3);

    add_action( 'admin_init', array( __CLASS__, 'settings_init' ), 30 );

    add_filter( 'wooms_stock_type', array(__CLASS__, 'select_type_stock'));

    if ( ! empty( get_option( 'woomss_warehouse_id' ) ) ) {
      add_filter( 'wooms_url_get_products', array( __CLASS__, 'add_filter_by_warehouse_id' ), 10 );
      add_filter( 'wooms_url_get_variants', array( __CLASS__, 'add_filter_by_warehouse_id' ), 10 );
    }

  }

  /**
   * add_filter_by_warehouse_id
   */
  public static function add_filter_by_warehouse_id($url){

    $warehouse_id = get_option( 'woomss_warehouse_id' );
    if(empty($warehouse_id)){
      return $url;
    }

    $arg = array(
      'stockstore' => sprintf('https://online.moysklad.ru/api/remap/1.1/entity/store/%s', $warehouse_id),
    );

    $url = add_query_arg( $arg, $url );

    return $url;
  }

  /**
   * Select type stock
   */
  public static function select_type_stock($type_stock){
    if(get_option('wooms_stocks_without_reserve')){
      $type_stock = 'stock';
    }

    return $type_stock;
  }

  /**
   * Update stock for variation
   */
  public static function update_variation( $variation, $data_api, $product_id ) {

    $variant_data = $data_api;

    if ( empty( get_option( 'woomss_stock_sync_enabled' ) ) ) {

      $variation->set_manage_stock( 'no' );
      $variation->set_stock_status( 'instock' );

      return $variation;
    }

    $variation_id = $variation->get_id();

      /**
       * Поле по которому берем остаток?
       * quantity = это доступные остатки за вычетом резервов
       * stock = это все остатки по организации
       */
      $stock_type = apply_filters('wooms_stock_type', 'quantity');

      if (empty($variant_data[$stock_type])) {
          $stock = 0;
      } else {
          $stock = (int)$variant_data[$stock_type];

          $stock_log = array(
              'quantity' => $data_api['quantity'],
              'stock'    => $data_api['stock'],
          );
      }

      if (empty($stock)) {
          $url = "https://online.moysklad.ru/api/remap/1.1/report/stock/all";
          if (get_option('woomss_warehouses_sync_enabled') && $warehouse_id = get_option('woomss_warehouse_id')) {
              $url = add_query_arg(array('store.id' => $warehouse_id), $url);
          }

          $url = add_query_arg('product.id', $variant_data['id'], $url);

          do_action('wooms_logger', __CLASS__,
              sprintf('Запрос на остатки для вариации %s (url %s)', $variation_id, $url)
          );

          $data = wooms_request($url);

          $stock_log = array(
              'quantity' => empty($data['rows'][0]['quantity']) ? 0 : $data['rows'][0]['quantity'],
              'stock'    => empty($data['rows'][0]['stock']) ? 0 : $data['rows'][0]['stock'],
          );

          if (empty($data['rows'][0][$stock_type])) {
              $stock = 0;
          } else {
              $stock = (int)$data['rows'][0][$stock_type];
          }
      }

      if (empty(get_option('wooms_warehouse_count'))) {
          $variation->set_manage_stock('no');
      } else {
          $variation->set_manage_stock('yes');
      }

      if (get_option('wooms_stock_empty_backorder')) {
          $variation->set_backorders('notify');
      } else {
          $variation->set_backorders('no');
      }

      if ($stock <= 0) {
          $variation->set_stock_quantity(0);
          $variation->set_stock_status('outofstock');
      } else {
          $variation->set_stock_quantity($stock);
          $variation->set_stock_status('instock');
      }

      do_action('wooms_logger', __CLASS__,
          sprintf(
              'Остатки для вариации %s (продукт: %s) = %s (stock = %s, quantity = %s)',
              $variation_id,
              $product_id,
              $stock,
              $stock_log['stock'],
              $stock_log['quantity']
          )
      );

      return $variation;
  }

    /**
     * Update product
     */
    public static function update_product($product, $data_api, $data)
    {
        $item = $data_api;

        if (empty(get_option('woomss_stock_sync_enabled'))) {
            $product->set_manage_stock('no');
            $product->set_stock_status('instock');

            return $product;
        }

        $product_id = $product->get_id();

        /**
         * Поле по которому берем остаток?
         * quantity = это доступные остатки за вычетом резервов
         * stock = это все остатки без уета резерва
         */
        $stock_type = apply_filters('wooms_stock_type', 'quantity');

        $stock = 0;

        if (empty($data_api[$stock_type])) {
            $stock = 0;
        } else {
            $stock     = (int)$data_api[$stock_type];
            $stock_log = array(
                'quantity' => $data_api['quantity'],
                'stock'    => $data_api['stock'],
            );
        }

        if (empty($stock)) {
            $url = "https://online.moysklad.ru/api/remap/1.1/report/stock/all";

            $query_args = [
                'product.id' => $item['id'],
            ];

            if (get_option('woomss_warehouses_sync_enabled') && $warehouse_id = get_option('woomss_warehouse_id')) {
                $query_args['store.id'] = $warehouse_id;
            }
            $url = add_query_arg($query_args, $url);

            $data = wooms_request($url);

            do_action('wooms_stock_by_product', $data, $product_id);

            do_action('wooms_logger', __CLASS__,
                sprintf('Запрос %s, на остатки для %s', $url, $product_id)
            );

            if (empty($data['rows'][0][$stock_type])) {
                $stock = 0;
            } else {
                $stock = (int)$data['rows'][0][$stock_type];
            }

            $stock_log = array(
                'quantity' => empty($data['rows'][0]['quantity']) ? 0 : $data['rows'][0]['quantity'],
                'stock'    => empty($data['rows'][0]['stock']) ? 0 : $data['rows'][0]['stock'],
            );
        }

        if (get_option('wooms_stock_empty_backorder')) {
            $product->set_backorders('notify');
        } else {
            $product->set_backorders('no');
        }

        if (empty(get_option('wooms_warehouse_count'))) {
            $product->set_manage_stock('no');
        } else {
            if ($product->is_type('variable')) {

                //для вариативных товаров доступность определяется наличием вариаций
                $product->set_manage_stock('no');
            } else {
                $product->set_manage_stock('yes');
            }
        }

        if ($stock <= 0) {
            if ( ! $product->is_type('variable')) {
                $product->set_stock_quantity(0);
                $product->set_stock_status('outofstock');
            }
        } else {
            $product->set_stock_quantity($stock);
            $product->set_stock_status('instock');
        }

        do_action('wooms_logger', __CLASS__,
            sprintf('Остатки для продукта ИД %s = %s', $product_id, $stock),
            sprintf('stock %s, quantity %s', $stock_log['stock'], $stock_log['quantity'])
        );

        return $product;
    }

  /**
   * Settings UI
   */
  public static function settings_init() {

    add_settings_section(
      'woomss_section_warehouses',
      'Склад и остатки',
      $callback = array( __CLASS__, 'display_woomss_section_warehouses'),
      'mss-settings'
    );

    register_setting( 'mss-settings', 'woomss_stock_sync_enabled' );
    add_settings_field(
      $id = 'woomss_stock_sync_enabled',
      $title = 'Включить работу с остатками',
      $callback = array( __CLASS__, 'woomss_stock_sync_enabled_display'),
      $page = 'mss-settings',
      $section = 'woomss_section_warehouses'
    );

    register_setting( 'mss-settings', 'wooms_stocks_without_reserve' );
    add_settings_field(
      $id = 'wooms_stocks_without_reserve',
      $title = 'Остатки без резерва',
      $callback = array( __CLASS__, 'display_field_wooms_stocks_without_reserve'),
      $page = 'mss-settings',
      $section = 'woomss_section_warehouses'
    );

    register_setting( 'mss-settings', 'wooms_warehouse_count' );
    add_settings_field(
      $id = 'wooms_warehouse_count',
      $title = 'Управление запасами на уровне товаров',
      $callback = array( __CLASS__, 'display_wooms_warehouse_count' ),
      $page = 'mss-settings',
      $section = 'woomss_section_warehouses'
    );

    register_setting( 'mss-settings', 'wooms_stock_empty_backorder' );
    add_settings_field(
      $id = 'wooms_stock_empty_backorder',
      $title = 'Разрешать предазказ при 0 остатке',
      $callback = array( __CLASS__, 'display_wooms_stock_empty_backorder' ),
      $page = 'mss-settings',
      $section = 'woomss_section_warehouses'
    );

    register_setting( 'mss-settings', 'woomss_warehouses_sync_enabled' );
    add_settings_field(
      $id = 'woomss_warehouses_sync_enabled',
      $title = 'Учитывать остатки по складу',
      $callback = array( __CLASS__, 'woomss_warehouses_sync_enabled_display' ),
      $page = 'mss-settings',
      $section = 'woomss_section_warehouses'
    );

    register_setting( 'mss-settings', 'woomss_warehouse_id' );
    add_settings_field(
      $id = 'woomss_warehouse_id',
      $title = 'Выбрать склад для сайта',
      $callback = array( __CLASS__, 'woomss_warehouse_id_display' ),
      $page = 'mss-settings',
      $section = 'woomss_section_warehouses'
     );
  }

  /**
   *
   */
  public static function display_woomss_section_warehouses() {
    ?>
    <p>Данные опции позволяют настроить обмен данным по остаткам между складом и сайтом.</p>
    <ol>
      <li>Функционал обязательно нужно проверять на тестовом сайте. Он еще проходит обкатку. В случае проблем
        сообщайте в техподдержку
      </li>
      <li>После изменения этих опций, следует обязательно <a
          href="<?php echo admin_url( 'admin.php?page=moysklad' ) ?>" target="_blank">запускать обмен данными
          вручную</a>, чтобы статусы наличия продуктов обновились
      </li>
      <li>Перед включением опций, нужно настроить магазина на работу с <a
          href="<?php echo admin_url( 'admin.php?page=wc-settings&tab=products&section=inventory' ) ?>"
          target="_blank">Запасами</a></li>
    </ol>
    <?php
  }


  /**
   * Display field
   */
  public static function woomss_stock_sync_enabled_display() {
    $option = 'woomss_stock_sync_enabled';
    printf( '<input type="checkbox" name="%s" value="1" %s />', $option, checked( 1, get_option( $option ), false ) );
    echo '<p>При включении опции товары будут помечаться как в наличии или отсутствующие в зависимиости от числа остатков на складе</p>';
  }

  /**
   * Display field
   */
  public static function display_wooms_stock_empty_backorder() {
    $option = 'wooms_stock_empty_backorder';
    printf( '<input type="checkbox" name="%s" value="1" %s />', $option, checked( 1, get_option( $option ), false ) );
    echo '<p><small>Если включить опцию то система будет разрешать предзаказ при 0 остатках</small></p>';
  }

  /**
   * display_field_wooms_stocks_without_reserve
   */
  public static function display_field_wooms_stocks_without_reserve() {
    $option = 'wooms_stocks_without_reserve';
    printf( '<input type="checkbox" name="%s" value="1" %s />', $option, checked( 1, get_option( $option ), false ) );
    echo '<p><small>Если включить опцию то на сайте будут учитываться остатки без учета резерва</small></p>';
  }

  /**
   * Display field
   */
  public static function display_wooms_warehouse_count() {
    $option = 'wooms_warehouse_count';
    printf( '<input type="checkbox" name="%s" value="1" %s />', $option, checked( 1, get_option( $option ), false ) );
    printf( '<p><strong>Перед включением опции, убедитесь что верно настроено управление запасами в WooCommerce (на <a href="%s" target="_blank">странице настроек</a>).</strong></p>', admin_url( 'admin.php?page=wc-settings&tab=products&section=inventory' ) );
    echo "<p><small>Если включена, то будет показан остаток в количестве единиц продукта на складе. Если снять галочку - только наличие.</small></p>";
  }

  /**
   * Display field
   */
  public static function woomss_warehouses_sync_enabled_display() {
    $option = 'woomss_warehouses_sync_enabled';
    printf( '<input type="checkbox" name="%s" value="1" %s />', $option, checked( 1, get_option( $option ), false ) );
  }

  /**
   * Display field: select warehouse
   */
  public static function woomss_warehouse_id_display() {
    $option = 'woomss_warehouse_id';
    if ( empty( get_option( 'woomss_warehouses_sync_enabled' ) ) ) {
      echo '<p>Для выбора включите синхронизацию по складу</p>';

      return;
    }
    $url  = 'https://online.moysklad.ru/api/remap/1.1/entity/store';
    $data = wooms_request( $url );
    if ( empty( $data['rows'] ) ) {
      return;
    }
    $selected_wh = get_option( 'woomss_warehouse_id' );
    ?>
    <select class="wooms_select_warehouse" name="woomss_warehouse_id">
      <option value="">Выберите склад</option>
      <?php
      foreach ( $data['rows'] as $value ):
        printf( '<option value="%s" %s>%s</option>', $value['id'], selected( $value['id'], $selected_wh, false ), $value['name'] );
      endforeach;
      ?>
    </select>
    <?php
  }
}

ProductStocks::init();
