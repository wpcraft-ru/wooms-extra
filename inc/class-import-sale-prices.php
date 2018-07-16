<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Select specific price is setup
 */
class WooMS_Import_Sale_Prices {

	/**
	 * WooMS_Import_Sale_Prices constructor.
	 */
	public function __construct() {
		add_action( 'wooms_product_update', array( $this, 'chg_sale_price' ), 100, 2 );
		add_action( 'wooms_variation_id', array( $this, 'chg_sale_price' ), 100, 2 );

		add_action( 'admin_init', array($this, 'settings'), $priority = 101, $accepted_args = 1 );

	}

	/**
	 * Get sale price
	 *
	 */
	public function chg_sale_price( $product_id, $value ) {
		$product    = wc_get_product( $product_id );

		$price_name = esc_html( get_option( 'wooms_price_sale_name' ) );

		if ( empty( $price_name ) ) {
			$product->set_sale_price( '' );
			$product->save();
			return;
		}

		if ( ! empty($value['salePrices']) ) {
			foreach ( $value['salePrices'] as $price ) {

				if($price['priceType'] == $price_name and floatval($price['value']) > 0){

					$product->set_sale_price( floatval($price['value']/100) );
					$product->save();
					return;
				}

			}
		}

	}

	/**
	* Add settings
	*/
	function settings(){
		register_setting('mss-settings', 'wooms_price_sale_name');
		add_settings_field(
			$id = 'wooms_price_sale_name',
			$title = 'Тип Цены Распродажи',
			$callback = [$this, 'display_price_sale_name'],
			$page = 'mss-settings',
			$section = 'woomss_section_other'
		);
	}
	function display_price_sale_name(){
		$id = 'wooms_price_sale_name';
		printf('<input type="text" name="%s" value="%s" />', $id, sanitize_text_field(get_option($id)));
		echo '<p><small>Укажите наименование цены для Распродаж. Система будет проверять такой тип цены и если он указан то будет сохранять его в карточке Продукта.</small></p>';
		echo '<p><small>Если оставить поле пустым, то цена Распродажи у всех проудктов будут удалены после очередной синхронизации.</small></p>';
	}
}

new WooMS_Import_Sale_Prices;
