<?php

/**
 * Import variants from MoySklad
 */
class WooMS_Product_Variations {
	/**
	 * WooMS_Product_Variations constructor.
	 */
	public function __construct() {
		
		//Use hook do_action('wooms_product_update', $product_id, $value, $data);
		add_action( 'wooms_product_update', array( $this, 'load_data' ), 15, 3 );
		add_action( 'wooms_product_update', array( $this, 'load_attributes_data' ), 16, 2 );
		add_action( 'wooms_product_variant_import_row', array( $this, 'load_data_variant' ), 15, 3 );
		// Cron
		add_action( 'init', array( $this, 'add_cron_hook' ) );
		add_filter( 'cron_schedules', array( $this, 'add_schedule' ) );
		add_action( 'wooms_cron_variation_sync', array( $this, 'walker_variants_cron_starter' ) );
		//Notices
		add_action( 'wooms_before_notice_walker', array( $this, 'notice_variants_walker' ) );
		add_action( 'wooms_before_notice_errors', array( $this, 'notice_variants_errors' ) );
		add_action( 'wooms_before_notice_result', array( $this, 'notice_variants_results' ) );
		//UI and actions manually
		add_action( 'admin_init', array( $this, 'settings_init' ), 150 );
		add_action( 'woomss_tool_actions_btns', array( $this, 'ui_for_manual_start' ), 15 );
		add_action( 'woomss_tool_actions_wooms_import_variations_manual_start', array( $this, 'start_manually' ) );
		add_action( 'woomss_tool_actions_wooms_import_variations_manual_stop', array( $this, 'stop_manually' ) );
	}
	
	/**
	 * Load data and set product type variable
	 *
	 * @param $product_id
	 * @param $item
	 * @param $data
	 */
	public function load_data( $product_id, $item, $data ) {
		$product_variant = null;
		if ( empty( get_option( 'woomss_variations_sync_enabled' ) ) ) {
			return;
		}
		if ( ! empty( $item['modificationsCount'] ) ) {
			$this->set_product_as_variable( $product_id );
		}
	}
	
	/**
	 * Installation of variable product
	 *
	 * @param $product_id
	 *
	 * @return bool
	 */
	public function set_product_as_variable( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product->is_type( 'variable' ) ) {
			wp_set_post_terms( $product_id, 'variable', 'product_type' );
			
			return true;
		}
		
