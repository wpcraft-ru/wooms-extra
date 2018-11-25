<?php

/**
 * Import Product Categories from MoySklad
 */
class WooMS_Import_Product_Choice_Categories {
	
	public function __construct() {
		
		add_action( 'admin_init', array( $this, 'settings_init' ), 110 );
		
		if ( empty( get_option( 'woomss_include_categories_sync' ) ) ) {
			
			return;
		}
		
		add_filter( 'wooms_variant_ms_api_url', array( $this, 'change_ms_api_url_variant' ), 10 );
		add_filter( 'wooms_product_ms_api_url', array( $this, 'change_ms_api_url_simple' ), 10 );
		
		add_action( 'wooms_walker_finish', array( $this, 'update_category' ) );
		
		add_action( 'wooms_update_category', array( $this, 'update_meta_session_term' ) );
		
	}
	
	/**
	 * Replace the link for import from the selected category for simple products
	 *
	 * @param $url
	 *
	 * @return string
	 */
	public function change_ms_api_url_simple( $url ) {
		
		if ( empty( $this->select_category() ) ) {
			return;
		}
		
		$arg = array(
			'scope'  => 'product',
			'filter' => 'productFolder=' . $this->select_category(),
		);
		
		$url = add_query_arg( $arg, 'https://online.moysklad.ru/api/remap/1.1/entity/assortment' );
		
		return $url;
	}
	
	/**
	 * Wrapper to get the option value
	 *
	 * @return mixed
	 */
	public function select_category() {
		
		return get_option( 'woomss_include_categories_sync' );
	}
	
