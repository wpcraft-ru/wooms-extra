<?php


/**
 * Import Product Images
 */
class WooMS_Import_Product_Images {

  function __construct() {
    //do_action('wooms_product_import_row', $value, $key, $data);

    add_action( 'admin_init', array($this, 'settings_init'), 100 );

    //Use hook do_action('wooms_product_update', $product_id, $value, $data);
    add_action('wooms_product_update', [$this, 'load_data'], 10, 3);

    add_action('wooms_cron_worker_start', [$his, 'download_images']);

    add_action('woomss_tool_actions_btns', [$this, 'ui_for_manual_start'], 15);
    add_action('woomss_tool_actions', [$this, 'ui_action']);

  }

  function download_images(){


    $list = get_posts('post_type=product&meta_key=wooms_url_for_get_thumbnail&meta_compare=EXISTS');

    foreach ($list as $key => $value) {
      $url = get_post_meta($value->ID, 'wooms_url_for_get_thumbnail', true);
      $image_data = get_post_meta($value->ID, 'wooms_image_data', true);


      $image_name = $image_data['filename'];


      $att_id = $this->download_url($url, $image_name, $value->ID);

      // $this->save_image_product_from_moysklad($url, $image_name, $value->ID);
    }


  }


    /**
    * Download by url
    *
    * @return $attachment_id or false
    */
    function download_url( $url, $filename, $post_id ) {


      $timeout = 300;

    	//WARNING: The file is not automatically deleted, The script must unlink() the file.
    	if ( ! $url )
    		return new WP_Error('http_no_url', __('Invalid URL Provided.'));

    	$url_filename = $filename;

    	$tmpfname = wp_tempnam( $url_filename );
    	if ( ! $tmpfname )
    		return new WP_Error('http_no_file', __('Could not create Temporary file.'));

      $remote_arg = array(
        'timeout' => $timeout,
        'stream' => true,
        'filename' => $tmpfname,
        'headers' => array(
            'Authorization' => 'Basic ' . base64_encode( get_option( 'woomss_login' ) . ':' . get_option( 'woomss_pass' ) )
        )

      );

    	$response = wp_remote_get( $url, $remote_arg );


    	if ( is_wp_error( $response ) ) {
    		unlink( $tmpfname );
    		return $response;
    	}

    	if ( 200 != wp_remote_retrieve_response_code( $response ) ){
    		unlink( $tmpfname );

        echo '<pre>';
        var_dump($post_id); exit;
        echo '</pre>';

    		return new WP_Error( 'http_404', trim( wp_remote_retrieve_response_message( $response ) ) );
    	}

    	$content_md5 = wp_remote_retrieve_header( $response, 'content-md5' );
    	if ( $content_md5 ) {
    		$md5_check = verify_file_md5( $tmpfname, $content_md5 );
    		if ( is_wp_error( $md5_check ) ) {
    			unlink( $tmpfname );
    			return $md5_check;
    		}
    	}



    	// return $tmpfname;


      $file_array = [
        'name' => $url_filename,
        'tmp_name' => $tmpfname
      ];

      $id = media_handle_sideload( $file_array, $post_id, $desc = '' );


      // если ошибка
      if( is_wp_error( $id ) ) {
      	@unlink($file_array['tmp_name']);
      	return $id->get_error_messages();
      }

      @unlink( $file_array['tmp_name'] );

      if(intval($id)){
        return $id;
      } else {
        false;
      }

    }


   /**
    * Save image from MoySklad for Product
    *
    * @param $url  - url of image from MoySklad REST API
    * @param $product_id  - ID of product WooCommerce
    * @return
    */
    private function save_image_product_from_moysklad($url, $image_name, $product_id){




			$upload = $this->upload_image_from_url( esc_url_raw( $url ), $image_name );


			if ( is_wp_error( $upload ) ) {
				return false;
			}

			$attachment_id = $this->set_uploaded_image_as_attachment( $upload, $product_id );



			if ( ! wp_attachment_is_image( $attachment_id ) ) {
				return false;
			}

      printf('<p>+ For product loaded image id: %s</p>', $attachment_id);

      update_post_meta($attachment_id, '_href_moysklad', esc_url_raw( $url ) );

			if(set_post_thumbnail( $product_id, $attachment_id )){
        delete_post_meta($product_id, 'wooms_url_for_get_thumbnail');
        delete_post_meta($product_id, 'wooms_image_data');

      }


		}









