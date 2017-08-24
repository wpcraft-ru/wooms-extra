<?php

/**
 * Setup wp_cron
 */
class WooMS_WP_Cron {

  function __construct() {
    add_filter( 'cron_schedules', array($this, 'add_schedule') );
    add_action('init', [$this, 'add_hook']);
  }

  /*
	* Регистрируем интервал для wp_cron в секундах
	*/
	function add_schedule( $schedules ) {

		$schedules['wooms_cron_worker'] = array(
			'interval' => 60,
			'display' => 'WooMS Cron Worker'
		);

		return $schedules;
	}

  function add_hook(){

		if ( ! wp_next_scheduled( 'wooms_cron_worker_start' ) ) {
			wp_schedule_event( time(), 'wooms_cron_worker', 'wooms_cron_worker_start' );
		}

	}
}
new WooMS_WP_Cron;
