<?php

/**
 * The public-facing functionality of the plugin.
 *
 * @link       https://woosmartcod.com/
 * @since      1.0.0
 *
 * @package    Wc_Smart_Cod
 * @subpackage Wc_Smart_Cod/public
 */

/**
 * The public-facing functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    Wc_Smart_Cod
 * @subpackage Wc_Smart_Cod/public
 * @author     FullStack <info@woosmartcod.com>
 */
class Wc_Smart_Cod_Public {

	/**
	 * The ID of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param      string    $plugin_name       The name of the plugin.
	 * @param      string    $version    The version of this plugin.
	 */

	private $has_cod_available;
	private $reason;

	public $cod_settings = array();
	public $restriction_settings = array();
	public $fee_settings = array();

	private $cart_products = false;
	private $settings_analyzed = false;
	private $cod_protection_checks = array();

	public function __construct( $plugin_name ) {

		$this->plugin_name  = $plugin_name;
		$this->version      = SMART_COD_VER;

		$this->cart_products     = false;
		$this->settings_analyzed = false;

		if ( is_admin() ) {
			return;
		}

		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'apply_smart_cod_settings' ) );
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'apply_smart_cod_fees' ) );
		add_action( 'woocommerce_update_order_review_fragments', array( $this, 'apply_custom_message' ) );

	}

	protected function analyze_settings() {

		$cod_settings         = $this->cod_settings;
		$restriction_settings = array_key_exists( 'restriction_settings', $cod_settings ) ? json_decode( $cod_settings['restriction_settings'], true ) : array();
		$fee_settings         = array_key_exists( 'fee_settings', $cod_settings ) ? json_decode( $cod_settings['fee_settings'], true ) : array();

		$restriction_modes = wp_parse_args(
			$restriction_settings,
			array(
				'role_restriction'                 => 0,
				'shipping_zone_restrictions'       => 0,
				'shipping_zone_method_restriction' => 0,
				'country_restrictions'             => 0,
				'state_restrictions'               => 0,
				'restrict_postals'                 => 0,
				'city_restrictions'                => 0,
				'product_restriction'              => 0,
				'category_restriction'             => 0,
				'shipping_class_restriction'       => 0,
			)
		);

		$restriction_settings = array(
			'includes' => array(),
			'excludes' => array(),
		);

		foreach ( $restriction_modes as $k => $v ) {

			if ( ! array_key_exists( $k, $cod_settings ) ) {
				unset( $restriction_modes[ $k ] );
				continue;
			}

			if ( $cod_settings[ $k ] === '' || ( is_array( $cod_settings[ $k ] ) && empty( $cod_settings[ $k ] ) ) ) {
				unset( $restriction_modes[ $k ] );
				continue;
			}

			$key = $v === 0 ? 'excludes' : 'includes';
			if ( $k === 'product_restriction' || $k === 'category_restriction' || $k === 'shipping_class_restriction' ) {
				$restriction_settings[ $key ][ $k ] = array(
					'value' => $cod_settings[ $k ],
					'mode'  => $cod_settings[ $k . '_mode' ],
				);
			} else {
				$restriction_settings[ $key ][ $k ] = $cod_settings[ $k ];
			}
		}

		if ( $this->has_native_zone_method() ) {
			// native wc setting
			// enabled ignore ours
			unset( $restriction_settings['shipping_zone_method_restriction'] );
		}

		$this->restriction_settings = $restriction_settings;
		$this->fee_settings         = $this->analyze_fee_settings( $fee_settings );

	}

	protected function analyze_fee_settings( $fee_settings ) {
		$fee_settings = is_array( $fee_settings ) ? $fee_settings : array();
		$fee_table = array();
		foreach ( array( 'method_different_charge_local_pickup' => 'check_method', 'extra_fee' => 'check_normal_fee' ) as $key => $condition ) {
			if ( ! isset( $this->cod_settings[ $key ] ) || ! is_numeric( $this->cod_settings[ $key ] ) ) {
				continue;
			}
			$fee_table[ $condition ][] = array(
				'fee'  => $this->cod_settings[ $key ],
				'type' => isset( $fee_settings[ $key ] ) && in_array( $fee_settings[ $key ], array( 'fixed', 'percentage' ), true ) ? $fee_settings[ $key ] : 'fixed',
				'key'  => $key,
			);
		}
		return $fee_table;

	}

	protected function has_native_zone_method() {
		return Wc_Smart_Cod::wc_version_check()
		&& isset( $this->cod_settings['enable_for_methods'] )
		&& ! empty( $this->cod_settings['enable_for_methods'] );
	}

	public function get_cod_message( $reason, $settings ) {

		if ( ! isset( $settings['cod_unavailable_message'] ) ) {
			return false;
		}

		$messages = $settings['cod_unavailable_message'];

		if ( ! is_array( $messages ) ) {
			// backwards compatibility
			// before 1.4.4
			if ( trim( $messages ) !== '' ) {
				return $messages;
			} else {
				return false;
			}
		}

		if ( ! $reason ) {
			if ( array_key_exists( 'generic', $messages ) && trim( $messages['generic'] ) !== '' ) {
				return $messages['generic'];
			}
		}

		if ( $reason === 'restrict_postals' ) {
			$reason = 'postal';
		} else {
			// extract _restriction
			$reason = substr( rtrim( $reason, 's' ), 0, -12 );
		}

		if ( ! array_key_exists( $reason, $messages ) || trim( $messages[ $reason ] ) === '' ) {
			if ( array_key_exists( 'generic', $messages ) && trim( $messages['generic'] ) !== '' ) {
				return $messages['generic'];
			}
		} else {
			return $messages[ $reason ];
		}

		return false;

	}

	public function init_wsc_settings() {

		if ( ! $this->settings_analyzed ) {
			$this->get_cod_settings();
			$this->analyze_settings();
			$this->settings_analyzed = true;
		}

	}

	public function apply_custom_message( $data ) {

		try {
			$this->init_wsc_settings();
			if ( $this->has_cod_available() === false ) {
	
				$settings = $this->get_cod_settings();
				$message  = $this->get_cod_message( $this->reason, $settings );
	
				if ( $message ) {
	
					$doc = new DOMDocument();
					$doc->loadHTML( mb_convert_encoding( $data['.woocommerce-checkout-payment'], 'HTML-ENTITIES', 'UTF-8' ) );
					$doc->preserveWhiteSpace = false;
					$payment_div             = $doc->getElementById( 'payment' );
					if ( $payment_div ) {
						$fragment = $doc->createDocumentFragment();
						$fragment->appendXML( '<div class="woocommerce-info cod-unavailable">' . $message . '</div>' );
						if ( $payment_div->hasChildNodes() ) {
							$first_element = $payment_div->childNodes->item( 0 );
							$first_element->parentNode->insertBefore( $fragment, $first_element );
						} else {
							$payment_div->appendChild( $fragment );
						}
	
						$doc->removeChild( $doc->doctype );
						$doc->replaceChild( $doc->firstChild->firstChild->firstChild, $doc->firstChild );
	
						$data['.woocommerce-checkout-payment'] = $doc->saveHTML();
					}
				}
			}
		}
		catch( Exception $e ) {
			$this->log_wsc_error( $e->getMessage() );
		}

		return $data;
	}

	private function log_wsc_error( $message ) {
		$logger = wc_get_logger();
		$context = array( 'source' => $this->plugin_name );
		$logger->error( $message, $context );
	}

	private function get_cod_settings() {

		if ( ! empty( $this->cod_settings ) ) {
			return $this->cod_settings;
		}

		return $this->cod_settings = get_option( 'woocommerce_cod_settings' );

	}

	private function is_new_wc() {
		return class_exists( 'WC_Shipping_Zones' );
	}

	private function get_customer_shipping_zone( $cart ) {

		$package = $cart->get_shipping_packages();

		if ( ! is_array( $package ) || ! isset( $package[0] ) ) {
			return false;
		}

		$package                = $package[0];
		$customer_shipping_zone = WC_Shipping_Zones::get_zone_matching_package( $package );

		return $customer_shipping_zone->get_id();

	}

	private function get_customer_shipping_method( $inside_zone = false, $full = false ) {

		global $woocommerce;

		$packages    = WC()->shipping->get_packages();
		$chosen_rate = false;
		// Read-only checkout selection; WooCommerce validates the checkout request nonce.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['shipping_method'] ) && is_array( $_POST['shipping_method'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$chosen_rate = array_map( 'sanitize_text_field', wp_unslash( $_POST['shipping_method'] ) );
		}

		if ( ! $chosen_rate ) {
			$chosen_rate = WC()->session->get( 'chosen_shipping_methods' );
		}

		if ( ! $chosen_rate ) {
			return false;
		}

		$chosen_rate = $chosen_rate[0];
		$id          = $this->get_method_id( $chosen_rate, $inside_zone, $packages );

		if ( $id === false ) {
			// check again for a possible
			// rate change with cached rate
			$chosen_rate = WC()->session->get( 'chosen_shipping_methods' );
			$chosen_rate = $chosen_rate[0];
			$id          = $this->get_method_id( $chosen_rate, $inside_zone, $packages );
		}

		return $full ? $chosen_rate : $id;

	}

	protected function get_method_id( $chosen_rate, $inside_zone, $packages ) {

		foreach ( $packages as $i => $package ) {
			if ( isset( $package['rates'][ $chosen_rate ] ) ) {

				if ( ! $inside_zone ) {
					return $package['rates'][ $chosen_rate ]->method_id;
				} else {
					return $package['rates'][ $chosen_rate ]->instance_id;
				}
			}
		}

		return false;
	}

	public function apply_smart_cod_fees( WC_Cart $cart, $apply_fee = true ) {

		try {
			
			if ( $apply_fee && ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) {
				return;
			}
	
			$this->init_wsc_settings();
	
			// Read-only checkout selection; WooCommerce validates the checkout request nonce.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$payment_gateway = isset( $_POST['payment_method'] ) && is_scalar( $_POST['payment_method'] ) && 'cod' === sanitize_key( wp_unslash( $_POST['payment_method'] ) ) ? 'cod' : '';
	
			if ( ! $payment_gateway ) {
	
				$payment_gateway = WC()->session->get( 'chosen_payment_method' );
	
				// WooCommerce issue
				// when it's only
				// one gateway
	
				if ( ! $payment_gateway ) {
	
					$available_gateways = WC()->payment_gateways->get_available_payment_gateways();
					if ( ! empty( $available_gateways ) && current( array_keys( $available_gateways ) ) === 'cod' ) {
						$payment_gateway = 'cod';
					}
				}
			}
	
			if ( ( $payment_gateway !== 'cod' || $this->has_cod_available() === false ) && $apply_fee ) {
				return;
			}
	
			global $woocommerce;
			$cart      = $woocommerce->cart;
			$settings  = $this->get_cod_settings();
			$rounding  = array_key_exists( 'percentage_rounding', $settings ) && in_array( $settings['percentage_rounding'], array( 'round_up', 'round_down' ) ) ? $settings['percentage_rounding'] : 'round_up';
			$has_tax   = false;
			if ( isset( $settings['extra_fee_tax'] ) && $settings['extra_fee_tax'] === 'enable' ) {
				$has_tax = true;
			}
			$extra_fee = 0;
	
			// check for restrictions and policies
	
			foreach ( $this->fee_settings as $condition => $group ) {
	
				foreach ( $group as $fee ) {
	
					if ( is_numeric( $extra_fee = $this->{$condition}( $fee, $cart ) ) ) {
						if ( $fee['type'] === 'percentage' ) {
							$extra_fee = $this->calculate_percentage( $extra_fee, $cart, $rounding );
						}
						break 2;
					}
				}
			}
	
			$extra_fee = apply_filters( 'wc_smart_cod_fee', is_numeric( $extra_fee ) ? $extra_fee : 0, $this->fee_settings );
			if ( $apply_fee && $extra_fee > 0 ) {
				// Keep WooCommerce's translated payment-method label for the fee.
				// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				$woocommerce->cart->add_fee( apply_filters( 'wc_smart_cod_fee_title', __( 'Cash on delivery', 'woocommerce' ) ), $extra_fee, $has_tax );
			} else {
				return $extra_fee;
			}
		}
		catch( Exception $e ) {
			$this->log_wsc_error( $e->getMessage() );
		}
	}

	protected function calculate_percentage( $percentage, $cart, $rounding ) {

		$total = $cart->total;

		if ( ! $total ) {
			$total = $cart->cart_contents_total;
		}

		$extra_fee = ( $percentage * $total ) / 100;
		return $rounding === 'round_up' ? ceil( $extra_fee ) : floor( $extra_fee );

	}

	protected function get_cart_products( $cart ) {

		if ( $this->cart_products ) {
			return $this->cart_products;
		}

		$items    = $cart->get_cart();
		$products = array();
		foreach ( $items as $key => $item ) {
			$id = isset( $item['variation_id'] ) && $item['variation_id'] !== 0 ? $item['variation_id'] : $item['product_id'];
			array_push( $products, $id );
		}
		return $this->cart_products = $products;
	}

	public function has_cod_available() {

		$has_cod_available = true;

		if ( $this->is_cod_protection_enabled() && $this->checkout_customer_has_prior_cod_issue() ) {
			$this->reason = 'cod_protection';
			return apply_filters( 'wc_smart_cod_available', false, $this->restriction_settings );
		}

		foreach ( $this->restriction_settings['includes'] as $key => $value ) {
			if ( ! method_exists( $this, 'check_' . $key ) ) {
				continue;
			}
			$has_cod_available = $this->{ 'check_' . $key }( $value, true, $has_cod_available );
			if ( ! $has_cod_available ) {
				$this->reason = $key;
				break;
			}
		}

		if ( $has_cod_available ) {

			foreach ( $this->restriction_settings['excludes'] as $key => $value ) {
				if ( ! method_exists( $this, 'check_' . $key ) ) {
					continue;
				}
				$has_cod_available = $this->{ 'check_' . $key }( $value, false, $has_cod_available );
				if ( ! $has_cod_available ) {
					$this->reason = $key;
					break;
				}
			}
		}

		return $this->has_cod_available = apply_filters( 'wc_smart_cod_available', $has_cod_available, $this->restriction_settings );

	}

	/** @return bool */
	private function is_cod_protection_enabled() {
		return isset( $this->cod_settings['enable_smart_cod_ai_data_sharing'] ) && 'yes' === $this->cod_settings['enable_smart_cod_ai_data_sharing'];
	}

	/**
	 * Requests one installation-scoped decision, then reuses it for the repeated
	 * AJAX gateway refreshes generated by the same checkout session.
	 *
	 * A service failure is cached for only one minute and always allows COD. A
	 * successful result is cached for fifteen minutes. No raw identity is placed
	 * in the WooCommerce session.
	 *
	 * @return bool
	 */
	private function checkout_customer_has_prior_cod_issue() {
		$identity = $this->checkout_identity();
		if ( '' === $identity['email'] && '' === $identity['phone'] ) {
			return false;
		}

		$fingerprint = hash( 'sha256', $identity['email'] . '|' . $identity['phone'] );
		if ( isset( $this->cod_protection_checks[ $fingerprint ] ) ) {
			return $this->cod_protection_checks[ $fingerprint ];
		}

		$session = function_exists( 'WC' ) && WC() ? WC()->session : null;
		if ( is_object( $session ) && method_exists( $session, 'get' ) ) {
			$cached = $session->get( 'wsc_cod_protection_check', array() );
			if ( is_array( $cached ) && isset( $cached['fingerprint'], $cached['expires'], $cached['disable_cod'] )
				&& hash_equals( $fingerprint, (string) $cached['fingerprint'] ) && absint( $cached['expires'] ) >= time() ) {
				$result = (bool) $cached['disable_cod'];
				$this->cod_protection_checks[ $fingerprint ] = $result;
				return $result;
			}
		}

		try {
			require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-wc-smart-cod-ai-outbox.php';
			$lookup = Wc_Smart_Cod_Ai_Outbox::lookup_own_shop_cod_risk( $identity['email'], $identity['phone'] );
		} catch ( Exception $exception ) {
			$lookup = null;
		}

		$result = is_array( $lookup ) && ! empty( $lookup['disable_cod'] );
		$ttl    = is_array( $lookup ) ? 15 * MINUTE_IN_SECONDS : MINUTE_IN_SECONDS;
		if ( is_object( $session ) && method_exists( $session, 'set' ) ) {
			$session->set(
				'wsc_cod_protection_check',
				array(
					'fingerprint' => $fingerprint,
					'disable_cod' => $result,
					'expires'     => time() + $ttl,
				)
			);
		}
		$this->cod_protection_checks[ $fingerprint ] = $result;
		return $result;
	}

	/**
	 * Reads the current checkout values without mutating checkout state. During
	 * update_order_review WooCommerce sends the fields as a serialized post_data
	 * value, so those values take precedence over the customer-session snapshot.
	 *
	 * @return array{email:string,phone:string}
	 */
	private function checkout_identity() {
		$email = '';
		$phone = '';
		$customer = function_exists( 'WC' ) && WC() ? WC()->customer : null;
		if ( is_object( $customer ) ) {
			$email = method_exists( $customer, 'get_billing_email' ) ? (string) $customer->get_billing_email() : '';
			$phone = method_exists( $customer, 'get_billing_phone' ) ? (string) $customer->get_billing_phone() : '';
		}

		$posted = array();
		// Read-only checkout fields; WooCommerce validates the surrounding checkout request.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['post_data'] ) && is_scalar( $_POST['post_data'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- URL-encoded values are sanitized individually below.
			parse_str( wp_unslash( (string) $_POST['post_data'] ), $posted );
		}
		// Block checkout and some payment flows submit the fields directly.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['billing_email'] ) && is_scalar( $_POST['billing_email'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$posted['billing_email'] = sanitize_email( wp_unslash( $_POST['billing_email'] ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['billing_phone'] ) && is_scalar( $_POST['billing_phone'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$posted['billing_phone'] = sanitize_text_field( wp_unslash( $_POST['billing_phone'] ) );
		}
		if ( isset( $posted['billing_email'] ) && is_scalar( $posted['billing_email'] ) ) {
			$email = (string) $posted['billing_email'];
		}
		if ( isset( $posted['billing_phone'] ) && is_scalar( $posted['billing_phone'] ) ) {
			$phone = (string) $posted['billing_phone'];
		}

		$email = strtolower( trim( sanitize_email( $email ) ) );
		if ( '' !== $email && ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			$email = '';
		}
		$phone = substr( trim( sanitize_text_field( $phone ) ), 0, 64 );
		return array( 'email' => $email, 'phone' => $phone );
	}

	/**
	 * Check cod availability
	 * begin
	 */

	protected function check_shipping_zone_restrictions( $restriction, $enable, $has_cod_available ) {

		global $woocommerce;
		$cart    = $woocommerce->cart;
		$package = $cart->get_shipping_packages();

		if ( ! is_array( $package ) || ! isset( $package[0] ) ) {
			return $has_cod_available;
		}

		$package                = $package[0];
		$customer_shipping_zone = WC_Shipping_Zones::get_zone_matching_package( $package );

		if ( ! $customer_shipping_zone ) {
			return $has_cod_available;
		}

		if ( $enable ) {
			if ( in_array( $customer_shipping_zone->get_id(), $restriction ) ) {
				return true;
			}
			return false;
		} else {
			if ( in_array( $customer_shipping_zone->get_id(), $restriction ) ) {
				return false;
			}
		}

		return $has_cod_available;

	}

	protected function check_shipping_zone_method_restriction( $restriction, $enable, $has_cod_available ) {

		global $woocommerce;
		$cart                          = $woocommerce->cart;
		$customer_shipping_zone        = $this->get_customer_shipping_zone( $cart );
		$customer_shipping_zone_method = $this->get_customer_shipping_method( true );

		if ( $customer_shipping_zone_method === false || $customer_shipping_zone === false ) {
			return $has_cod_available;
		}

		$needle = $customer_shipping_zone . '_' . $customer_shipping_zone_method;

		if ( $enable ) {
			if ( in_array( $needle, $restriction ) ) {
				return true;
			}
			return false;
		} else {
			if ( in_array( $needle, $restriction ) ) {
				return false;
			}
		}

		return $has_cod_available;
	}

	protected function check_restrict_postals( $restriction, $enable, $has_cod_available ) {

		global $woocommerce;
		$postals            = explode( ',', trim( $restriction ) );
		$postals            = array_map( 'trim', $postals );
		$customer_post_code = $woocommerce->customer->get_shipping_postcode();

		if ( ! $customer_post_code ) {
			return $has_cod_available;
		}

		foreach ( $postals as $p ) {
			if ( ! $p ) {
				continue;
			}
			$prepare = explode( '...', $p );
			$count   = count( $prepare );
			if ( $count === 1 ) {
				// single
				if ( $prepare[0] === $customer_post_code ) {
					return $enable ? true : false;
				}
			} elseif ( $count === 2 ) {
				// range
				if ( ! is_numeric( $prepare[0] ) || ! is_numeric( $prepare[1] ) || ! is_numeric( $customer_post_code ) ) {
					continue;
				}

				if ( $customer_post_code >= $prepare[0] && $customer_post_code <= $prepare[1] ) {
					return $enable ? true : false;
				}
			} else {
				continue;
			}
		}

		if ( $enable ) {
			return false;
		}

		return $has_cod_available;

	}

	protected function check_city_restrictions( $restriction, $enable, $has_cod_available ) {

		global $woocommerce;
		$customer_city = $woocommerce->customer->get_shipping_city();

		if ( ! $customer_city ) {
			return $has_cod_available;
		}

		$customer_city = trim( $customer_city );

		$restriction = explode( ',', trim( $restriction ) );
		$restriction = array_map( 'trim', $restriction );
		$restriction = array_map( 'strtolower', $restriction );

		if ( $enable ) {
			if ( in_array( strtolower( $customer_city ), $restriction ) ) {
				return true;
			}
			return false;
		} else {
			if ( in_array( strtolower( $customer_city ), $restriction ) ) {
				return false;
			}
		}

		return $has_cod_available;

	}

	protected function check_country_restrictions( $restriction, $enable, $has_cod_available ) {

		global $woocommerce;
		$customer_country = $woocommerce->customer->get_shipping_country();

		if ( ! $customer_country ) {
			return $has_cod_available;
		}

		if ( $enable ) {
			if ( in_array( $customer_country, $restriction ) ) {
				return true;
			}
			return false;
		} else {
			if ( in_array( $customer_country, $restriction ) ) {
				return false;
			}
		}

		return $has_cod_available;

	}

	protected function check_state_restrictions( $restriction, $enable, $has_cod_available ) {

		global $woocommerce;
		$customer_country = $woocommerce->customer->get_shipping_country();
		$customer_state   = $woocommerce->customer->get_shipping_state();
		$needle           = $customer_country . '_' . $customer_state;

		if ( ! $customer_country || ! $customer_state ) {
			return $has_cod_available;
		}

		if ( $enable ) {
			if ( in_array( $needle, $restriction ) ) {
				return true;
			}
			return false;
		} else {
			if ( in_array( $needle, $restriction ) ) {
				return false;
			}
		}

		return $has_cod_available;

	}

	protected function check_product_restriction( $restriction, $enable, $has_cod_available ) {

		global $woocommerce;
		$cart = $woocommerce->cart;

		$cart_products = $this->get_cart_products( $cart );

		if ( empty( $cart_products ) ) {
			return false;
		}

		$product_count  = count( $cart_products );
		$restrict_count = 0;

		foreach ( $cart_products as $product_id ) {

			if ( in_array( $product_id, $restriction['value'] ) ) {
				if ( $restriction['mode'] === 'one_product' ) {
					return $enable ? true : false;
				} else {
					$restrict_count++;
				}
			} else {
				if ( $restriction['mode'] === 'all_products' ) {
					return $enable ? false : true;
				}
			}
		}

		if ( $restriction['mode'] === 'all_products' ) {
			if ( $restrict_count === $product_count ) {
				return $enable ? true : false;
			} else {
				return $enable ? false : true;
			}
		} else {
			if ( $enable ) {
				return false;
			}
		}

		return $has_cod_available;

	}

	protected function check_category_restriction( $restriction, $enable, $has_cod_available ) {

		global $woocommerce;
		$cart = $woocommerce->cart;

		$cart_products = $this->get_cart_products( $cart );

		if ( empty( $cart_products ) ) {
			return false;
		}

		$product_count  = count( $cart_products );
		$restrict_count = 0;

		foreach ( $cart_products as $product_id ) {

			$_product = wc_get_product( $product_id );
			$type     = $_product->get_type();
			if ( $type === 'variation' ) {
				$_product = wc_get_product( $_product->get_parent_id() );
			}
			$category_ids = $_product->get_category_ids();

			if ( array_intersect( $category_ids, $restriction['value'] ) ) {
				if ( $restriction['mode'] === 'one_product' ) {
					return $enable ? true : false;
				} else {
					$restrict_count++;
				}
			} else {
				if ( $restriction['mode'] === 'all_products' ) {
					return $enable ? false : true;
				}
			}
		}

		if ( $restriction['mode'] === 'all_products' ) {
			if ( $restrict_count === $product_count ) {
				return $enable ? true : false;
			} else {
				return $enable ? false : true;
			}
		} else {
			if ( $enable ) {
				return false;
			}
		}

		return $has_cod_available;

	}

	protected function check_shipping_class_restriction( $restriction, $enable, $has_cod_available ) {

		global $woocommerce;
		$cart = $woocommerce->cart;

		$cart_products = $this->get_cart_products( $cart );

		if ( empty( $cart_products ) ) {
			return false;
		}

		$product_count  = count( $cart_products );
		$restrict_count = 0;

		foreach ( $cart_products as $product_id ) {
			$product           = wc_get_product( $product_id );
			$shipping_class_id = $product->get_shipping_class_id();
			if ( in_array( $shipping_class_id, $restriction['value'] ) ) {
				if ( $restriction['mode'] === 'one_product' ) {
					return $enable ? true : false;
				} else {
					$restrict_count++;
				}
			} else {
				if ( $restriction['mode'] === 'all_products' ) {
					return $enable ? false : true;
				}
			}
		}

		if ( $restriction['mode'] === 'all_products' ) {
			if ( $restrict_count === $product_count ) {
				return $enable ? true : false;
			} else {
				return $enable ? false : true;
			}
		} else {
			if ( $enable ) {
				return false;
			}
		}

		return $has_cod_available;

	}

	protected function check_user_role_restriction( $restriction, $enable, $has_cod_available ) {

		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();
		} else {
			$user        = new stdClass();
			$user->roles = array( 'guest' );
		}

		if ( $enable ) {
			if ( array_intersect( $restriction, $user->roles ) ) {
				return true;
			}
			return false;
		} else {
			if ( array_intersect( $restriction, $user->roles ) ) {
				return false;
			}
		}

		return $has_cod_available;
	}

	protected function check_method_restriction() {

		$settings = $this->get_cod_settings();

		if ( isset( $settings['enable_for_methods'] ) ) {
			if ( ! empty( $settings['enable_for_methods'] ) ) {

				if ( Wc_Smart_Cod::wc_version_check() ) {
					// over 3.4, now woocommerce
					// supports natively shipping
					// zone method restriction
					$method = $this->get_customer_shipping_method( true, true );

					if ( ! $method ) {
						return true;
					}
					if ( ! in_array( $method, $settings['enable_for_methods'] ) ) {
						return false;
					}
				} else {
					$method = $this->get_customer_shipping_method();
					if ( ! $method ) {
						return true;
					}
					if ( ! in_array( $method, $settings['enable_for_methods'] ) ) {
						return false;
					}
				}
			}
		}

		return true;

	}

	/**
	 * Check cod availability
	 * end
	 */

	public function apply_smart_cod_settings( $available_gateways ) {

		try {
			if ( ! function_exists( 'is_checkout' ) || ! is_checkout() && ! is_wc_endpoint_url( 'order-pay' ) ) {
				return $available_gateways;
			}
	
			$this->init_wsc_settings();
	
			if ( $this->has_cod_available() === false ) {
				unset( $available_gateways['cod'] );
			}
		}
		catch( Exception $e ) {
			$this->log_wsc_error( $e->getMessage() );
		}
		
		return $available_gateways;
	}

	/**
	 * Check extra fee
	 * start
	 */

	private function check_method( $settings, $cart ) {

		// check for specific shipping methods
		// different charges

		$extra_fee       = false;
		$key             = $settings['key'];
		$shipping_method = $this->get_customer_shipping_method();

		if ( ! $shipping_method ) {
			return $extra_fee;
		}

		if ( 'local_pickup' === $shipping_method && 'method_different_charge_local_pickup' === $key ) {
			$extra_fee = $settings['fee'];
		}

		return $extra_fee;

	}

	private function check_normal_fee( $settings, $cart ) {

		$extra_fee = 0;
		$key       = $settings['key'];

		if ( $key === 'extra_fee' ) {
			$extra_fee = $settings['fee'];
		}

		return $extra_fee;

	}

	/**
	 * Check extra fee
	 * end
	 */

	/**
	 * Register the JavaScript for the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_scripts() {

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in Wc_Smart_Cod_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The Wc_Smart_Cod_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/wc-smart-cod-public.js', array( 'jquery' ), $this->version, false );
		}

	}

}
