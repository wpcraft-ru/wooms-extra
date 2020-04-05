<?php

namespace WooMS;

// Exit if accessed directly
defined('ABSPATH') || exit;

/**
 * Import variants from MoySklad
 */
class ProductVariable
{
    /**
     * Save state in DB
     * 
     * @var string
     */
    public static $state_transient_key = 'wooms_variables_walker_state';

    /**
     * Hookd and key for ActionSheduler
     *
     * @var string
     */
    public static $walker_hook_name = 'wooms_variables_walker_batch';


    /**
     * The init
     */
    public static function init()
    {
        add_action('wooms_product_save', array(__CLASS__, 'update_product'), 20, 3);


        add_filter('wooms_save_variation', array(__CLASS__, 'save_attributes_for_variation'), 10, 3);
        add_action('wooms_products_variations_item', array(__CLASS__, 'load_data_variant'), 15);

        //Other
        add_action('admin_init', array(__CLASS__, 'settings_init'), 150);
        add_action('woomss_tool_actions_btns', array(__CLASS__, 'render_ui'), 15);
        add_action('woomss_tool_actions_wooms_import_variations_manual_start', array(__CLASS__, 'start_manually'));
        add_action('woomss_tool_actions_wooms_import_variations_manual_stop', array(__CLASS__, 'stop_manually'));
        add_action('wooms_main_walker_finish', array(__CLASS__, 'reset_after_main_walker_finish'));

        add_action('init', array(__CLASS__, 'add_schedule_hook'));
        add_action('wooms_variables_walker_batch', array(__CLASS__, 'walker'));
    }


    /**
     * Walker for data variant product from MoySklad
     */
    public static function walker($args = [])
    {
       
        if(self::is_wait()){
            return;
        }
        
        $state = self::get_state();

        if( ! empty($state['lock'])){
            return; // блокировка состояни гонки
        }
        self::set_state('lock', 1);

        //reset state if new session
        if(empty($state['timestamp'])){

            self::set_state('timestamp', date("YmdHis"));
            self::set_state('end_timestamp', 0);
            self::set_state('count', 0);

            $query_arg_default = [
                'offset' => 0,
                'limit'  => apply_filters('wooms_variant_iteration_size', 30),
                'scope'  => 'variant',
            ];

            self::set_state('query_arg', $query_arg_default);
        }

        $query_arg = self::get_state('query_arg');
 
        $url = 'https://online.moysklad.ru/api/remap/1.1/entity/assortment';

        $url = add_query_arg($query_arg, $url);

        $url = apply_filters('wooms_url_get_variants', $url);

        try {

            do_action('wooms_logger', __CLASS__,
                sprintf('Вариации. Отправлен запрос: %s', $url),
                $state 
            );

            $data = wooms_request($url);

            //Check for errors and send message to UI
            if (isset($data['errors'][0]["error"])) {
                throw new \Exception($data['errors'][0]["error"]);
            }

            //If no rows, that send 'end' and stop walker
            if (isset($data['rows']) && empty($data['rows'])) {

                self::set_state('lock', 0);
                self::walker_finish();
                return true;
            }

            $i = 0;
            foreach ($data['rows'] as $key => $item) {

                if ($item["meta"]["type"] != 'variant') {
                    continue;
                }

                $i++;

                do_action('wooms_products_variations_item', $item);

                /**
                 * deprecated
                 */
                do_action('wooms_product_variant_import_row', $item, $key, $data);
            }

            //update count
            self::set_state( 'count', self::get_state('count') + $i );

            //update offset 
            $query_arg['offset'] = $query_arg['offset'] + count($data['rows']);

            self::set_state('query_arg', $query_arg);

            self::set_state('lock', 0);

            self::add_schedule_hook(true);

            return true;
        } catch (\Exception $e) {
            self::set_state('lock', 0);
            do_action('wooms_logger_error', __CLASS__,
                $e->getMessage()
            );
            return false;
        }
    }


    /**
     * reset_after_main_walker_finish
     */
    public static function reset_after_main_walker_finish()
    {
        self::set_state('timestamp', 0);
        self::set_state('count', 0);
        self::set_state('end_timestamp', 0);
        self::add_schedule_hook();
    }


