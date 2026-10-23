<?php
/**
 * Native Cart/Checkout Blocks adapter for Free. Classic hooks stay unchanged.
 * PHP 5.6 syntax for older Classic installs; Blocks requires WooCommerce 9.9+.
 */
class Wc_Smart_Cod_Blocks {
    const NS = 'wc-smart-cod';
    private static $store_api = false;
    private $engine;
    private $registered = false;

    public function __construct($engine) {
        if (!defined('WC_VERSION') || version_compare(WC_VERSION,'9.9','<')) return;
        $this->engine = $engine;
        add_filter('rest_request_before_callbacks', array($this, 'begin_request'), 5, 3);
        add_filter('rest_request_after_callbacks', array($this, 'end_request'), 999, 3);
        add_action('woocommerce_store_api_cart_update_customer_from_request', array($engine, 'reset_checkout_state'), 20);
        add_action('woocommerce_store_api_checkout_update_customer_from_request', array($this, 'prepare_checkout'), 20, 2);
        add_action('woocommerce_store_api_checkout_update_order_from_request', array($this, 'validate_checkout'), 20, 2);
        add_action('wp_enqueue_scripts', array($this, 'enqueue'));
        if (did_action('woocommerce_blocks_loaded')) $this->register();
        else add_action('woocommerce_blocks_loaded', array($this, 'register'));
    }

    public static function is_store_api_request() { return self::$store_api; }

    public function begin_request($response, $handler, $request) {
        self::$store_api = (bool) preg_match('#^/wc/store/v[0-9]+/(?:cart(?:/|$)|checkout(?:/|$))#', $request->get_route());
        if (self::$store_api) {
            $this->engine->reset_checkout_state();
        }
        return $response;
    }

    public function end_request($response, $handler, $request) {
        self::$store_api = false;
        return $response;
    }

    public static function is_block_page() {
        if (!defined('WC_VERSION') || version_compare(WC_VERSION,'9.9','<')) return false;
        $utils = '\\Automattic\\WooCommerce\\Blocks\\Utils\\CartCheckoutUtils';
        return class_exists($utils) && ($utils::is_checkout_block_default() || $utils::is_cart_block_default())
            && (is_checkout() || is_cart()) && !is_wc_endpoint_url('order-pay') && !is_order_received_page();
    }

    public function register() {
        if ($this->registered || !defined('WC_VERSION') || version_compare(WC_VERSION, '9.9', '<')
            || !function_exists('woocommerce_store_api_register_endpoint_data')) return;
        woocommerce_store_api_register_endpoint_data(array(
            'endpoint' => 'cart', 'namespace' => self::NS, 'data_callback' => array($this, 'cart_data'),
            'schema_callback' => array($this, 'schema'), 'schema_type' => ARRAY_A,
        ));
        woocommerce_store_api_register_update_callback(array('namespace' => self::NS, 'callback' => array($this, 'update')));
        $this->registered = true;
    }

    private function error($code, $message) {
        throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException($code, $message, 400);
    }

    private function payment($method) {
        $gateways = WC()->payment_gateways()->payment_gateways();
        if (!is_string($method) || strlen($method) > 100 || ('' !== $method && (!isset($gateways[$method]) || sanitize_key($method) !== $method))) {
            $this->error('wsc_invalid_payment', __('Choose an available payment method.', 'woocommerce'));
        }
        WC()->session->set('chosen_payment_method', $method);
    }

    /** Woo validates nonce/cart token and recalculates totals. No client prices or rules are accepted. */
    public function update($data) {
        if (!is_array($data) || !WC()->session || !WC()->customer) $this->error('wsc_invalid_cart', __('Please reload the page and try again.', 'woocommerce'));
        foreach (array('email'=>254, 'phone'=>64) as $field=>$limit) {
            if (isset($data[$field]) && (!is_string($data[$field]) || strlen($data[$field]) > $limit)) {
                $this->error('wsc_invalid_customer', __('Please check your details.', 'woocommerce'));
            }
        }
        $this->payment(isset($data['payment_method']) ? $data['payment_method'] : '');
        if (isset($data['email'])) WC()->customer->set_billing_email(is_email($data['email']) ? sanitize_email($data['email']) : '');
        if (isset($data['phone'])) WC()->customer->set_billing_phone(sanitize_text_field($data['phone']));
        if (array_key_exists('customer', $data)) $this->update_customer_preview($data['customer']);
        WC()->customer->save();
        $this->engine->reset_checkout_state();
    }

