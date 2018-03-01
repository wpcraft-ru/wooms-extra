<?php

/**
 * Send orders to MoySklad
 */
class WooMS_Orders_Sender {
	
	/**
	 * WooMS_Orders_Sender constructor.
	 */
	function __construct() {
		
		add_action( 'woomss_tool_actions_btns', array( $this, 'ui_for_manual_start' ), 15 );
		add_action( 'woomss_tool_actions_wooms_orders_send', array( $this, 'ui_action' ) );
		add_action( 'wooms_cron_order_sender', array( $this, 'cron_starter_walker' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_date_picker' ) );
		add_action( 'rest_api_init', array( $this, 'rest_api_init_callback_endpoint' ) );
		add_action( 'admin_init', array( $this, 'settings_init' ), 100 );
	}
	
	
	/**
	 * Add endpoint /wp-json/wooms/v1/order-update/
	 */
	function rest_api_init_callback_endpoint() {
		register_rest_route( 'wooms/v1', '/order-update/', array(
			// 'methods' => WP_REST_Server::READABLE,
			'methods'  => WP_REST_Server::EDITABLE,
			'callback' => array( $this, 'get_data_order_from_moysklad' ),
		) );
	}
	
	
	/**
	 *
	 * Get data from MoySkald and start update order
	 *
	 * @param $data_request
	 *
	 * @return void|WP_REST_Response
	 */
	function get_data_order_from_moysklad( $data_request ) {
		
		try {
			$body = $data_request->get_body();
			$data = json_decode( $body, true );
			if ( empty( $data["events"][0]["meta"]["href"] ) ) {
				return;
			}
			$url = $data["events"][0]["meta"]["href"];
			$data_order = wooms_get_data_by_url( $url );
			if ( empty( $data_order['id'] ) ) {
				return;
			}
			$order_uuid = $data_order['id'];
			$state_url  = $data_order["state"]["meta"]["href"];
			$state_data = wooms_get_data_by_url( $state_url );
			if ( empty( $state_data['name'] ) ) {
				return;
			}
			$state_name = $state_data['name'];
			$result = $this->check_and_update_order_status( $order_uuid, $state_name );
			if ( $result ) {
				$response = new WP_REST_Response( array( 'success', 'Data received successfully' ) );
				$response->set_status( 200 );
				
				return $response;
			} else {
				throw new Exception( "Заказ не обновился" );
			}
		} catch ( Exception $e ) {
			$response = new WP_REST_Response( array( 'fail', $e->getMessage() ) );
			$response->set_status( 500 );
			
			return $response;
		}
	}
	
	
	/**
	 * Update order by data from MoySklad
	 *
	 * @param $order_uuid
	 * @param $state_name
	 *
	 * @return bool
	 */
	function check_and_update_order_status( $order_uuid, $state_name ) {
		
		$args = array(
			'numberposts' => 1,
			'post_type'   => 'shop_order',
			'post_status' => 'any',
			'meta_key'    => 'wooms_id',
			'meta_value'  => $order_uuid,
		);
		$orders = get_posts( $args );
		if ( empty( $orders[0]->ID ) ) {
			return false;
		}
		$order_id = $orders[0]->ID;
		$order = wc_get_order( $order_id );
		switch ( $state_name ) {
			case "Новый":
				$check = $order->update_status( 'pending', 'Выбран статус "Новый" через МойСклад' );
				break;
			case "Подтвержден":
				$check = $order->update_status( 'processing', 'Выбран статус "Подтвержден" через МойСклад' );
				break;
			case "Собран":
				$check = $order->update_status( 'processing', 'Выбран статус "Собран" через МойСклад' );
				break;
			case "На удержании":
				$check = $order->update_status( 'on-hold', 'Выбран статус "На удержании" через МойСклад' );
				break;
			case "Отменен":
				$check = $order->update_status( 'cancelled', 'Отменен через МойСклад' );
				break;
			case "Не удался":
				$check = $order->update_status( 'failed', 'Выбран статус "Не удался" через МойСклад' );
				break;
			case "Возврат":
				$check = $order->update_status( 'refunded', 'Статус "Возврат" через МойСклад' );
				break;
			case "Отгружен":
				$check = $order->update_status( 'completed', 'Выбран статус "Отгружен" через МойСклад' );
				break;
			case "Доставлен":
				$check = $order->update_status( 'completed', 'Выбран статус "Доставлен" через МойСклад' );
				break;
			default:
				$check = false;
		}
		$check = apply_filters( 'wooms_order_status_chg', $check, $order, $state_name );
		if ( $check ) {
			return true;
		} else {
			return false;
		}
	}
	
	/**
	 * Start by cron
	 */
	function cron_starter_walker() {
		$this->walker();
	}
	
	/**
	 * Main walker for send orders
	 *
	 * @return array
	 */
	function walker() {
		$args = array(
			'numberposts'  => apply_filters( 'wooms_orders_number', 5 ),
			'post_type'    => 'shop_order',
			'post_status'  => 'any',
			'meta_key'     => 'wooms_send_timestamp',
			'meta_compare' => 'NOT EXISTS',
		);
		if ( empty( get_option( 'wooms_orders_send_from' ) ) ) {
			$date_from = '2 day ago';
		} else {
			$date_from = get_option( 'wooms_orders_send_from' );
		}
		$args['date_query'] = array(
			'after' => $date_from,
		);
		$orders = get_posts( $args );
		$result_list = [];
		foreach ( $orders as $key => $order ) {
			$check = $this->send_order( $order->ID );
			if ( $check ) {
				update_post_meta( $order->ID, 'wooms_send_timestamp', date( "Y-m-d H:i:s" ) );
				$result_list[] = $order->ID;
			}
		}
		if ( ! empty( $result_list ) ) {
			return $result_list;
		} else {
			false;
		}
	}
	
	/**
	 * Send order to moysklad.ru and mark the order as sended
	 *
	 * @param $order_id
	 *
	 * @return bool
	 */
	function send_order( $order_id ) {
		$data = $this->get_data_order_for_moysklad( $order_id );
		if ( empty( $data ) ) {
			update_post_meta( $order_id, 'wooms_send_timestamp', date( "Y-m-d H:i:s" ) );
			
			return false;
		}
		$url = 'https://online.moysklad.ru/api/remap/1.1/entity/customerorder';
		$result = $this->send_data( $url, $data );
		if ( empty( $result['id'] ) ) {
			return false;
		}
		update_post_meta( $order_id, 'wooms_id', $result['id'] );
		
		return true;
	}
	
	/**
	 * Prepare data before send
	 *
	 * @param $order_id
	 *
	 * @return array|bool
	 */
	function get_data_order_for_moysklad( $order_id ) {
		$data = [
			"name" => apply_filters( 'wooms_order_name', (string) $order_id ),
		];
		$data['positions'] = $this->get_data_positions( $order_id );
		if ( empty( $data['positions'] ) ) {
			return false;
		}
		$data["organization"] = $this->get_data_organization();
		$data["agent"]        = $this->get_data_agent( $order_id );
		
		return $data;
	}
	
	/**
	 * Get data of positions the order
	 *
	 * @param $order_id
	 *
	 * @return array|bool
	 */
	function get_data_positions( $order_id ) {
		$order = wc_get_order( $order_id );
		$items = $order->get_items();
		if ( empty( $items ) ) {
			return false;
		}
		$data = [];
		foreach ( $items as $key => $item ) {
			
			$product_id = $item["product_id"];
			$uuid       = get_post_meta( $product_id, 'wooms_id', true );
			if ( empty( $uuid ) ) {
				continue;
			}
			if ( apply_filters( 'wooms_order_item_skip', false, $product_id, $item ) ) {
				continue;
			}
			$price = $item->get_total();
			$quantity = $item->get_quantity();
			$data[] = [
				'quantity'   => $quantity,
				'price'      => ( $price / $quantity ) * 100,
				'discount'   => 0,
				'vat'        => 0,
				'assortment' => [
					'meta' => [
						"href"      => "https://online.moysklad.ru/api/remap/1.1/entity/product/" . $uuid,
						"type"      => "product",
						"mediaType" => "application/json",
					],
				],
				'reserve'    => 0,
			];
		}
		if ( empty( $data ) ) {
			return false;
		} else {
			return $data;
		}
	}
	
	
	/**
	 * Get meta for organization
	 * @return array|bool
	 */
	function get_data_organization() {
		$url  = 'https://online.moysklad.ru/api/remap/1.1/entity/organization';
		$data = wooms_get_data_by_url( $url );
		$meta = $data['rows'][0]['meta'];
		if ( empty( $meta ) ) {
			return false;
		}
		
		return array( 'meta' => $meta );
	}
	
	/**
	 * Get data counterparty for send MoySklad
	 *
	 * @param $order_id
	 *
	 * @return array|bool
	 */
	function get_data_agent( $order_id ) {
		
		$order = wc_get_order( $order_id );
		$user = $order->get_user();
		$email = '';
		if ( empty( $user ) ) {
			if ( ! empty( $order->get_billing_email() ) ) {
				$email = $order->get_billing_email();
			}
		} else {
			$email = $user->user_email;
		}
		if ( empty( $email ) ) {
			return false;
		}
		
		$meta = $this->get_agent_meta_by_email( $email );
		if ( empty( $meta ) ) {
			
			$name = $order->get_billing_company();
			$phone = $order->get_billing_phone();
			if ( empty( $name ) ) {
				$name = $order->get_billing_last_name();
				if ( ! empty( $order->get_billing_first_name() ) ) {
					$name .= ' ' . $order->get_billing_first_name();
				}
			}
			$company_type = 'legal';
			if ( empty( $name ) ) {
				$name         = "Клиент по заказу №" . $order_id;
				//$company_type = 'individual';
			}
			$data = array(
				"name"        => $name,
				//"companyType" => $company_type,
			);
			if ( ! empty( $email ) ) {
				$data['email'] = $email;
			}
			if ( ! empty( $phone ) ) {
				$data['phone'] = $phone;
			}
			//return $data;
			$url = 'https://online.moysklad.ru/api/remap/1.1/entity/counterparty';
			$result = $this->send_data( $url, $data );
			if ( empty( $result["meta"] ) ) {
				return false;
			}
			$meta = $result["meta"];
		}
		
		return array( 'meta' => $meta );
	}
	
	/**
	 * Get meta by email agent
	 *
	 * @param string $email
	 *
	 * @return bool
	 */
	function get_agent_meta_by_email( $email = '' ) {
		$url_search_agent = 'https://online.moysklad.ru/api/remap/1.1/entity/counterparty?filter=email=' . $email;
		$data_agents      = wooms_get_data_by_url( $url_search_agent );
		if ( empty( $data_agents['rows'][0]['meta'] ) ) {
			return false;
		} else {
			return $data_agents['rows'][0]['meta'];
		}
	}
	
	/**
	 * Send data to MoySklad
	 *
	 * @param $url
	 * @param $data
	 *
	 * @return array|bool|mixed|object
	 */
	function send_data( $url, $data ) {
		
		if ( is_array( $data ) ) {
			$data = json_encode( $data );
		} else {
			return false;
		}
		$args = array(
			'timeout' => 45,
			'headers' => array(
				"Content-Type"  => 'application/json',
				'Authorization' => 'Basic ' .
				                   base64_encode( get_option( 'woomss_login' ) . ':' . get_option( 'woomss_pass' ) ),
			),
			'body'    => $data,
		);
		$response      = wp_remote_post( $url, $args );
		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$result        = json_decode( $response_body, true );
		if ( empty( $result ) ) {
			return false;
		} else {
			return $result;
		}
	}
	
	/**
	 * Start manual send orders to MoySklad
	 */
	function ui_for_manual_start() {
		
		?>
		<h2>Отправка заказов в МойСклад</h2>
		<p>Для отправки ордеров в МойСклад - нажмите на кнопку</p>
			<a href="<?php echo add_query_arg( 'a', 'wooms_orders_send', admin_url( 'tools.php?page=moysklad' ) ) ?>"class="button">Выполнить</a>
		<p><strong>Внимание!</strong> Отправка новых заказов происходит автоматически раз в минуту.</p>
		<?php
	}
	
	
	/**
	 * Setting
	 */
	function settings_init() {
		
		add_settings_section( 'wooms_section_orders', 'Заказы - передача в МойСклад', '', 'mss-settings' );
		register_setting( 'mss-settings', 'wooms_orders_sender_enable' );
		add_settings_field( $id = 'wooms_orders_sender_enable', $title = 'Включить синхронизацию заказов в МойСклад', $callback = [
			$this,
			'display_wooms_orders_sender_enable',
		], $page = 'mss-settings', $section = 'wooms_section_orders' );
		register_setting( 'mss-settings', 'wooms_orders_send_from' );
		add_settings_field( $id = 'wooms_orders_send_from', $title = 'Дата, с которой берутся Заказы для отправки', $callback = [
			$this,
			'display_wooms_orders_send_from',
		], $page = 'mss-settings', $section = 'wooms_section_orders' );
	}
	
	/**
	 * Send statuses from MoySklad
	 */
	function display_wooms_orders_sender_enable() {
		$option = 'wooms_orders_sender_enable';
		printf( '<input type="checkbox" name="%s" value="1" %s />', $option, checked( 1, get_option( $option ), false ) );
		?>
		<div>
			<hr>
			<strong>Передатчик Статусов из Склада на Сайт:</strong>
			<span><?php $this->get_status_order_webhook() ?></span>
		</div>
		
		<?php
	}
	
	/**
	 *
	 */
	function get_status_order_webhook() {
		// echo "<hr>";
		$check = $this->check_webhooks_and_try_fix();
		$url  = 'https://online.moysklad.ru/api/remap/1.1/entity/webhook';
		$data = wooms_get_data_by_url( $url );
		$webhooks = array();
		foreach ( $data['rows'] as $row ) {
			if ( $row['url'] == rest_url( '/wooms/v1/order-update/' ) ) {
				$webhooks[ $row['id'] ] = array(
					'entityType' => $row['entityType'],
					'url'        => $row['url'],
					'method'     => $row['method'],
					'enabled'    => $row['enabled'],
					'action'     => $row['action'],
				);
			}
		}
		if ( empty( get_option( 'wooms_orders_sender_enable' ) ) ) {
			if ( empty( $webhooks ) ) {
				echo "Хук на стороне МойСклад отключен в соответствии с настройкой";
			} else {
				echo "Что то пошло не так. Хук на стороне МойСклад остался включен в нарушении настроек.";
			}
		} else {
			if ( empty( $webhooks ) ) {
				echo "Что то пошло не так. Хук на стороне МойСклад отключен в нарушении настройки. Попробуйте отключить и включить снова. Если не поможет - обратитесь в техподдержку.";
			} else {
				echo "Хук на стороне МойСклад добавлен в соответствии с настройки";
			}
		}
		echo '<p><small>Ссылка для получения данных от МойСклад: ' . rest_url( '/wooms/v1/order-update/' ) .
		     '</small></p>';
		// echo '<pre>';
		// var_dump($data['rows']);
		// echo '</pre>';
	}
	
	/**
	 * Check isset hook and fix if not isset
	 *
	 * @return bool
	 */
	function check_webhooks_and_try_fix() {
		$url  = 'https://online.moysklad.ru/api/remap/1.1/entity/webhook';
		$data = wooms_get_data_by_url( $url );
		$webhooks = array();
		foreach ( $data['rows'] as $row ) {
			if ( $row['entityType'] != 'customerorder' ) {
				continue;
			}
			if ( $row['url'] != rest_url( '/wooms/v1/order-update/' ) ) {
				continue;
			}
			$webhooks[ $row['id'] ] = array(
				'entityType' => $row['entityType'],
				'url'        => $row['url'],
				'method'     => $row['method'],
				'enabled'    => $row['enabled'],
				'action'     => $row['action'],
			);
		}
		//Проверка на включение опции и наличия хуков
		if ( empty( get_option( 'wooms_orders_sender_enable' ) ) ) {
			
			if ( empty( $webhooks ) ) {
				return true;
			} else {
				//пытаемся удалить лишний хук
				$args = array(
					'timeout' => 45,
					'headers' => array(
						"Content-Type"  => 'application/json',
						'Authorization' => 'Basic ' . base64_encode( get_option( 'woomss_login' ) . ':' .
						                                             get_option( 'woomss_pass' ) ),
					),
					'method'  => 'DELETE',
				);
				foreach ( $webhooks as $id => $value ) {
					$url   = 'https://online.moysklad.ru/api/remap/1.1/entity/webhook/' . $id;
					$check = wp_remote_request( $url, $args );
				}
			}
		} else {
			//Если нужного вебхука нет - создаем новый
			if ( empty( $webhooks ) ) {
				// создаем веб хук в МойСклад
				$data = array(
					'url'        => rest_url( '/wooms/v1/order-update/' ),
					'action'     => "UPDATE",
					"entityType" => "customerorder",
				);
				$result = $this->send_data( $url, $data );
				if ( empty( $result ) ) {
					return false;
				} else {
					return true;
				}
			} else {
				return true;
			}
		}
	}
	
	/**
	 *
	 */
	function display_wooms_orders_send_from() {
		$option_key = 'wooms_orders_send_from';
		printf( '<input type="text" name="%s" value="%s" />', $option_key, get_option( $option_key ) );
		echo '<p><small>Если дата не выбрана, то берутся заказы сегодняшнего и вчерашнего дня. Иначе берутся все новые заказы с указанной даты.</small></p>';
		?>
		<script type="text/javascript">
            jQuery(document).ready(function () {
                jQuery('input[name=wooms_orders_send_from]').datepicker();
            });
		</script>
		<?php
	}
	
	/**
	 * UI for manual start send data of order
	 */
	function ui_action() {
		$result_list = $this->walker();
		echo '<br/><hr>';
		if ( empty( $result_list ) ) {
			echo "<p>Нет <strong>новых</strong> заказов для передачи в МойСклад</p>";
		} else {
			foreach ( $result_list as $key => $value ) {
				printf( '<p>Передан заказ <a href="%s">№%s</a></p>', get_edit_post_link( $value ), $value );
			}
		}
	}
	
	
	/**
	 * Add jQuery date picker for select start-date sending orders to MoySklad
	 */
	function enqueue_date_picker() {
		$screen = get_current_screen();
		if ( empty( $screen->id ) or 'settings_page_mss-settings' != $screen->id ) {
			return;
		}
		wp_enqueue_script( 'jquery-ui-datepicker' );
		$wp_scripts = wp_scripts();
		wp_enqueue_style( 'jquery-ui', 'http://ajax.googleapis.com/ajax/libs/jqueryui/' .
		                               $wp_scripts->registered['jquery-ui-core']->ver .
		                               '/themes/smoothness/jquery-ui.css', false, $wp_scripts->registered['jquery-ui-core']->ver, false );
	}
	
}

new WooMS_Orders_Sender;