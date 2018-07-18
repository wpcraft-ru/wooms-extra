<?php
/**
 * Import Product Categories from MoySklad
 */
class WooMS_Import_Product_Choice_Categories {
	
	public function __construct() {
		add_action( 'admin_init', array( $this, 'settings_init' ), 102 );
		
		if ( empty( get_option( 'woomss_include_categories_sync' ) ) ) {
			return;
		}
		
		add_filter( 'wooms_variant_ms_api_url', array( $this, 'change_ms_api_url_variant' ), 10 );
		add_filter( 'wooms_product_ms_api_url', array( $this, 'change_ms_api_url_simple' ), 10 );
		add_filter( 'wooms_skip_categories', array( $this, 'skip_base_parent_category' ), 10, 3 );
	}
	
	
	/**
	 * Wrapper to get the option value
	 *
	 * @return mixed|void
	 */
	public function select_category() {
		return get_option( 'woomss_include_categories_sync' );
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
	 * Replace the link for import from the selected category for variative products
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
