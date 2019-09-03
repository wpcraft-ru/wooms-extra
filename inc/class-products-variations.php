<?php

namespace WooMS\Products;

// Exit if accessed directly
defined('ABSPATH') || exit;

/**
 * Import variants from MoySklad
 */
class Variations
{

    /**
     * tag for cron detected
     */
    public static $is_cron = false;

    /**
     * The init
     */
    public static function init()
    {
        add_action('wooms_product_save', array(__CLASS__, 'update_product'), 20, 3);

        // Cron
        add_action('init', array(__CLASS__, 'add_cron_hook'));
        add_action('wooms_cron_variation_walker', array(__CLASS__, 'walker_starter_by_cron'));

        add_filter('wooms_save_variation', array(__CLASS__, 'save_attributes_for_variation'), 10, 3);
        add_action('wooms_products_variations_item', array(__CLASS__, 'load_data_variant'), 15);

        //Other
        add_action('admin_init', array(__CLASS__, 'settings_init'), 150);
        add_action('woomss_tool_actions_btns', array(__CLASS__, 'ui_for_manual_start'), 15);
        add_action('woomss_tool_actions_wooms_import_variations_manual_start', array(__CLASS__, 'start_manually'));
        add_action('woomss_tool_actions_wooms_import_variations_manual_stop', array(__CLASS__, 'stop_manually'));
        add_action('wooms_variants_display_state', array(__CLASS__, 'display_state'));
        add_action('wooms_main_walker_finish', array(__CLASS__, 'reset_after_main_walker_finish'));
        add_action('wooms_products_sync_manual_start', [__CLASS__, 'set_tag_for_manual_start']);

    }

    /**
     * Set tag for start sync after manual start the main walker
     */
    public static function set_tag_for_manual_start()
    {
        set_transient('wooms_variations_sync_for_manual_start', 1);
    }

    /**
     * reset_after_main_walker_finish
     */
    public static function reset_after_main_walker_finish()
    {
        delete_transient('wooms_variant_start_timestamp');
        delete_transient('wooms_variant_offset');
        delete_transient('wooms_variant_end_timestamp');
        delete_transient('wooms_variant_walker_stop');
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
        delete_transient('wooms_variant_start_timestamp');
        delete_transient('wooms_variant_offset');
        delete_transient('wooms_variant_end_timestamp');
        delete_transient('wooms_variant_walker_stop');
        delete_transient('wooms_variations_sync_for_manual_start');
        set_transient('wooms_variant_manual_sync', 1);
        self::walker();
        wp_redirect(admin_url('admin.php?page=moysklad'));
    }

    /**
     * Walker for data variant product from MoySklad
     */
    public static function walker()
    {

        //Check stop tag and break the walker
        if (self::check_stop_manual()) {
            return false;
        }

        $count = apply_filters('wooms_variant_iteration_size', 30);
        if ( ! $offset = get_transient('wooms_variant_offset')) {
            $offset = 0;
            set_transient('wooms_variant_offset', $offset);
            delete_transient('wooms_count_variant_stat');
        }

        $ms_api_args = array(
            'offset' => $offset,
            'limit'  => $count,
            'scope'  => 'variant',
        );

        $url = 'https://online.moysklad.ru/api/remap/1.1/entity/assortment';

        $url = add_query_arg($ms_api_args, $url);

        $url = apply_filters('wooms_url_get_variants', $url);

        try {

            delete_transient('wooms_variant_end_timestamp');
            set_transient('wooms_variant_start_timestamp', time());
            $data = wooms_request($url);

            do_action('wooms_logger',
                __CLASS__,
                sprintf('Отправлен запрос на вариации: %s', $url)
            );

            //Check for errors and send message to UI
            if (isset($data['errors'])) {
                $error_code = $data['errors'][0]["code"];
                if ($error_code == 1056) {
                    $msg = sprintf('Ошибка проверки имени и пароля. Код %s, исправьте в <a href="%s">настройках</a>',
                        $error_code, admin_url('options-general.php?page=mss-settings'));
                    throw new \Exception($msg);
                } else {
                    throw new \Exception($error_code . ': ' . $data['errors'][0]["error"]);
                }
            }

            //If no rows, that send 'end' and stop walker
            if (isset($data['rows']) && empty($data['rows'])) {
                self::walker_finish();

                return true;
            }

            if (empty($data['rows'])) {
                do_action('wooms_logger_error', __CLASS__,
                    'Ошибка - пустой data row',
                    print_r($data, true)
                );
            }

            $i = 0;
            foreach ($data['rows'] as $key => $item) {
                $i++;

                if ($item["meta"]["type"] != 'variant') {
                    continue;
                }

                do_action('wooms_products_variations_item', $item);

                /**
                 * deprecated
                 */
                do_action('wooms_product_variant_import_row', $item, $key, $data);
            }

            if ($count_saved = get_transient('wooms_count_variant_stat')) {
                set_transient('wooms_count_variant_stat', $i + $count_saved);
            } else {
                set_transient('wooms_count_variant_stat', $i);
            }

            set_transient('wooms_variant_offset', $offset + $i);

            return;
        } catch (Exception $e) {
            delete_transient('wooms_variant_start_timestamp');
            do_action('wooms_logger_error', __CLASS__,
                $e->getMessage()
            );
        }
    }