    /**
     * Set attributes for variables
     */
    public static function set_product_attributes_for_variation($product_id, $data_api)
    {
        $product = wc_get_product($product_id);

        $ms_attributes = [];
        foreach ($data_api['characteristics'] as $key => $characteristic) {

            $attribute_label = $characteristic["name"];

            $ms_attributes[$attribute_label] = [
                'name'   => $characteristic["name"],
                'values' => [],
            ];
        }

        $values = array();
        foreach ($data_api['characteristics'] as $key => $characteristic) {
            $attribute_label = $characteristic["name"];

            if ($attribute_taxonomy_id = self::get_attribute_id_by_label($characteristic['name'])) {
                $taxonomy_name  = wc_attribute_taxonomy_name_by_id((int)$attribute_taxonomy_id);
                $current_values = $product->get_attribute($taxonomy_name);

                if ($current_values) {
                    $current_values = explode(', ', $current_values);
                    $current_values = array_map('trim', $current_values);
                }

            } else {
                $current_values = $product->get_attribute($characteristic['name']);
                $current_values = explode(' | ', $current_values);
            }

            if (empty($current_values)) {
                $values[] = $characteristic['value'];
            } else {
                $values   = $current_values;
                $values[] = $characteristic['value'];
            }

            $values                                    = apply_filters('wooms_product_attribute_save_values', $values,
                $product, $characteristic);
            $ms_attributes[$attribute_label]['values'] = $values;
        }

        /**
         * check unique for values
         */
        foreach ($ms_attributes as $key => $value) {
            $ms_attributes[$key]['values'] = array_unique($value['values']);
        }

        $attributes = $product->get_attributes('edit');

        if (empty($attributes)) {
            $attributes = array();
        }

        foreach ($ms_attributes as $key => $value) {
            $attribute_taxonomy_id = self::get_attribute_id_by_label($value['name']);
            $attribute_slug        = sanitize_title($value['name']);

            if (empty($attribute_taxonomy_id)) {
                $attribute_object = new \WC_Product_Attribute();
                $attribute_object->set_name($value['name']);
                $attribute_object->set_options($value['values']);
                $attribute_object->set_position(0);
                $attribute_object->set_visible(0);
                $attribute_object->set_variation(1);
                $attributes[$attribute_slug] = $attribute_object;

            } else {
                //Очищаем индивидуальный атрибут с таким именем если есть
                if (isset($attributes[$attribute_slug])) {
                    unset($attributes[$attribute_slug]);
                }
                $taxonomy_name    = wc_attribute_taxonomy_name_by_id((int)$attribute_taxonomy_id);
                $attribute_object = new \WC_Product_Attribute();
                $attribute_object->set_id($attribute_taxonomy_id);
                $attribute_object->set_name($taxonomy_name);
                $attribute_object->set_options($value['values']);
                $attribute_object->set_position(0);
                $attribute_object->set_visible(0);
                $attribute_object->set_variation(1);
                $attributes[$taxonomy_name] = $attribute_object;
            }
        }

        $attributes = apply_filters('wooms_product_attributes', $attributes, $data_api, $product);

        $product->set_attributes($attributes);

        $product->save();

        do_action('wooms_logger',
            __CLASS__,
            sprintf(
                'Сохранены атрибуты для продукта: %s (%s)',
                $product->get_name(),
                $product_id
            ),
            wc_print_r($attributes, true)
        );
    }


