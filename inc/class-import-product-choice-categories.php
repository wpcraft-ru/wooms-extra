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
		
		add_action( 'wooms_walker_finish', array( $this, 'remove_parent_category' ), 20 );
		add_action( 'wooms_update_category', array( $this, 'update_meta_session_term' ) );
		
	}
	
	/**
	 * Replace the link for import from the selected category for simple products
	 *
	 * @param $url
	 *
	 * @return string|void
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
	 * Skipping the parent category by sync time
	 *
	 * @param $bool
	 * @param $url
	 * @param $path_name
	 *
	 * @return bool
	 */
	public function skip_base_parent_category( $bool, $url, $path_name ) {
		if ( $url !== $this->select_category() && empty( $path_name ) ) {
			$bool = false;
		}
		
		return $bool;
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
	 * @return bool|void
	 */
	public function remove_parent_category() {
		if ( empty( $this->select_category() ) ) {
			return;
		}
		
		$session_id = get_option( 'wooms_session_id' );
		if ( empty( $session_id ) ) {
			return false;
		}
		
		$term_select = wooms_request( $this->select_category() );
		
		$term = get_terms( array(
			'taxonomy'     => array( 'product_cat' ),
			'hide_empty'   => 0,
			'parent'       => 0,
			'hierarchical' => false,
			'meta_query'   => array(
				/*array(
					'key'     => 'wooms_session_id',
					'value'   => $session_id,
					'compare' => '!=',
				),*/
				array(
					'key'   => 'wooms_id',
					'value' => $term_select['id'],
				),
			),
		) );
		
		$this->update_subcategory_meta( $term[0]->term_id );
		
		$term_remove = wp_delete_term( $term[0]->term_id, 'product_cat', array( 'force_default' => true ) );
		
	}
	
	
	/**
	 * Adding parent category values to the metadata of child categories
	 *
	 * @param null $term_id
	 */
	public function update_subcategory_meta( $term_id = null ) {
		$terms_sub = get_terms( array(
			'taxonomy' => array( 'product_cat' ),
			'parent'   => $term_id,
		) );
		
		if ( false != $terms_sub ) {
			foreach ( $terms_sub as $term_sub ) {
				$parent          = get_term( $term_sub->parent );
				$parent_wooms_id = get_term_meta( $term_sub->parent, 'wooms_id', true );
				update_term_meta( $term_sub->term_id, 'wooms_slug_parent', $parent->slug );
				update_term_meta( $term_sub->term_id, 'wooms_name_parent', $parent->name );
				update_term_meta( $term_sub->term_id, 'wooms_wooms_id_parent', $parent_wooms_id );
				if ( $session_id = get_option( 'wooms_session_id' ) ) {
					update_term_meta( $term_sub->term_id, 'wooms_session_id', $session_id );
				}
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
	
	
	//Display field
	public function display_woomss_include_categories_sync() {
		$option         = 'woomss_include_categories_sync';
		$checked_choice = get_option( $option );
		$offset         = 0;
		$limit          = 100;
		$ms_api_args    = apply_filters( 'wooms_product_ms_api_arg_category', array(
			'offset' => $offset,
			'limit'  => $limit,
		) );
		$url            = apply_filters( 'wooms_product_ms_api_url_category', 'https://online.moysklad.ru/api/remap/1.1/entity/productfolder' );
		$url_api        = add_query_arg( $ms_api_args, $url );
		
		$data = wooms_request( $url_api );
		if ( empty( $data['rows'] ) ) {
			return;
		}
		?>
		
		<select class="woomss_include_categories_sync" name="woomss_include_categories_sync">
			<option value="">Выберите группу</option>
			<?php
			foreach ( $data['rows'] as $value ):
				if ( empty( $value['pathName'] ) ) :
					printf( '<option value="%s" %s>%s</option>', esc_attr( $value['meta']['href'] ), selected( $checked_choice, $value['meta']['href'], false ), $value['name'] );
				endif;
			endforeach;
			?>
		</select>
		<p><small>После включения опции, старые товары будут помечаться как отсутствующие. Чтобы они пропали с сайта нужно убедиться, что:</small></p>
		<ul style="margin-left: 18px;">
			<li><small>&mdash;&nbsp;включена опция <a href="admin.php?page=wc-settings" target="_blank">управления запасами</a></small></li>
			<li><small>&mdash;&nbsp;стоит опция сокрытия отсутствующих товаров</small></li>
			<li><small>&mdash;&nbsp;в виджете категорий стоит опция скрывать пустые категории</small></li>
		</ul>
		<p><small>Также для ускорения, рекоендуется выполнить команду пересчета счетчиков в разделе Статуса по адресу <a href="admin.php?page=wc-status&tab=tools" target="_blank">WooCommerce -> Статусы -> Инструменты</a>.</small></p>
		<?php
	}
}

new WooMS_Import_Product_Choice_Categories;