	/**
	 * Replace the link for import from the selected category for variation products
	 *
	 * @param $url
	 *
	 * @return string|void
	 */
	public function change_ms_api_url_variant( $url ) {
		
		if ( empty( $this->select_category() ) ) {
			return;
		}
		
		$arg = array(
			'scope'  => 'variant',
			'filter' => 'productFolder=' . $this->select_category(),
		);
		
		$url = add_query_arg( $arg, 'https://online.moysklad.ru/api/remap/1.1/entity/assortment' );
		
		return $url;
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
	public function skip_update_category() {
		
		return false;
	}
	
	
	/**
	 * We write the time stamp of the session in the meta of the term
	 *
	 * @param $term_id
	 */
	public function update_meta_session_term( $term_id ) {
		
		if ( $session_id = get_option( 'wooms_session_id' ) ) {
			update_term_meta( $term_id, 'wooms_session_id', $session_id );
		}
	}
	
	/**
	 * Delete the parent category
	 *
	 * @since 1.8.6
	 * @version 1.8.8
	 * @return bool|void
	 */
	public function update_category() {
		
		if ( empty( $this->select_category() ) ) {
			return;
		}
		
		$session_id = get_option( 'wooms_session_id' );
		if ( empty( $session_id ) ) {
			return;
		}
		
		$term_select = wooms_request( $this->select_category() );
		
		$arg = array(
			'taxonomy'     => array( 'product_cat' ),
			'hierarchical' => false,
			'meta_query'   => array(
				array(
					'key'   => 'wooms_id',
					'value' => $term_select['id'],
				),
			),
		);
		
		if ( ! isset( $term_select['productFolder']['meta']['href'] ) ) {
			$arg_parent = array(
				'hide_empty' => 0,
				'parent'     => 0,
			);
			$arg        = array_merge( $arg, $arg_parent );
		}
		
		$term = get_terms( $arg );
		
		if ( false != $term ) {
			
			if ( 0 == $term[0]->parent ) {
				$term_children = get_terms( array(
					'taxonomy' => array( 'product_cat' ),
					'parent'   => $term[0]->term_id,
					'fields'   => 'ids',
				) );
			} else {
				$term_children = get_term_children( $term[0]->term_id, 'product_cat' );
			}
			
			if ( 0 == $term[0]->parent && ! empty( $term_children ) ) {
				
				$this->update_term_children( $term_children, array( 'parent' => 0 ) );
				wp_update_term_count( $term[0]->term_id, 'product_cat' );
				
			}
			
			if ( 0 != $term[0]->parent && ! empty( $term_children ) ) {
				
				$this->update_term_children( $term_children, array( 'parent' => 0 ) );
				$this->update_term_children( $term[0]->parent, array( 'count' => 0 ) );
				wp_update_term_count( $term[0]->parent, 'product_cat' );
				$this->delete_relationship( $term[0] );
				
			}
			
			if ( 0 != $term[0]->parent && empty( $term_children ) ) {
				
				apply_filters( 'wooms_skip_update_select_category', array( $this, 'skip_update_category' ) );
				$this->delete_relationship( $term[0] );
				
			}
			if ( 0 == $term[0]->parent && empty( $term_children ) ) {

				$this->delete_relationship( $term[0] );
				
			}
		}
	}
	
	/**
	 * Update children terms
	 *
	 * @since 1.8.7
	 *
	 * @param $term_children
	 */
	public function update_term_children( $terms_id, $arg = array() ) {
		
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
	public function delete_relationship( $term ) {
		
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
	 * Settings UI
	 */
	public function settings_init() {
		
		if ( get_option( 'woomss_categories_sync_enabled' ) ) {
			return;
		}
		
		register_setting( 'mss-settings', 'woomss_include_categories_sync' );
		add_settings_field( 'woomss_include_categories_sync', 'Выбрать группу', array(
			$this,
			'display_woomss_include_categories_sync',
		), 'mss-settings', 'woomss_section_other' );
	}
	
	public function display_woomss_include_categories_sync() {
		
		$checked_choice   = get_option( 'woomss_include_categories_sync' );
		$request_category = $this->setting_request_category();
		
		if ( $request_category && is_array( $request_category ) ) {
			
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
				printf( '<option value="%s" %s>%s</option>', esc_attr( $value['meta']['href'] ), selected( $checked_choice, $value['meta']['href'], false ), $path_name_margin .
				                                                                                                                                             $value['name'] );
				
			}
			echo '</select>';
			
		} else {
			echo '<p><small>Сервер не отвечает. Требуется подождать. Обновить страницу через некоторое время</small></p>';
		}
		
		?>
		<p>
			<small>После включения опции, старые товары будут помечаться как отсутствующие. Чтобы они пропали с сайта нужно убедиться, что:</small>
		</p>
		<ul style="margin-left: 18px;">
			<li>
				<small>&mdash;&nbsp;включена опция <a href="admin.php?page=wc-settings" target="_blank">управления запасами</a></small>
			</li>
			<li>
				<small>&mdash;&nbsp;стоит опция сокрытия отсутствующих товаров</small>
			</li>
			<li>
				<small>&mdash;&nbsp;в виджете категорий стоит опция скрывать пустые категории</small>
			</li>
		</ul>
		<p>
			<small>Также для ускорения, рекоендуется выполнить команду пересчета счетчиков в разделе Статуса по адресу <a href="admin.php?page=wc-status&tab=tools" target="_blank">WooCommerce
					-> Статусы -> Инструменты</a>.
			</small>
		</p>
		<?php
		
	}
	
	/**
	 * Requests category to settings
	 *
	 * @since 1.8.6
	 *
	 * @return bool
	 */
	public function setting_request_category() {
		
		$offset      = 0;
		$limit       = 100;
		$ms_api_args = apply_filters( 'wooms_product_ms_api_arg_category', array(
			'offset' => $offset,
			'limit'  => $limit,
		) );
		$url         = apply_filters( 'wooms_product_ms_api_url_category', 'https://online.moysklad.ru/api/remap/1.1/entity/productfolder' );
		$url_api     = add_query_arg( $ms_api_args, $url );
		
		if ( ! $data = get_transient( 'wooms_settings_categories' ) ) {
			$data = wooms_request( $url_api );
			set_transient( 'wooms_settings_categories', $data, 60 * 60 * 12 );
		}
		
		if ( empty( $data['rows'] ) ) {
			return false;
		}
		
		return $data['rows'];
	}
}

new WooMS_Import_Product_Choice_Categories;