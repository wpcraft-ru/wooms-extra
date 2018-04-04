<?php
/**
 * Plugin Name: WooMS Extra
 * Plugin URI: https://wpcraft.ru/product/wooms-extra/
 * Description: Расширение для синхронизации МойСклад и WooCommerce
 * Author: WPCraft
 * Author URI: https://wpcraft.ru/
 * Developer: WPCraft
 * Developer URI: https://wpcraft.ru/
 * Version: 1.7.0
 * WC requires at least: 3.0
 * WC tested up to: 3.3.4
 *
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 */

require_once 'inc/class-cron.php';
require_once 'inc/class-import-product-stocks.php';
require_once 'inc/class-import-product-attributes.php';
require_once 'inc/class-import-product-variants.php';
require_once 'inc/class-products-bundles.php';
require_once 'inc/class-orders-sending.php';
