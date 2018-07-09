<?php

/**
 * Import Product Categories from MoySklad
 */
class WooMS_Import_Product_Choice_Categories {
	
	public function __construct() {
		add_action( 'wooms_product_update', array( $this, 'load_data' ), 1, 3 );
		add_action( 'admin_init', array( $this, 'settings_init' ), 102 );
		add_filter( 'wooms_variant_ms_api_arg', array( $this, 'add_ms_api_arg_variant' ), 10 );
		add_filter( 'wooms_variant_ms_api_url', array( $this, 'change_ms_api_url' ), 10 );
		add_filter( 'wooms_product_ms_api_arg', array( $this, 'add_ms_api_arg_simple' ), 10 );
		add_filter( 'wooms_product_ms_api_url', array( $this, 'change_ms_api_url' ), 10 );
		
	}
	
	public function add_ms_api_filter_arg() {
		if ( $this->include_categories() ) {
			
			if ( 1 > count( $this->include_categories() ) ) {
				$filter = implode( '&filter=productFolder=', $this->include_categories() );
			} else {
				$filter = 'productFolder=' . implode( '&filter=productFolder=', $this->include_categories() );
			}
			
		}
		
		return $filter;
	}
	
	public function add_ms_api_arg_simple( $arg ) {
		if ( $this->include_categories() ) {
			$arg['scope']  = 'product';
			$arg['filter'] = $this->add_ms_api_filter_arg();
		}
		
		return $arg;
	}
	public function add_ms_api_arg_variant( $arg ) {
		if ( $this->include_categories() ) {
			$arg['scope']  = 'variant';
			$arg['filter'] = $this->add_ms_api_filter_arg();
		}
		return $arg;
	}
	public function include_categories() {
		return get_option( 'woomss_include_categories_sync' );
	}
	
	public function change_ms_api_url( $url ) {
		if ( $this->include_categories() ) {
			$url = 'https://online.moysklad.ru/api/remap/1.1/entity/assortment';
		}
		
		return $url;
	}
	
	public function load_data( $product_id, $value, $data ) {
		
		/*		if ( get_option( 'woomss_categories_sync_enabled' ) ) {
					return;
				}
				if ( empty( get_option( 'woomss_include_categories_sync' ) ) ) {
					return;
				}
				if ( empty( get_option( 'woomss_exclude_categories_sync' ) ) ) {
					return;
				}
				if ( empty( $value['productFolder']['meta']['href'] ) ) {
					return;
				}*/
		$checked_choice_include = get_option( 'woomss_include_categories_sync' );
		$url                    = $value['productFolder']['meta']['href'];
		if ( in_array( $url, $checked_choice_include ) ) {
			//do_action( "logger_u7", $value );
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
		if ( ! get_option( 'woomss_categories_sync_enabled' ) ) {
			return;
		}
		register_setting( 'mss-settings', 'woomss_exclude_categories_sync' );
		add_settings_field( 'woomss_exclude_categories_sync', 'Исключить группу', array(
			$this,
			'display_woomss_exclude_categories_sync',
		), 'mss-settings', 'woomss_section_other' );
		
		register_setting( 'mss-settings', 'woomss_include_categories_sync' );
		add_settings_field( 'woomss_include_categories_sync', 'Включить группу', array(
			$this,
			'display_woomss_include_categories_sync',
		), 'mss-settings', 'woomss_section_other' );
	}
	
	//Display field
	public function display_woomss_exclude_categories_sync() {
		$option         = 'woomss_exclude_categories_sync';
		$checked_choice = get_option( 'woomss_exclude_categories_sync' );
		$url            = 'https://online.moysklad.ru/api/remap/1.1/entity/productfolder';
		$data           = wooms_request( $url );
		if ( empty( $data['rows'] ) ) {
			return;
		}
		?>
		
		<small>Выберите группы, которые не требуется синхронизировать</small>
		<ul style="max-height: 180px;overflow: auto; box-shadow: inset 0 0 5px 0px #aaa;padding: 20px;">
			<?php
			
			foreach ( $data['rows'] as $value ):
				if ( ! empty( $value['pathName'] ) ) {
					$path_name = explode( '/', $value['pathName'] );
				} else {
					$path_name        = '';
					$path_name_margin = '';
				}
				
				if ( is_array( $path_name ) && ( count( $path_name ) == 1 ) ) {
					$path_name_margin = 'style="margin-left:20px"';
				} elseif ( is_array( $path_name ) && ( count( $path_name ) >= 2 ) ) {
					$path_name_margin = 'style="margin-left:40px"';
				}
				echo '<li ' . $path_name_margin . ' >';
				echo '<input type="checkbox"
              name="' . $option . '[' . $value['id'] . ']"
              id="' . $option . '-' . $value['id'] . '"
              value="' . esc_attr( $value['meta']['href'] ) . '" ' .
				     ( isset( $checked_choice[ $value['id'] ] ) ? checked( $checked_choice[ $value['id'] ], $value['meta']['href'], false ) : '' ) . ' />';
				echo '<label for="' . $option . '-' . $value['id'] . '">' . $value['name'] . '</label>';
				echo '</li>';
			endforeach;
			?>
		</ul>
		<?php
	}
	
	//Display field
	public function display_woomss_include_categories_sync() {
		$option         = 'woomss_include_categories_sync';
		$checked_choice = get_option( 'woomss_include_categories_sync' );
		
		$url  = 'https://online.moysklad.ru/api/remap/1.1/entity/productfolder';
		$data = wooms_request( $url );
		if ( empty( $data['rows'] ) ) {
			return;
		}
		?>
		<small>Выберите группы, которые надо синхронизировать</small>
		<ul style="max-height: 180px;overflow: auto; box-shadow: inset 0 0 5px 0px #aaa;padding: 20px;">
			<?php
			/*if ( empty( $checked_choice ) ) {
				echo '<pre>';
				var_dump( $checked_choice );
				echo 'yes</pre>';
			} else {
				echo 'no';
			}*/
			foreach ( $data['rows'] as $value ):
				if ( ! empty( $value['pathName'] ) ) {
					$path_name = explode( '/', $value['pathName'] );
				} else {
					$path_name        = '';
					$path_name_margin = '';
				}
				
				if ( is_array( $path_name ) && ( count( $path_name ) == 1 ) ) {
					$path_name_margin = 'style="margin-left:20px"';
				} elseif ( is_array( $path_name ) && ( count( $path_name ) >= 2 ) ) {
					$path_name_margin = 'style="margin-left:40px"';
				}
				echo '<li ' . $path_name_margin . ' >';
				echo '<input type="checkbox"
              name="' . $option . '[' . $value['id'] . ']"
              id="' . $option . '-' . $value['id'] . '"
              value="' . esc_attr( $value['meta']['href'] ) . '" ' .
				     ( isset( $checked_choice[ $value['id'] ] ) ? checked( $checked_choice[ $value['id'] ], $value['meta']['href'], false ) : '' ) . ' />';
				echo '<label for="' . $option . '-' . $value['id'] . '">' . $value['name'] . '</label>';
				echo '</li>';
			endforeach;
			?>
		</ul>
		<?php
	}
}

new WooMS_Import_Product_Choice_Categories;
