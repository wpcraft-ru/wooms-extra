<?php

/**
 * Import Product Categories from MoySklad
 */
class WooMS_Import_Product_Choice_Categories {
	
	public function __construct() {
		add_action( 'admin_init', array( $this, 'settings_init' ), 102 );
		
		if ( empty(get_option( 'woomss_include_categories_sync' ) )) {
			return;
		}
		
		add_action( 'wooms_product_update', array( $this, 'load_data' ), 1, 3 );
		
		add_filter( 'wooms_variant_ms_api_arg', array( $this, 'add_ms_api_arg_variant' ), 10 );
		add_filter( 'wooms_variant_ms_api_url', array( $this, 'change_ms_api_url' ), 10 );
		add_filter( 'wooms_product_ms_api_arg', array( $this, 'add_ms_api_arg_simple' ), 10 );
		add_filter( 'wooms_product_ms_api_url', array( $this, 'change_ms_api_url' ), 10 );
		
	}
	
	public function add_ms_api_filter_arg() {
		if ( $this->select_category() ) {
			$filter = 'productFolder=' . $this->select_category() ;
		}
		
		return $filter;
	}
	
	public function add_ms_api_arg_simple( $arg ) {

		if ( $this->select_category() ) {
			$arg['scope']  = 'product';
			$arg['filter'] = $this->add_ms_api_filter_arg();
		}
		
		return $arg;
	}
	public function add_ms_api_arg_variant( $arg ) {

		if ( $this->select_category() ) {
			$arg['scope']  = 'variant';
			$arg['filter'] = $this->add_ms_api_filter_arg();
		}
		return $arg;
	}
	public function select_category() {
		return get_option( 'woomss_include_categories_sync' );
	}
	
	public function change_ms_api_url( $url ) {

		if ( $this->select_category() ) {
			$url = 'https://online.moysklad.ru/api/remap/1.1/entity/assortment';
		}
		
		return $url;
	}
	
	public function load_data( $product_id, $value, $data ) {
		
		if ( get_option( 'woomss_categories_sync_enabled' ) ) {
			return;
		}
		if ( empty(get_option( 'woomss_include_categories_sync' ) )) {
			return;
		}
		
		$checked_choice_include = get_option( 'woomss_include_categories_sync' );
		$url                    = $value['productFolder']['meta']['href'];
		if ( in_array( $url, $checked_choice_include ) ) {
			if ( $term_id = $this->update_category( $url ) ) {
				
				wp_set_object_terms( $product_id, $term_id, $taxonomy = 'product_cat' );
			}
		}
	}
	
	public function update_category( $url ) {
		$data = wooms_request( $url );
		
		if ( $term_id = $this->check_term_by_ms_id( $data['id'] ) ) {
			
			return $term_id;
		} else {
			
			$args = array();
			
			$term_new = [
				'wooms_id' => $data['id'],
				'name'     => $data['name'],
				'archived' => $data['archived'],
			];
			
			if ( isset( $data['productFolder']['meta']['href'] ) ) {
				$url_parent = $data['productFolder']['meta']['href'];
				if ( $term_id_parent = $this->update_category( $url_parent ) ) {
					$args['parent'] = intval( $term_id_parent );
				}
			}
			
			$term = wp_insert_term( $term_new['name'], $taxonomy = 'product_cat', $args );
			
			if ( isset( $term->errors["term_exists"] ) ) {
				$term_id = intval( $term->error_data['term_exists'] );
				if ( empty( $term_id ) ) {
					return false;
				}
			} elseif ( isset( $term->term_id ) ) {
				$term_id = $term->term_id;
			} elseif ( isset( $term["term_id"] ) ) {
				$term_id = $term["term_id"];
			} else {
				return false;
			}
			
			update_term_meta( $term_id, 'wooms_id', $term_new['wooms_id'] );
			
			return $term_id;
		}
		
	}
	
	/**
	 * If isset term return term_id, else return false
	 */
	public function check_term_by_ms_id( $id ) {
		
		$terms = get_terms( 'taxonomy=product_cat&meta_key=wooms_id&meta_value=' . $id );
		
		if ( empty( $terms ) ) {
			return false;
		}
		
		return $terms[0]->term_id;
		
	}
	
	/**
	 * Settings UI
	 */
	public function settings_init() {
		register_setting( 'mss-settings', 'woomss_include_categories_sync' );
		add_settings_field( 'woomss_include_categories_sync', 'Выбрать группу', array(
			$this,
			'display_woomss_include_categories_sync',
		), 'mss-settings', 'woomss_section_other' );
	}
	
	
	//Display field
	public function display_woomss_include_categories_sync() {
		$option         = 'woomss_include_categories_sync';
		$checked_choice = get_option( $option);
		$offset= 0;
		$limit = 100;
		$ms_api_args = apply_filters( 'wooms_product_ms_api_arg_category', array(
			'offset' => $offset,
			'limit'  => $limit,
		) );
		$url  = apply_filters( 'wooms_product_ms_api_url_category', 'https://online.moysklad.ru/api/remap/1.1/entity/productfolder' );
		$url_api     = add_query_arg( $ms_api_args, $url );

		$data = wooms_request( $url_api );
		if ( empty( $data['rows'] ) ) {
			return;
		}
		?>

		<select class="woomss_include_categories_sync" name="woomss_include_categories_sync">
			<option value="">Выберите группу</option>
			<?php
			foreach ( $data['rows'] as $value ):
				if (empty($value['pathName'])) :
				printf( '<option value="%s" %s>%s</option>',
					esc_attr( $value['meta']['href'] ),
					selected( $checked_choice, $value['meta']['href'], false ),
					$value['name'] );
				endif;
			endforeach;
			?>
		</select>
		
		<?php
	}
}

new WooMS_Import_Product_Choice_Categories;