		return false;
	}
	
	/**
	 * Installation of attributes for variable product
	 *
	 * @param $product_id
	 * @param $item
	 */
	public function load_attributes_data( $product_id, $item ) {
		if ( empty( $item['modificationsCount'] ) ) {
			return;
		}
		$count       = apply_filters( 'wooms_variant_attributes_iteration_size', 100 );
		$args_ms_api = array(
			'filter=productid' => $item['id'],
			'limit'            => $count,
		);
		$url_api     = add_query_arg( $args_ms_api, 'https://online.moysklad.ru/api/remap/1.1/entity/variant' );
		$data        = wooms_request( $url_api );
		$this->set_product_attributes_for_variation( $product_id, $data );
	}
	
	/**
	 * Set attributes for variables
	 *
	 * @param $product_id
	 * @param $data
	 */
	public function set_product_attributes_for_variation( $product_id, $data ) {
		$ms_attributes = [];
		foreach ( $data['rows'] as $key => $row ) {
			foreach ( $row['characteristics'] as $key => $characteristic ) {
				$ms_attributes[ $characteristic['id'] ] = [
					'name'   => $characteristic["name"],
					'values' => [],
				];
			}
		}
		foreach ( $data['rows'] as $key => $row ) {
			foreach ( $row['characteristics'] as $key => $characteristic ) {
				$ms_attributes[ $characteristic['id'] ]['values'][] = $characteristic['value'];
			}
		}
		foreach ( $ms_attributes as $key => $value ) {
			$ms_attributes[ $key ]['values'] = array_unique( $value['values'] );
		}
		$attributes = [];
		foreach ( $ms_attributes as $key => $value ) {
			
			$attribute_object = new WC_Product_Attribute();
			// $attribute_object->set_id( $key );
			$attribute_object->set_name( $value['name'] );
			$attribute_object->set_options( $value['values'] );
			$attribute_object->set_position( 0 );
			$attribute_object->set_visible( 1 );
			$attribute_object->set_variation( 1 );
			$attributes[] = $attribute_object;
		}
		$product = wc_get_product( $product_id );
		$product->set_attributes( $attributes );
		$product->save();
	}
	
	/**
	 * Installation of variations for variable product
	 *
	 * @param $value
	 * @param $key
	 * @param $data
	 */
	public function load_data_variant( $value, $key, $data ) {
		
		if ( false != $value['archived'] ) {
			return;
		}
		$response   = wooms_request( $value['product']['meta']['href'] );
		$product_id = $this->get_product_id_by_uuid( $response['id'] );
		if ( empty( get_option( 'wooms_use_uuid' ) ) ) {
			if ( empty( $response['article'] ) ) {
				return;
			}
		}
		$this->update_variations_for_product( $product_id, $value );
		do_action( 'wooms_product_variations_update', $value, $key, $data );
	}
	
	/**
	 * Get product variant ID
	 *
	 * @param $uuid
	 *
	 * @return bool
	 */
	public function get_product_id_by_uuid( $uuid ) {
		
		$posts = get_posts( 'post_type=product&meta_key=wooms_id&meta_value=' . $uuid );
		if ( empty( $posts[0]->ID ) ) {
			return false;
		}
		
		return $posts[0]->ID;
	}
	
	/**
	 * Update and add variables from product
	 *
	 * @param $product_id
	 * @param $value
	 */
	public function update_variations_for_product( $product_id, $value ) {
		
		if ( empty( $value ) ) {
			return;
		}
		if ( ! $variation_id = $this->get_variation_by_wooms_id( $value['id'] ) ) {
			$variation_id = $this->add_variation( $product_id, $value );
		}
		$this->set_variation_attributes( $variation_id, $value['characteristics'] );
		$variation = wc_get_product( $variation_id );
		$variation->set_name( $value['name'] );
		if ( ! empty( $value["salePrices"][0]['value'] ) ) {
			$price = $value["salePrices"][0]['value'] / 100;
			$variation->set_price( $price );
			$variation->set_regular_price( $price );
		}
		$variation->save();
		do_action( 'wooms_variation_id', $variation_id, $value );
	}
	
	/**
	 * Get product parent ID
	 *
	 * @param $id
	 *
	 * @return bool
	 */
	public function get_variation_by_wooms_id( $id ) {
		
		$posts = get_posts( 'post_type=product_variation&meta_key=wooms_id&meta_value=' . $id );
		if ( empty( $posts ) ) {
			return false;
		}
		
		return $posts[0]->ID;
	}
	
	/**
	 * Add variables from product
	 *
	 * @param $product_id
	 * @param $value
	 *
	 * @return bool|int
	 */
	public function add_variation( $product_id, $value ) {
		
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( absint( $product_id ) );
		$variation->set_status( 'publish' );
		$variation->save();
		$variation_id = $variation->get_id();
		if ( empty( $variation_id ) ) {
			return false;
		}
		update_post_meta( $variation_id, 'wooms_id', $value['id'] );
		do_action( 'wooms_add_variation', $variation_id, $product_id, $value );
		
		return $variation_id;
	}
	
	/**
	 * Set attributes and value for variation
	 *
	 * @param $variation_id
	 * @param $characteristics
	 */
	public function set_variation_attributes( $variation_id, $characteristics ) {
		
		$attributes = [];
		foreach ( $characteristics as $key => $characteristic ) {
			$attribute_name                = sanitize_title( $characteristic['name'] );
			$attributes[ $attribute_name ] = $characteristic['value'];
		}
		$variation = wc_get_product( $variation_id );
		$variation->set_attributes( $attributes );
		$variation->save();
	}
	
	/**
	 * Start import manually
	 */
	public function start_manually() {
		delete_transient( 'wooms_variant_start_timestamp' );
		delete_transient( 'wooms_error_background' );
		delete_transient( 'wooms_variant_offset' );
		delete_transient( 'wooms_variant_end_timestamp' );
		delete_transient( 'wooms_variant_walker_stop' );
		set_transient( 'wooms_variant_manual_sync', 1 );
		$this->walker();
		wp_redirect( admin_url( 'tools.php?page=moysklad' ) );
	}
	
	/**
	 * Walker for data variant product from MoySklad
	 *
	 * @return bool|void
	 */
	public function walker() {
		
		//Check stop tag and break the walker
		if ( $this->check_stop_manual() ) {
			return false;
		}
		$count = apply_filters( 'wooms_variant_iteration_size', 20 );
		if ( ! $offset = get_transient( 'wooms_variant_offset' ) ) {
			$offset = 0;
			set_transient( 'wooms_variant_offset', $offset );
			update_option( 'wooms_variant_session_id', date( "YmdHis" ), 'no' );
			delete_transient( 'wooms_count_variant_stat' );
		}
		$args_ms_api = array(
			'offset' => $offset,
			'limit'  => $count,
		);
		$url_api     = add_query_arg( $args_ms_api, 'https://online.moysklad.ru/api/remap/1.1/entity/variant' );
		try {
			
			delete_transient( 'wooms_variant_end_timestamp' );
			set_transient( 'wooms_variant_start_timestamp', time() );
			$data = wooms_request( $url_api );
			//Check for errors and send message to UI
			if ( isset( $data['errors'] ) ) {
				$error_code = $data['errors'][0]["code"];
				if ( $error_code == 1056 ) {
					$msg = sprintf( 'Ошибка проверки имени и пароля. Код %s, исправьте в <a href="%s">настройках</a>', $error_code, admin_url( 'options-general.php?page=mss-settings' ) );
					throw new Exception( $msg );
				} else {
					throw new Exception( $error_code . ': ' . $data['errors'][0]["error"] );
				}
			}
			//If no rows, that send 'end' and stop walker
			if ( empty( $data['rows'] ) ) {
				$this->walker_finish();
				
				return false;
			}
			$i = 0;
			foreach ( $data['rows'] as $key => $value ) {
				do_action( 'wooms_product_variant_import_row', $value, $key, $data );
				$i ++;
			}
			if ( $count_saved = get_transient( 'wooms_count_variant_stat' ) ) {
				set_transient( 'wooms_count_variant_stat', $i + $count_saved );
			} else {
				set_transient( 'wooms_count_variant_stat', $i );
			}
			set_transient( 'wooms_variant_offset', $offset + $i );
			
			return;
		} catch ( Exception $e ) {
			delete_transient( 'wooms_variant_start_timestamp' );
			set_transient( 'wooms_error_background', $e->getMessage() );
		}
	}
	
	/**
	 * Check for stopping imports from MoySklad
	 *
	 * @return bool
	 */
	public function check_stop_manual() {
		if ( get_transient( 'wooms_variant_walker_stop' ) ) {
			delete_transient( 'wooms_variant_start_timestamp' );
			delete_transient( 'wooms_variant_offset' );
			delete_transient( 'wooms_variant_walker_stop' );
			
			return true;
		} else {
			return false;
		}
	}
	
	/**
	 * Stopping walker imports from MoySklad
	 *
	 * @return bool
	 */
	public function walker_finish() {
		delete_transient( 'wooms_variant_start_timestamp' );
		delete_transient( 'wooms_variant_offset' );
		delete_transient( 'wooms_variant_manual_sync' );
		//Отключаем обработчик или ставим на паузу
		if ( empty( get_option( 'woomss_walker_cron_enabled' ) ) ) {
			$timer = 0;
		} else {
			$timer = 60 * 60 * intval( get_option( 'woomss_walker_cron_timer', 24 ) );
		}
		set_transient( 'wooms_variant_end_timestamp', date( "Y-m-d H:i:s" ), $timer );
		
		return true;
	}
	
	public function remove_variations_for_product( $product_id ) {
		//todo make remove variation for product
		return true;
	}
	
	/**
	 * Stop import manually
	 */
	public function stop_manually() {
		set_transient( 'wooms_variant_walker_stop', 1, 60 * 60 );
		delete_transient( 'wooms_variant_start_timestamp' );
		delete_transient( 'wooms_variant_offset' );
		delete_transient( 'wooms_variant_end_timestamp' );
		delete_transient( 'wooms_variant_manual_sync' );
		wp_redirect( admin_url( 'tools.php?page=moysklad' ) );
	}
	
	/**
	 * Add cron pramametrs
	 *
	 * @param $schedules
	 *
	 * @return mixed
	 */
	public function add_schedule( $schedules ) {
		
		$schedules['wooms_cron_worker_variations'] = array(
			'interval' => 60,
			'display'  => 'WooMS Cron Load Variations 60 sec',
		);
		
		return $schedules;
	}
	
	/**
	 *Init Cron
	 */
	public function add_cron_hook() {
		if ( empty( get_option( 'woomss_variations_sync_enabled' ) ) ) {
			return;
		}
		if ( ! wp_next_scheduled( 'wooms_cron_variation_sync' ) ) {
			wp_schedule_event( time(), 'wooms_cron_worker_variations', 'wooms_cron_variation_sync' );
		}
	}
	
	/**
	 * Starting walker from cron
	 */
	public function walker_variants_cron_starter() {
		
		if ( $this->can_cron_start() ) {
			$this->walker();
		}
	}
	
	/**
	 * Can cron start
	 *
	 * @return bool
	 */
	public function can_cron_start() {
		if ( ! empty( get_transient( 'wooms_variant_manual_sync' ) ) ) {
			return true;
		}
		if ( empty( get_option( 'woomss_walker_cron_enabled' ) ) ) {
			return false;
		}
		if ( empty( get_option( 'woomss_variations_sync_enabled' ) ) ) {
			return false;
		}
		if ( $end_stamp = get_transient( 'wooms_variant_end_timestamp' ) ) {
			
			$interval_hours = get_option( 'woomss_walker_cron_timer' );
			$interval_hours = (int) $interval_hours;
			if ( empty( $interval_hours ) ) {
				return false;
			}
			$now        = new DateTime();
			$end_stamp  = new DateTime( $end_stamp );
			$end_stamp  = $now->diff( $end_stamp );
			$diff_hours = $end_stamp->format( '%h' );
			if ( $diff_hours > $interval_hours ) {
				return true;
			} else {
				return false;
			}
		} else {
			return true;
		}
	}
	
	/**
	 * Notice walker
	 */
	public function notice_variants_walker() {
		$screen = get_current_screen();
		if ( $screen->base != 'tools_page_moysklad' ) {
			return;
		}
		if ( empty( get_transient( 'wooms_variant_start_timestamp' ) ) ) {
			return;
		}
		$time_string = get_transient( 'wooms_variant_start_timestamp' );
		$diff_sec    = time() - $time_string;
		$time_string = date( 'Y-m-d H:i:s', $time_string );
		?>
		<div class="wrap">
			<?php
			if ( false != $this->check_availability_of_variations() ) {
				?>
				<div id="message" class="updated notice">
					<p><strong>Сейчас выполняется пакетная обработка данных в фоне.</strong></p>
					<p>Отметка времени о последней итерации: <?php echo $time_string ?></p>
					<p>Количество обработанных вариаций: <?php echo get_transient( 'wooms_count_variant_stat' ); ?></p>
					<p>Количество обработанных
						атрибутов: <?php echo get_transient( 'wooms_count_variant_attr_stat' ); ?></p>
					<p>Секунд прошло: <?php echo $diff_sec ?>.<br/> Следующая серия данных должна отправиться примерно
						через минуту. Можно обновить страницу для проверки результатов работы.</p>
				
				</div>
				<?php
			} else {
				?>
				<div id="message" class="updated error is-dismissible">
					<p><strong>Не проведена синхронизация основных товаров</strong></p>
					<p>Синхронизацию вариативных товаров необходимо поводить <strong>после</strong> общей синхронизации
						товаров</p>
				</div>
				<?php
				$this->stop_manually_to_check();
			}
			?>
		
		</div>
		<?php
	}
	
	/**
	 * Checking for variable product
	 *
	 * @return bool
	 */
	public function check_availability_of_variations() {
		$variants = wc_get_products( array(
			'type' => 'variable',
		) );
		if ( empty( $variants ) ) {
			return false;
		}
		
		return true;
	}
	
	/**
	 * Stopping the import of variational goods during verification
	 */
	public function stop_manually_to_check() {
		set_transient( 'wooms_variant_walker_stop', 1, 60 * 60 );
		delete_transient( 'wooms_variant_start_timestamp' );
		delete_transient( 'wooms_variant_offset' );
		delete_transient( 'wooms_variant_end_timestamp' );
		delete_transient( 'wooms_variant_manual_sync' );
	}
	
	/**
	 * Notice about results
	 */
	public function notice_variants_results() {
		
		$screen = get_current_screen();
		if ( $screen->base != 'tools_page_moysklad' ) {
			return;
		}
		if ( empty( get_transient( 'wooms_variant_end_timestamp' ) ) ) {
			return;
		}
		if ( ! empty( get_transient( 'wooms_variant_start_timestamp' ) ) ) {
			return;
		}
		?>
		<div class="wrap">
			<div id="message" class="updated notice">
				<p><strong>Успешно завершился импорт вариативных товаров из МойСклад</strong></p>
				<?php
				printf( '<p>Номер текущей сессии: %s</p>', get_option( 'wooms_session_id' ) );
				printf( '<p>Время успешного завершения последней загрузки: %s</p>', get_transient( 'wooms_end_timestamp' ) );
				printf( '<p>Количество обработанных вариаций в последней итерации: %s</p>', get_transient( 'wooms_count_variant_stat' ) );
				printf( '<p>Количество операций: %s</p>', get_transient( 'wooms_count_variant_stat' ) );
				?>
			</div>
		</div>
		<?php
	}
	
	/**
	 * Notice about errors
	 */
	public function notice_variants_errors() {
		$screen = get_current_screen();
		if ( $screen->base != 'tools_page_moysklad' ) {
			return;
		}
		if ( empty( get_transient( 'wooms_error_background' ) ) ) {
			return;
		}
		?>
		<div class="wrap">
			<div class="error">
				<p><strong>Обработка заверишлась с ошибкой.</strong></p>
				<p>Данные: <?php echo get_transient( 'wooms_error_background' ) ?></p>
			</div>
		</div>
		<?php
	}
	
	/**
	 * Manual start variations
	 */
	public function ui_for_manual_start() {
		if ( empty( get_option( 'woomss_variations_sync_enabled' ) ) ) {
			return;
		}
		?><?php
		echo '<h2>Синхронизация вариативных товаров</h2>';
		if ( empty( get_transient( 'wooms_variant_start_timestamp' ) ) ) {
			echo "<p>Нажмите на кнопку ниже, чтобы запустить синхронизацию данных о вариативных товарах вручную</p>";
			echo "<p><strong>Внимание!</strong> Синхронизацию вариативных товаров необходимо поводить <strong>после</strong> общей синхронизации товаров</p>";
			printf( '<a href="%s" class="button button-primary">Старт импорта вариаций</a>', add_query_arg( 'a', 'wooms_import_variations_manual_start', admin_url( 'tools.php?page=moysklad' ) ) );
		} else {
			printf( '<a href="%s" class="button button-secondary">Остановить импорт вариаций</a>', add_query_arg( 'a', 'wooms_import_variations_manual_stop', admin_url( 'tools.php?page=moysklad' ) ) );
		}
	}
	
	
	/**
	 * Settings import variations
	 */
	public function settings_init() {
		register_setting( 'mss-settings', 'woomss_variations_sync_enabled' );
		add_settings_field( $id = 'woomss_variations_sync_enabled', $title = 'Включить синхронизацию вариаций', $callback = [
			$this,
			'woomss_variations_sync_enabled_display',
		], $page = 'mss-settings', $section = 'woomss_section_other' );
	}
	
	/**
	 * Option import variations
	 */
	public function woomss_variations_sync_enabled_display() {
		$option = 'woomss_variations_sync_enabled';
		printf( '<input type="checkbox" name="%s" value="1" %s />', $option, checked( 1, get_option( $option ), false ) );
		?>
		<p><strong>Тестовый режим. Не включайте эту функцию на реальном сайте, пока не проверите ее на тестовой копии
				сайта.</strong></p>
		<?php
	}
}

new WooMS_Product_Variations;