    /**
     * Set attributes and value for variation
     *
     * @param $variation_id
     * @param $characteristics
     */
    public static function save_attributes_for_variation(\WC_Product_Variation $variation, $data_api, $product_id)
    {
        $variant_data = $data_api;

        $variation_id = $variation->get_id();
        $parent_id    = $variation->get_parent_id();

        $characteristics = $variant_data['characteristics'];

        $attributes = array();

        foreach ($characteristics as $key => $characteristic) {
            $attribute_label = $characteristic["name"];
            $attribute_slug  = sanitize_title($attribute_label);

            if ($attribute_taxonomy_id = self::get_attribute_id_by_label($attribute_label)) {
                $taxonomy_name = wc_attribute_taxonomy_name_by_id($attribute_taxonomy_id);
                if (isset($attributes[$attribute_slug])) {
                    unset($attributes[$attribute_slug]);
                }

                $attribute_value = $characteristic['value'];

                $term = get_term_by('name', $attribute_value, $taxonomy_name);

                if ($term && ! is_wp_error($term)) {
                    $attribute_value = $term->slug;
                } else {
                    $attribute_value = sanitize_title($attribute_value);
                }

                $attributes[$taxonomy_name] = $attribute_value;

            } else {
                $attributes[$attribute_slug] = $characteristic['value'];
            }

        }

        $attributes = apply_filters('wooms_variation_attributes', $attributes, $data_api, $variation);

        $variation->set_attributes($attributes);

        do_action('wooms_logger',
            __CLASS__,
            sprintf('Сохранены атрибуты для вариации %s (продукт: %s)', $variation_id, $product_id),
            wc_print_r($attributes, true)
        );

        return $variation;
    }


    /**
     * Installation of variations for variable product
     */
    public static function load_data_variant($variant)
    {
        if ( ! empty($variant['archived'])) {
            return;
        }

        $product_href = $variant['product']['meta']['href'];
        $product_id   = self::get_product_id_by_uuid($product_href);

        if (empty($product_id)) {

            do_action('wooms_logger_error',
                __CLASS__,
                sprintf('Ошибка получения product_id для url %s', $product_href)
            );

            return;
        }

        self::update_variant_for_product($product_id, $variant);

        /**
         * deprecated
         */
        do_action('wooms_product_variant', $product_id, $variant);
    }


    /**
     * Get product variant ID
     *
     * @param $uuid
     */
    public static function get_product_id_by_uuid($uuid)
    {
        if (strpos($uuid, 'http') !== false) {
            $uuid = str_replace('https://online.moysklad.ru/api/remap/1.1/entity/product/', '', $uuid);
        }

        $posts = get_posts('post_type=product&meta_key=wooms_id&meta_value=' . $uuid);
        if (empty($posts[0]->ID)) {
            return false;
        }

        return $posts[0]->ID;
    }


    /**
     * Update and add variables from product
     *
     * @param $product_id
     * @param $value
     */
    public static function update_variant_for_product($product_id, $data_api)
    {
        $variant_data = $data_api;
        if (empty($data_api)) {
            return;
        }

        //добавление атрибутов к основному продукту с пометкой для вариаций
        self::set_product_attributes_for_variation($product_id, $variant_data);

        if ( ! $variation_id = self::get_variation_by_wooms_id($product_id, $variant_data['id'])) {
            $variation_id = self::add_variation($product_id, $variant_data);
        }

        $variation = wc_get_product($variation_id);
        $variation->set_name($variant_data['name']);

        $variation->set_stock_status('instock');

        if ( ! empty($variant_data["salePrices"][0]['value'])) {
            $price = $variant_data["salePrices"][0]['value'];
        } else {
            $price = 0;
        }

        $price = apply_filters('wooms_product_price', $price, $data_api, $variation_id);

        $price = $price / 100;
        $variation->set_price($price);
        $variation->set_regular_price($price);

        do_action('wooms_logger',
            __CLASS__,
            sprintf('Цена %s сохранена (для вариации %s продукта %s)', $price, $variation_id, $product_id)
        );

        $product_parent = wc_get_product($product_id);
        if ( ! $product_parent->is_type('variable')) {
            $product_parent = new \WC_Product_Variable($product_parent);
            $product_parent->save();

            do_action('wooms_logger_error',
                __CLASS__,
                sprintf('Снова сохранили продукт как вариативный %s', $product_id)
            );
        }

        /**
         * deprecated
         */
        $variation = apply_filters('wooms_save_variation', $variation, $variant_data, $product_id);

        $variation = apply_filters('wooms_variation_save', $variation, $variant_data, $product_id);

        if ($session_id = get_option('wooms_session_id')) {
            $variation->update_meta_data('wooms_session_id', $session_id);
        }

        $variation->save();

        do_action('wooms_logger',
            __CLASS__,
            sprintf(
                'Сохранена вариация: %s (%s), для продукта %s (%s)',
                $variation->get_name(),
                $variation_id,
                $product_parent->get_name(),
                $product_id
            )
        );

        do_action('wooms_variation_id', $variation_id, $variant_data);
    }


