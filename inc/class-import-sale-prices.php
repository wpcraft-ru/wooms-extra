<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Select specific price is setup
 */
class WooMS_Import_Sale_Prices {
	public $price_sale_value;
	
	/**
	 * WooMS_Import_Sale_Prices constructor.
	 */
	public function __construct() {
		$this->price_sale_value = '';
		add_action( 'wooms_product_update', array( $this, 'get_sale_price' ), 100, 2 );
		add_action( 'wooms_variation_id', array( $this, 'get_sale_price' ), 100, 2 );
	}
	
	/**
	 * Get sale price
	 *
	 */
	public function get_sale_price( $product_id, $value ) {
		$product    = wc_get_product( $product_id );
		$price_name = esc_html( get_option( 'wooms_price_id' ) );
		
		if ( empty( $price_name ) ) {
			$product->set_sale_price( '' );
			$product->set_price( '' );
		}
		
		if ( $value['salePrices'] ) {
			foreach ( $value['salePrices'] as $price ) {
				if ( 'Распродажа' == $price_name ) {
					$this->price_sale_value = ( $price['value'] ) / 100;
				}
			}
		}
		
		if ( ! empty( $this->price_sale_value ) && 0 != $this->price_sale_value ) {
			$product->set_sale_price( $this->price_sale_value );
			$product->set_price( $this->price_sale_value );
			
		} else {
			$product->set_sale_price( '' );
			$product->set_price( '' );
		}
		
		$product->save();
	}
}

new WooMS_Import_Sale_Prices;