    /**
     * Check for stopping imports from MoySklad
     */
    public static function check_stop_manual()
    {
        if (get_transient('wooms_variant_walker_stop')) {
            delete_transient('wooms_variant_start_timestamp');
            delete_transient('wooms_variant_offset');
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
        delete_transient('wooms_variant_start_timestamp');
        delete_transient('wooms_variant_offset');
        delete_transient('wooms_variant_manual_sync');
        delete_transient('wooms_variations_sync_for_manual_start');

        //Отключаем обработчик или ставим на паузу
        if (empty(get_option('woomss_walker_cron_enabled'))) {
            $timer = 0;
        } else {
            $timer = 60 * 60 * intval(get_option('woomss_walker_cron_timer', 24));
        }

        set_transient('wooms_variant_end_timestamp', date("Y-m-d H:i:s"), $timer);

        do_action('wooms_wakler_variations_finish');

        do_action('wooms_logger',
            __CLASS__,
            'Обработчик вариаций финишировал',
            sprintf('Поставлена пауза в секундах: %s', PHP_EOL . print_r($timer, true))
        );

        return true;
    }

    /**
     * Stop import manually
     */
    public static function stop_manually()
    {
        set_transient('wooms_variant_walker_stop', 1, 60 * 60);
        delete_transient('wooms_variant_start_timestamp');
        delete_transient('wooms_variant_offset');
        delete_transient('wooms_variant_end_timestamp');
        delete_transient('wooms_variant_manual_sync');
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

    /**
     * add_cron_hook
     */
    public static function add_cron_hook()
    {
        if (empty(get_option('woomss_variations_sync_enabled'))) {
            return;
        }

        if ( ! wp_next_scheduled('wooms_cron_variation_walker')) {
            wp_schedule_event(time(), 'wooms_cron_walker_shedule', 'wooms_cron_variation_walker');
        }

    }

    /**
     * Starting walker from cron
     */
    public static function walker_starter_by_cron()
    {

        self::$is_cron = true;

        if (self::can_cron_start()) {
            self::walker();
        }
    }

    /**
     * Can cron start
     *
     * @return bool
     */
    public static function can_cron_start()
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
        delete_transient('wooms_variant_offset');
        delete_transient('wooms_variant_end_timestamp');
        delete_transient('wooms_variant_manual_sync');
    }

    /**
     * Manual start variations
     */
    public static function ui_for_manual_start()
    {
        if (empty(get_option('woomss_variations_sync_enabled'))) {
            return;
        }

        echo '<h2>Вариации (Модификации)</h2>';

        do_action('wooms_variants_display_state');

        if (empty(get_transient('wooms_variant_start_timestamp'))) {
            echo "<p>Нажмите на кнопку ниже, чтобы запустить синхронизацию данных о вариативных товарах вручную</p>";
            echo "<p><strong>Внимание!</strong> Синхронизацию вариативных товаров необходимо поводить <strong>после</strong> общей синхронизации товаров</p>";
            if (empty(get_transient('wooms_start_timestamp'))) {
                printf('<a href="%s" class="button button-primary">Выполнить</a>',
                    add_query_arg('a', 'wooms_import_variations_manual_start', admin_url('admin.php?page=moysklad')));
            } else {
                printf('<span href="%s" class="button button-secondary" style="display:inline-block">Выполнить</span>',
                    add_query_arg('a', 'wooms_import_variations_manual_start', admin_url('admin.php?page=moysklad')));
            }

        } else {
            printf('<a href="%s" class="button button-secondary">Остановить</a>',
                add_query_arg('a', 'wooms_import_variations_manual_stop', admin_url('admin.php?page=moysklad')));
        }
    }

    /**
     * Settings import variations
     */
    public static function settings_init()
    {
        register_setting('mss-settings', 'woomss_variations_sync_enabled');
        add_settings_field(
            $id = 'woomss_variations_sync_enabled',
            $title = 'Включить синхронизацию вариаций',
            $callback = array(__CLASS__, 'display_variations_sync_enabled'),
            $page = 'mss-settings',
            $section = 'woomss_section_other'
        );
    }

    /**
     * Option import variations
     */
    public static function display_variations_sync_enabled()
    {
        $option = 'woomss_variations_sync_enabled';
        printf('<input type="checkbox" name="%s" value="1" %s />', $option, checked(1, get_option($option), false));
        ?>
        <p><strong>Тестовый режим. Не включайте эту функцию на реальном сайте, пока не проверите ее на тестовой копии
                сайта.</strong></p>
        <?php
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
     * Update product from source data
     */
    public static function update_product($product, $item, $data)
    {
        if (empty(get_option('woomss_variations_sync_enabled'))) {
            if ($product->is_type('variable')) {
                $product = new \WC_Product($product);
            }

            return $product;
        }

        if (empty($item['modificationsCount'])) {
            if ($product->is_type('variable')) {
                $product = new \WC_Product($product);
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
     * display_state
     */
    public static function display_state()
    {
        $time_stamp  = get_transient('wooms_variant_start_timestamp');
        $diff_sec    = time() - $time_stamp;
        $time_string = date('Y-m-d H:i:s', $time_stamp);

        $variation_count = get_transient('wooms_count_variant_stat');
        if (empty($variation_count)) {
            $variation_count = 'в процессе';
        }

        $state = '<strong>Выполняется</strong>';

        $finish_timestamp = get_transient('wooms_variant_end_timestamp');
        if (empty($finish_timestamp)) {
            $finish_timestamp = '';
        } else {
            $state = 'Выполнено';
        }

        if (empty(get_transient('wooms_end_timestamp'))) {
            $state = 'Работа заблокирована до окончания обмена по основным товарам';
        }

        $cron_on = get_option('woomss_walker_cron_enabled');

        ?>
        <div class="wrap">
            <div id="message" class="notice notice-warning">
                <p>Статус: <?= $state ?></p>
                <?php if ($finish_timestamp): ?>
                    <p>Последняя успешная синхронизация (отметка времени): <?= $finish_timestamp ?></p>
                <?php endif; ?>
                <p>Количество обработанных записей: <?= $variation_count ?></p>
                <?php if ( ! $cron_on): ?>
                    <p>Обмен по расписанию отключен</p>
                <?php endif; ?>
                <?php if ( ! empty($time_stamp)): ?>
                    <p>Отметка времени о последней итерации: <?= $time_string ?></p>
                    <p>Секунд прошло: <?= $diff_sec ?>.<br/> Следующая серия данных должна отправиться примерно через
                        минуту. Можно обновить страницу для проверки результатов работы.</p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}

Variations::init();