      /**
       * Set uploaded image as attachment.
       *
       * @since 1.0
       * @param array $upload Upload information from wp_upload_bits.
       * @param int $id Post ID. Default to 0.
       * @return int Attachment ID
       */
      function set_uploaded_image_as_attachment( $upload, $id = 0 ) {
      	$info    = wp_check_filetype( $upload['file'] );
      	$title = '';
      	$content = '';

      	if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
      		include_once( ABSPATH . 'wp-admin/includes/image.php' );
      	}

      	if ( $image_meta = wp_read_image_metadata( $upload['file'] ) ) {
      		if ( trim( $image_meta['title'] ) && ! is_numeric( sanitize_title( $image_meta['title'] ) ) ) {
      			$title = wc_clean( $image_meta['title'] );
      		}
      		if ( trim( $image_meta['caption'] ) ) {
      			$content = wc_clean( $image_meta['caption'] );
      		}
      	}

      	$attachment = array(
      		'post_mime_type' => $info['type'],
      		'guid'           => $upload['url'],
      		'post_parent'    => $id,
      		'post_title'     => $title,
      		'post_content'   => $content,
      	);

      	$attachment_id = wp_insert_attachment( $attachment, $upload['file'], $id );
      	if ( ! is_wp_error( $attachment_id ) ) {
      		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );
      	}

      	return $attachment_id;
      }

      /**
       * Upload image from URL.
       *
       * @since 1.0
       * @param string $image_url
       * @param string $file_name
       * @return array|WP_Error Attachment data or error message.
       */
      function upload_image_from_url( $image_url, $file_name = '' ) {
      	if(empty($file_name)){
      		$file_name  = basename( current( explode( '?', $image_url ) ) );
      	}


      	$parsed_url = @parse_url( $image_url );

      	// Check parsed URL.
      	if ( ! $parsed_url || ! is_array( $parsed_url ) ) {
      		return new WP_Error( 'woomss_invalid_image_url', sprintf( 'Invalid URL %s.', $image_url ), array( 'status' => 400 ) );
      	}



      	// Ensure url is valid.
      	$image_url = esc_url_raw( $image_url );

        $tmpfname = wp_tempnam( $file_name );

      	// Get the file.
      	$response = wp_safe_remote_get( $image_url, array(
      		'timeout' => 15,
          'stream' => true,
          'filename' => $tmpfname,
          'headers' => array(
              'Authorization' => 'Basic ' . base64_encode( get_option( 'woomss_login' ) . ':' . get_option( 'woomss_pass' ) )
          )
      	));

        if ( is_wp_error( $response ) ) {
      		unlink( $tmpfname );
      		return $response;
      	}


        var_dump($response); exit;



      	if ( is_wp_error( $response ) ) {
      		return new WP_Error( 'woomss_invalid_remote_image_url', sprintf( __( 'Error getting remote image %s.', 'woocommerce' ), $image_url ) . ' ' . sprintf( __( 'Error: %s.', 'woocommerce' ), $response->get_error_message() ), array( 'status' => 400 ) );
      	} elseif ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
      		return new WP_Error( 'woomss_invalid_remote_image_url', sprintf( __( 'Error getting remote image %s.', 'woocommerce' ), $image_url ), array( 'status' => 400 ) );
      	}

      	// Ensure we have a file name and type.
      	$wp_filetype = wp_check_filetype( $file_name, wc_rest_allowed_image_mime_types() );

      	if ( ! $wp_filetype['type'] ) {
      		$headers = wp_remote_retrieve_headers( $response );
      		if ( isset( $headers['content-disposition'] ) && strstr( $headers['content-disposition'], 'filename=' ) ) {
      			$disposition = end( explode( 'filename=', $headers['content-disposition'] ) );
      			$disposition = sanitize_file_name( $disposition );
      			$file_name   = $disposition;
      		} elseif ( isset( $headers['content-type'] ) && strstr( $headers['content-type'], 'image/' ) ) {
      			$file_name = 'image.' . str_replace( 'image/', '', $headers['content-type'] );
      		}
      		unset( $headers );

      		// Recheck filetype
      		$wp_filetype = wp_check_filetype( $file_name, wc_rest_allowed_image_mime_types() );

      		if ( ! $wp_filetype['type'] ) {
      			return new WP_Error( 'woomss_invalid_image_type', __( 'Invalid image type.', 'woocommerce' ), array( 'status' => 400 ) );
      		}
      	}

      	// Upload the file.
      	$upload = wp_upload_bits( $file_name, '', wp_remote_retrieve_body( $response ) );

      	if ( $upload['error'] ) {
      		return new WP_Error( 'woomss_image_upload_error', $upload['error'], array( 'status' => 400 ) );
      	}

      	// Get filesize.
      	$filesize = filesize( $upload['file'] );

      	if ( 0 == $filesize ) {
      		@unlink( $upload['file'] );
      		unset( $upload );

      		return new WP_Error( 'woomss_image_upload_file_error', __( 'Zero size file downloaded.', 'woocommerce' ), array( 'status' => 400 ) );
      	}

      	do_action( 'woomss_uploaded_image_from_url', $upload, $image_url );

      	return $upload;
      }

    /**
     * Check save or not image for product
     *
     * @param $product_id - id of product
     * @param $url_image_moysklad - irl image from MoySklad
     * @return bool
     */
    function is_image_save($product_id, $url_image_moysklad){
      $data = get_posts('post_type=attachment&meta_key=_href_moysklad&meta_value=' . esc_url_raw($url_image_moysklad) );
      if( ! empty($data) ){
        return true;
      }
      return false;
    }

  /**
   * Manual start images download
   */
  function ui_for_manual_start(){
    if( empty(get_option('woomss_images_sync_enabled')) ){
      return;
    }

    ?>
    <h2>Загрузка картинок</h2>
    <p>Для выполнения загрузки картинок вручную - нажмите на кнопку</p>
    <a href="<?php echo add_query_arg('a', 'wooms_products_images_manual_start', admin_url('tools.php?page=moysklad')) ?>" class="button">Выполнить</a>
    <?php
  }

  /**
   * Action for UI
   */
  public function ui_action() {


    // $data = wooms_get_data_by_url('https://online.moysklad.ru/api/remap/1.1/entity/product/0004fbc1-06ea-11e6-7a69-9711000ac43f');
    //
    // var_dump($data); exit;
    //
    //
    //
    //
    //

    $this->download_images();

  }


  function load_data($product_id, $value, $data){
    if( empty(get_option('woomss_images_sync_enabled')) ){
      return;
    }

    //Check image
    if(empty($value['image']['meta']['href'])){
      return;
    } else {
      $url = $value['image']['meta']['href'];
    }

    //check current thumbnail. if isset - break, or add url for next downloading
    if($id = get_post_thumbnail_id( $product_id )){
      return;
    } else {
      update_post_meta($product_id, 'wooms_url_for_get_thumbnail', $url);
      update_post_meta($product_id, 'wooms_image_data', $value['image']);
    }

  }






    function settings_init(){

      add_settings_section(
      	'woomss_section_images',
      	'Загрузка картинок',
      	null,
      	'mss-settings'
      );

      register_setting('mss-settings', 'woomss_images_sync_enabled');
      add_settings_field(
        $id = 'woomss_images_sync_enabled',
        $title = 'Включить синхронизацию картинок',
        $callback = [$this, 'setting_images_sync_enabled'],
        $page = 'mss-settings',
        $section = 'woomss_section_images'
      );

    }

    //Display field
    function setting_images_sync_enabled(){
      $option = 'woomss_images_sync_enabled';
      printf('<input type="checkbox" name="%s" value="1" %s />', $option, checked( 1, get_option($option), false ));
    }



}
new WooMS_Import_Product_Images;
