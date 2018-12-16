<?php
namespace WooMS\Products;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Select specific price is setup
 */
class Sale_Prices {

	/**
	 * The init
	 */
	public static function init() {

    add_action( 'wooms_product_save', array( __CLASS__, 'update_product' ), 10, 3 );

		add_action( 'wooms_variation_id', array( __CLASS__, 'chg_sale_price' ), 100, 2 );

		add_action( 'admin_init', array(__CLASS__, 'settings_init'), $priority = 101, $accepted_args = 1 );

	}


  /**
   * Update product
   */
  public static function update_product( $product, $value, $data )
  {

    $product_id = $product->get_id();


		$price_name = esc_html( get_option( 'wooms_price_sale_name' ) );

		if ( empty( $price_name ) ) {
			$product->set_sale_price( '' );
			return $product;
		}

		if ( ! empty($value['salePrices']) ) {
			foreach ( $value['salePrices'] as $price ) {

				if($price['priceType'] == $price_name && floatval($price['value']) > 0){
					$product->set_sale_price( apply_filters('wooms_sale_price', floatval($price['value']/100)) );
				} elseif ($price['priceType'] == $price_name && floatval($price['value']) == 0){
					$product->set_sale_price( '' );
				}
			}
		}
    return $product;
  }

	/**
	 * Get sale price
	 *
	 * TODO удалить тк не используется
	 */
	public static function chg_sale_price( $product_id, $value ) {
		$product    = wc_get_product( $product_id );

		$price_name = esc_html( get_option( 'wooms_price_sale_name' ) );

		if ( empty( $price_name ) ) {
			$product->set_sale_price( '' );
			$product->save();
			return;
		}

		if ( ! empty($value['salePrices']) ) {
			foreach ( $value['salePrices'] as $price ) {

				if($price['priceType'] == $price_name && floatval($price['value']) > 0){
					$product->set_sale_price( apply_filters('wooms_sale_price', floatval($price['value']/100)) );
					$product->save();
					return;
				} elseif ($price['priceType'] == $price_name && floatval($price['value']) == 0){
					$product->set_sale_price( '' );
					$product->save();
					return;
				}

			}
		}

	}

	/**
	* Add settings
	*/
	public static function settings_init(){
		register_setting('mss-settings', 'wooms_price_sale_name');
		add_settings_field(
			$id = 'wooms_price_sale_name',
			$title = 'Тип Цены Распродажи',
			$callback = array(__CLASS__, 'display_price_sale_name'),
			$page = 'mss-settings',
			$section = 'woomss_section_other'
		);
	}

  /**
   * display_price_sale_name
   */
	public static function display_price_sale_name() {
		$id = 'wooms_price_sale_name';
		printf( '<input type="text" name="%s" value="%s" />', $id, sanitize_text_field( get_option( $id ) ) );
		echo '<p><small>Укажите наименование цены для Распродаж. Система будет проверять такой тип цены и если он указан то будет сохранять его в карточке Продукта.</small></p>';
		echo '<p><small>Если оставить поле пустым, то цена Распродажи у всех продуктов будут удалены после очередной синхронизации.</small></p>';
	}
}

Sale_Prices::init();
