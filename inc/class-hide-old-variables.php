<?php

namespace WooMS\Products;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Hide old variations
 */
class Hide_Old_Variations {

	/**
	 * The init
	 */
	public static function init() {
		add_action( 'wooms_hide_old_product', array( __CLASS__, 'set_hide_old_variable' ), 20, 2 );
	}

	/**
	 * Adding hiding attributes to variations
	 */
	public static function set_hide_old_variable( $product_parent, $offset ) {

		if ( empty( get_option( 'woomss_variations_sync_enabled' ) ) ) {
			return;
		}

		if ( ! $offset = get_transient( 'wooms_offset_hide_variations' ) ) {
			$offset = 0;
			set_transient( 'wooms_offset_hide_variations', $offset );
		}

		$variations = self::get_variations_old_session( $offset, $product_parent );

		$i = 0;

		foreach ( $variations as $variations_id ) {
			$variation = wc_get_product( $variations_id );
			$variation->set_stock_status( 'instock' );
			$variation->save();
			$i ++;
		}

		set_transient( 'wooms_offset_hide_variations', $offset + $i );

		if ( empty( $product_parent ) ) {
			delete_transient( 'wooms_offset_hide_variations' );
		}
	}

	/**
	 * Obtaining variations with specific attributes
	 */
	public static function get_variations_old_session( $offset = 0, $product_parent = '' ) {
		$args = array(
			'post_type'   => 'product_variation',
			'post_parent' => $product_parent,
			'numberposts' => 20,
			'fields'      => 'ids',
			'offset'      => $offset,
			'meta_query'  => array(
				/*array(
					'key'     => 'wooms_session_id',
					'value'   => $this->get_session(),
					'compare' => '!=',
				),*/
				array(
					'key'     => 'wooms_id',
					'compare' => 'EXISTS',
				),
			),
		);

		return get_posts( $args );
	}

	/**
	 * Method for getting the value of an option
	 */
	public static function get_session() {
		$session_id = get_option( 'wooms_session_id' );
		if ( empty( $session_id ) ) {
			return false;
		}

		return $session_id;
	}
}

Hide_Old_Variations::init();