    /**
     * Get product parent ID
     */
    public static function get_variation_by_wooms_id($parent_id, $id)
    {
        $posts = get_posts(array(
            'post_type'   => 'product_variation',
            'post_parent' => $parent_id,
            'meta_key'    => 'wooms_id',
            'meta_value'  => $id,
        ));

        if (empty($posts)) {
            return false;
        }

        return $posts[0]->ID;
    }


    /**
     * Add variables from product
     */
    public static function add_variation($product_id, $value)
    {
        $variation = new \WC_Product_Variation();
        $variation->set_parent_id(absint($product_id));
        $variation->set_status('publish');
        $variation->set_stock_status('instock');
        $r = $variation->save();

        $variation_id = $variation->get_id();
        if (empty($variation_id)) {
            return false;
        }

        update_post_meta($variation_id, 'wooms_id', $value['id']);

        do_action('wooms_add_variation', $variation_id, $product_id, $value);

        return $variation_id;
    }


    /**
     * Start import manually
     */
    public static function start_manually()
    {
        delete_transient(self::$state_transient_key);
        self::add_schedule_hook();
        wp_redirect(admin_url('admin.php?page=moysklad'));
    }


    /**
     * Check for stopping imports from MoySklad
     */
    public static function check_stop_manual()
    {
        if (get_transient('wooms_variant_walker_stop')) {
            delete_transient('wooms_variant_start_timestamp');
            delete_transient('wooms_variant_walker_stop');

            return true;
        } else {
            return false;
        }
    }


    /**
     * Stopping walker imports from MoySklad
     */
    public static function walker_finish()
    {
        self::set_state('end_timestamp', time());
        self::set_state('lock', 0);

        do_action('wooms_wakler_variations_finish');

        do_action('wooms_logger',
            __CLASS__,
            'Вариации. Обработчик финишировал'
        );

        return true;
    }

    /**
     * Stop import manually
     */
    public static function stop_manually()
    {
        as_unschedule_all_actions(self::$walker_hook_name);

        self::walker_finish();

        wp_redirect(admin_url('admin.php?page=moysklad'));
    }

    /**
     * Get attribute id by label
     * or false
     */
    public static function get_attribute_id_by_label($label = '')
    {
        if (empty($label)) {
            return false;
        }

        $attr_taxonomies = wc_get_attribute_taxonomies();
        if (empty($attr_taxonomies)) {
            return false;
        }

        if ( ! is_array($attr_taxonomies)) {
            return false;
        }

        foreach ($attr_taxonomies as $attr) {
            if ($attr->attribute_label == $label) {
                return (int)$attr->attribute_id;
            }
        }

        return false;
    }


    public static function is_wait() {

        //check finish main walker
        if (as_next_scheduled_action('wooms_products_walker_batch', null, 'WooMS')) {
            return true;
        }

        if( ! empty(self::get_state('end_timestamp')) ){
            return true;
        }

        return false;

    }


    /**
     * Add schedule hook
     */
    public static function add_schedule_hook($force = false)
    {
        if ( ! self::is_enable() ) {
            return;
        }

        if( self::is_wait() ){
            return;
        }

        if (as_next_scheduled_action(self::$walker_hook_name, null, 'WooMS')) {
            return;
        }

        if(self::get_state('lock')){
            return;
        }

        if($force){
            self::set_state('force', 1);
        } else {
            self::set_state('force', 0);
        }

        // Adding schedule hook
        as_schedule_single_action(time() + 5, self::$walker_hook_name, self::get_state(), 'WooMS');
    }


