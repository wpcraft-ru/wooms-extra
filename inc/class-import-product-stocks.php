<?php

/**
 * Synchronization the stock of goods from MoySklad
 */
class WooMS_Warehouses {
	
	/**
	 * WooMS_Warehouses constructor.
	 */
	public function __construct() {
		//Use hook do_action('wooms_product_update', $product_id, $value, $data);
		add_action( 'wooms_product_update', array( $this, 'load_data' ), 10, 2 );
		//Use hook do_action('wooms_variation_id', $variation_id, $value);
		add_action( 'wooms_variation_id', array( $this, 'load_data' ), 10, 2 );
		//Settings
		add_action( 'admin_init', array( $this, 'settings_init' ), 100 );
	}
	
	
	/**
	 * Receive the balance of the warehouse and upload it to the goods
	 *
	 * @param $product_id
	 * @param $value
	 *
	 * @return bool
	 */
	public function load_data( $product_id, $value ) {
		// получать остаток по складу и загружать в товары
		if ( empty( get_option( 'woomss_stock_sync_enabled' ) ) ) {
			return false;
		}
		$url = "https://online.moysklad.ru/api/remap/1.1/report/stock/all";
		if ( get_option( 'woomss_warehouses_sync_enabled' ) && $warehouse_id = get_option( 'woomss_warehouse_id' ) ) {
			$url = add_query_arg( array( 'store.id' => $warehouse_id, 'product.id' => $value['id'] ), $url );
		} else {
			$url = add_query_arg( 'product.id', $value['id'], $url );
		}
		$data = wooms_request( $url );
		$product = wc_get_product( $product_id );
		if ( empty( $data['rows'][0]['stock'] ) ) {
			
			$stock = 0;
		} else {
			$stock = (int) $data['rows'][0]['stock'];
		}
		if ( get_option( 'wooms_stock_empty_backorder' ) ) {
			$product->set_backorders( 'yes' );
		} else {
			$product->set_backorders( 'no' );
		}
		if ( empty( get_option( 'wooms_warehouse_count' ) ) ) {
			$product->set_manage_stock( 'no' );
		} else {
			$product->set_manage_stock( 'yes' );
		}
		if ( $stock <= 0 ) {
			$product->set_stock_quantity( 0 );
			$product->set_stock_status( 'outofstock' );
		} else {
			$product->set_stock_quantity( $stock );
			$product->set_stock_status( 'instock' );
		}
		$product->save();
		
		return true;
	}
	
	
	/**
	 * Settings UI
	 */
	public function settings_init() {
		
		add_settings_section( 'woomss_section_warehouses', 'Склад и остатки', $callback = array(
			$this,
			'display_woomss_section_warehouses',
		), 'mss-settings' );
		register_setting( 'mss-settings', 'woomss_stock_sync_enabled' );
		add_settings_field( $id = 'woomss_stock_sync_enabled', $title = 'Включить синхронизацию остатков', $callback = array(
			$this,
			'woomss_stock_sync_enabled_display',
		), $page = 'mss-settings', $section = 'woomss_section_warehouses' );
		register_setting( 'mss-settings', 'wooms_warehouse_count' );
		add_settings_field( $id = 'wooms_warehouse_count', $title = 'Управление запасами', $callback = array(
			$this,
			'display_wooms_warehouse_count',
		), $page = 'mss-settings', $section = 'woomss_section_warehouses' );
		register_setting( 'mss-settings', 'wooms_stock_empty_backorder' );
		add_settings_field( $id = 'wooms_stock_empty_backorder', $title = 'Разрешать предазказ при 0 остатке', $callback = array(
			$this,
			'display_wooms_stock_empty_backorder',
		), $page = 'mss-settings', $section = 'woomss_section_warehouses' );
		register_setting( 'mss-settings', 'woomss_warehouses_sync_enabled' );
		add_settings_field( $id = 'woomss_warehouses_sync_enabled', $title = 'Включить синхронизацию по складу', $callback = array(
			$this,
			'woomss_warehouses_sync_enabled_display',
		), $page = 'mss-settings', $section = 'woomss_section_warehouses' );
		register_setting( 'mss-settings', 'woomss_warehouse_id' );
		add_settings_field( $id = 'woomss_warehouse_id', $title = 'Выбрать склад для сайта', $callback = array(
			$this,
			'woomss_warehouse_id_display',
		), $page = 'mss-settings', $section = 'woomss_section_warehouses' );
	}
	
	/**
	 *
	 */
	public function display_woomss_section_warehouses() {
		?>
		<p>Данные опции позволяют настроить обмен данным по остаткам между складом и сайтом.</p>
		<ol>
			<li>Функционал обязательно нужно проверять на тестовом сайте. Он еще проходит обкатку. В случае проблем
				сообщайте в техподдержку
			</li>
			<li>После изменения этих опций, следует обязательно <a
					href="<?php echo admin_url( 'tools.php?page=moysklad' ) ?>" target="_blank">запускать обмен данными
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
	public function woomss_stock_sync_enabled_display() {
		$option = 'woomss_stock_sync_enabled';
		printf( '<input type="checkbox" name="%s" value="1" %s />', $option, checked( 1, get_option( $option ), false ) );
	}
	
	/**
	 * Display field
	 */
	public function display_wooms_stock_empty_backorder() {
		$option = 'wooms_stock_empty_backorder';
		printf( '<input type="checkbox" name="%s" value="1" %s />', $option, checked( 1, get_option( $option ), false ) );
		echo '<p><small>Если включить опцию то система будет разрешать предзаказ при 0 остатках</small></p>';
	}
	
	/**
	 * Display field
	 */
	public function display_wooms_warehouse_count() {
		$option = 'wooms_warehouse_count';
		printf( '<input type="checkbox" name="%s" value="1" %s />', $option, checked( 1, get_option( $option ), false ) );
		printf( '<p><strong>Перед включением опции, убедитесь что верно настроено управление запасами в WooCommerce (на <a href="%s" target="_blank">странице настроек</a>).</strong></p>', admin_url( 'admin.php?page=wc-settings&tab=products&section=inventory' ) );
		echo "<p><small>Если включена, то будет показан остаток в количестве единиц продукта на складе. Если снять галочку - только наличие.</small></p>";
	}
	
	/**
	 * Display field
	 */
	public function woomss_warehouses_sync_enabled_display() {
		$option = 'woomss_warehouses_sync_enabled';
		printf( '<input type="checkbox" name="%s" value="1" %s />', $option, checked( 1, get_option( $option ), false ) );
	}
	
	/**
	 * Display field: select warehouse
	 */
	public function woomss_warehouse_id_display() {
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

new WooMS_Warehouses;