    /** Partial address preview only. Final checkout still uses Woo's full address validation. */
    private function update_customer_preview($data) {
        $invalid = function () { throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException('wsc_invalid_customer', __('Please check your details.', 'woocommerce'), 400); };
        if (!is_array($data) || array_diff(array_keys($data), array('billing_address','shipping_address'))) $invalid();
        $props = array(); $additional = array();
        foreach ($data as $group => $address) {
            if (!is_array($address) || count($address) > 64) $invalid();
            $prefix = $group === 'billing_address' ? 'billing' : 'shipping';
            foreach ($address as $field => $value) {
                $limits = array('country'=>2,'state'=>200,'city'=>200,'postcode'=>32);
                if (isset($limits[$field])) {
                    if (!is_string($value) || strlen($value) > $limits[$field]) $invalid();
                    $value = sanitize_text_field($value);
                    if ($field === 'country') {
                        $value = strtoupper($value);
                        if ($value !== '' && !isset(WC()->countries->get_countries()[$value])) $invalid();
                    }
                    $props[$prefix . '_' . $field] = $value;
                } elseif (is_string($field) && strpos($field, '/') !== false) {
                    if ((!is_string($value) && !is_bool($value)) || (is_string($value) && strlen($value) > 1000)) $invalid();
                    $fields = wc_get_container()->get('Automattic\\WooCommerce\\Blocks\\Domain\\Services\\CheckoutFields');
                    if (is_wp_error($fields->validate_field_for_location($field, $value, 'address'))) $invalid();
                    $additional[] = array($fields, $field, $fields->sanitize_field($field, $value), $prefix);
                } else $invalid(); // No names, account properties, prices or arbitrary customer meta.
            }
        }
        foreach (array('billing','shipping') as $prefix) {
            if (!array_key_exists($prefix . '_postcode', $props)) continue;
            $getter = 'get_' . $prefix . '_country';
            $country = isset($props[$prefix . '_country']) ? $props[$prefix . '_country'] : WC()->customer->$getter();
            $raw = $props[$prefix . '_postcode'];
            $props[$prefix . '_postcode'] = $raw === '' ? '' : wc_format_postcode($raw, $country);
        }
        if (method_exists(WC()->cart, 'needs_shipping') && !WC()->cart->needs_shipping() && !isset($data['shipping_address'])) {
            foreach (array('country','state','city','postcode') as $field) {
                if (array_key_exists('billing_' . $field, $props)) $props['shipping_' . $field] = $props['billing_' . $field];
            }
        }
        if (is_wp_error(WC()->customer->set_props($props))) $invalid();
        foreach ($additional as $field) $field[0]->persist_field_for_customer($field[1], $field[2], WC()->customer, $field[3]);
    }

    /** Submitted JSON is authoritative, even when the cart preview selected a different payment. */
    public function prepare_checkout($customer, $request) {
        $method = $request->get_param('payment_method');
        if (!is_string($method)) return;
        $this->payment($method);
        $this->engine->reset_checkout_state();
        WC()->cart->calculate_totals();
    }

    public function validate_checkout($order, $request) {
        if ($request->get_method() !== 'POST') return;
        $method = $order->get_payment_method();
        $gateways = WC()->payment_gateways()->get_available_payment_gateways();
        $data = $this->cart_data();
        if ($method === 'cod' && (!$data['cod_available'] || !isset($gateways['cod']))) {
            $this->error('wsc_cod_unavailable', __('Please choose a different payment method.', 'woocommerce'));
        }
        // Store API uses Woo's create_order_fee_lines, including the existing fee-item hook.
        if (method_exists($this->engine, 'enrich_order_meta')) {
            $this->engine->enrich_order_meta($order->get_id());
        }
    }

    public function cart_data() {
        $previous_scope = self::$store_api;
        self::$store_api = true;
        try {
        $this->engine->reset_checkout_state();
        $data = $this->engine->blocks_checkout_data();
        $gateways = WC()->payment_gateways()->get_available_payment_gateways();
        $data['cod_available'] = $data['cod_available'] && isset($gateways['cod']);
        return $data;
        } finally { self::$store_api = $previous_scope; }
    }

    public function schema() {
        return array(
            'cod_available'=>array('type'=>'boolean','readonly'=>true),
            'message'=>array('type'=>'string','readonly'=>true),
        );
    }

    public function enqueue() {
        if (!self::is_block_page() || !defined('WC_VERSION') || version_compare(WC_VERSION, '9.9', '<')) return;
        wp_enqueue_script('wc-smart-cod-blocks', plugin_dir_url(dirname(__FILE__)) . 'public/js/wc-smart-cod-blocks.js',
            array('wp-api-fetch','wp-data','wp-element','wp-plugins','wp-i18n','wc-blocks-checkout','wc-blocks-data-store','wc-blocks-registry'), SMART_COD_VER, true);
        wp_set_script_translations('wc-smart-cod-blocks', 'wc-smart-cod', dirname(__DIR__) . '/languages');
        wp_localize_script('wc-smart-cod-blocks', 'wscBlocksSettings', array(
            'storeApiNonce' => wp_create_nonce('wc_store_api'),
        ));
    }
}