    /**
     * Can cron start
     *
     * @return bool
     */
    public static function can_schedule_start()
    {

        /**
         * if manual start - we can start
         */
        if ( ! empty(get_transient('wooms_variant_manual_sync'))) {
            return true;
        }

        /**
         * If general walker finished and set the tag for manual start - we can started
         */
        if (get_transient('wooms_variations_sync_for_manual_start') && get_transient('wooms_end_timestamp')) {
            return true;
        }

        if (empty(get_option('woomss_walker_cron_enabled'))) {
            return false;
        }

        if (empty(get_option('woomss_variations_sync_enabled'))) {
            return false;
        }

        /**
         * Если не завершен обмен по базовым товарам - то вариации не должны работать
         */
        if (empty(get_transient('wooms_end_timestamp'))) {
            return false;
        }

        if ($end_stamp = get_transient('wooms_variant_end_timestamp')) {

            $interval_hours = get_option('woomss_walker_cron_timer');
            $interval_hours = (int)$interval_hours;
            if (empty($interval_hours)) {
                return false;
            }
            $now        = new \DateTime();
            $end_stamp  = new \DateTime($end_stamp);
            $end_stamp  = $now->diff($end_stamp);
            $diff_hours = $end_stamp->format('%h');
            if ($diff_hours > $interval_hours) {
                return true;
            } else {
                return false;
            }
        } else {
            return true;
        }
    }

    /**
     * Checking for variable product
     *
     * @return bool
     */
    public static function check_availability_of_variations()
    {

        $variants = get_posts(array(
            'post_type'   => 'product',
            'numberposts' => 10,
            'fields'      => 'ids',
            'tax_query'   => array(
                'relation' => 'AND',
                array(
                    'taxonomy' => 'product_type',
                    'terms'    => 'variable',
                    'field'    => 'slug',
                ),
            ),
            'meta_query'  => array(
                array(
                    'key'     => 'wooms_id',
                    'compare' => 'EXISTS',
                ),
            ),
        ));

        $timestamp = get_transient('wooms_end_timestamp');
        if (empty($variants) && (empty($timestamp) || ! isset($timestamp))) {
            return false;
        } else {
            return true;
        }
    }

    /**
     * Stopping the import of variational goods during verification
     */
    public static function stop_manually_to_check()
    {
        set_transient('wooms_variant_walker_stop', 1, 60 * 60);
        delete_transient('wooms_variant_start_timestamp');
        delete_transient('wooms_variant_end_timestamp');
        delete_transient('wooms_variant_manual_sync');
    }


    /**
     * Manual start variations
     */
    public static function render_ui()
    {
        if ( ! self::is_enable() ) {
            return;
        }

        echo '<h2>Вариации (Модификации)</h2>';

        echo "<p>Нажмите на кнопку ниже, чтобы запустить синхронизацию данных о вариативных товарах вручную</p>";
        if (as_next_scheduled_action(self::$walker_hook_name, null, 'WooMS') ) {
            printf('<a href="%s" class="button button-secondary">Остановить синхронизацию вариативных продуктов</a>',
            add_query_arg('a', 'wooms_import_variations_manual_stop', admin_url('admin.php?page=moysklad')));
        } else {
            printf('<a href="%s" class="button button-primary">Запустить синхронизацию вариативных продуктов</a>',
                add_query_arg('a', 'wooms_import_variations_manual_start', admin_url('admin.php?page=moysklad')));
        }

        self::display_state();

    }


    /**
     * display_state
     */
    public static function display_state()
    {

        $strings = [];

        if (as_next_scheduled_action(self::$walker_hook_name, null, 'WooMS') ) {
          $strings[] = sprintf('<strong>Статус:</strong> %s', 'Выполняется очередями в фоне');
        }
    
        if (self::is_wait()) {
          $strings[] = sprintf('<strong>Статус:</strong> %s', 'в ожидании');
        }
    
        if($end_timestamp = self::get_state('end_timestamp')){
          $end_timestamp = date('Y-m-d H:i:s', $end_timestamp);
          $strings[] = sprintf('Последняя успешная синхронизация (отметка времени UTC): %s', $end_timestamp);
        }
    
        $strings[] = sprintf('Очередь задач: <a href="%s">открыть</a>', admin_url('admin.php?page=wc-status&tab=action-scheduler&s=wooms_variables_walker_batch&orderby=schedule&order=desc'));
        
        
        if(defined('WC_LOG_HANDLER') && 'WC_Log_Handler_DB' == WC_LOG_HANDLER){
          $strings[] = sprintf('Журнал обработки: <a href="%s">открыть</a>', admin_url('admin.php?page=wc-status&tab=logs&source=wooms-WooMS-ProductVariable'));
        } else {
          $strings[] = sprintf('Журнал обработки: <a href="%s">открыть</a>', admin_url('admin.php?page=wc-status&tab=logs'));
        }

        $strings[] = sprintf('Количество обработанных записей: %s', empty(self::get_state('count')) ? 0 : self::get_state('count') );

        ?>
        <div class="wrap">
            <div id="message" class="notice notice-warning">
                <?php
                foreach($strings as $string){
                    printf('<p>%s</p>', $string);
                } 
                ?>
            </div>
        </div>
        <?php
    }


    /**
     * Settings import variations
     */
    public static function settings_init()
    {
        $option_name = 'woomss_variations_sync_enabled';
        register_setting('mss-settings', $option_name);
        add_settings_field(
            $id = $option_name,
            $title = 'Включить синхронизацию вариаций',
            $callback = function($args)
            {
                printf('<input type="checkbox" name="%s" value="1" %s />', $args['name'], checked(1, $args['value'], false));
                printf('<p><strong>%s</strong></p>', 'Тестовый режим. Не включайте эту функцию на реальном сайте, пока не проверите ее на тестовой копии сайта.');
            },
            $page = 'mss-settings',
            $section = 'woomss_section_other',
            $args = [
                'name' => $option_name,
                'value' => get_option($option_name),
            ]
        );
    }


    /**
     * Получаем данные таксономии по id глобального артибута
     */
    public static function get_attribute_taxonomy_by_id($id = 0)
    {

        if (empty($id)) {
            return false;
        }

        $taxonomy             = null;
        $attribute_taxonomies = wc_get_attribute_taxonomies();

        foreach ($attribute_taxonomies as $key => $tax) {
            if ($id == $tax->attribute_id) {
                $taxonomy       = $tax;
                $taxonomy->slug = 'pa_' . $tax->attribute_name;

                break;
            }
        }

        return $taxonomy;
    }


    /**
     * checking is enable
     */
    public static function is_enable(){
        if (empty(get_option('woomss_variations_sync_enabled'))) {
            return false;
        }

        return true;
    }

    /**
     * Update product from source data
     */
    public static function update_product($product, $data_api)
    {
        $item = $data_api;

        if ( ! self::is_enable() ) {
            if ($product->is_type('variable')) {
                $product = new \WC_Product_Simple($product);
            }

            return $product;
        }

        if (empty($item['modificationsCount'])) {
            if ($product->is_type('variable')) {
                $product = new \WC_Product_Simple($product);
            }

            return $product;
        }

        $product_id = $product->get_id();

        if ( ! $product->is_type('variable')) {
            $product = new \WC_Product_Variable($product);

            do_action('wooms_logger',
                __CLASS__,
                sprintf('Продукт изменен как вариативный %s', $product_id)
            );
        }

        return $product;
    }



    /**
     * get state data
     */
    public static function get_state($key = '')
    {
        if( ! $state = get_transient(self::$state_transient_key)){
        $state = [];
        set_transient(self::$state_transient_key, $state);
        }

        if(empty($key)){
        return $state;
        }

        if(empty($state[$key])){
        return null;
        }

        return $state[$key];
        
    }

    /**
     * set state data
     */
    public static function set_state($key, $value){

        if( ! $state = get_transient(self::$state_transient_key)){
        $state = [];
        }

        if(is_array($state)){
        $state[$key] = $value;
        } else {
        $state = [];
        $state[$key] = $value;
        }

        set_transient(self::$state_transient_key, $state);
    }
}

ProductVariable::init();
