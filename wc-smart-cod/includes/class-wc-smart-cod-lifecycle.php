<?php
/**
 * Shared Free/PRO policy and prospective COD lifecycle. PHP 5.6 compatible.
 * One sharing setting governs collection in both existing and new installations.
 */
class Wc_Smart_Cod_Lifecycle {
	const ACTION = 'wsc_send_cod_lifecycle';
	const STATE = '_wsc_cod_lifecycle_v3';
	const GENERATION = 'wsc_cod_lifecycle_generation';

	public static function register() {
		add_filter( 'woocommerce_settings_api_form_fields_cod', array( __CLASS__, 'fields' ), 100 );
		add_filter( 'woocommerce_settings_api_sanitized_fields_cod', array( __CLASS__, 'sanitize' ) );
		add_action( 'update_option_woocommerce_cod_settings', array( __CLASS__, 'settings_changed' ), 20, 3 );
		add_action( 'add_option_woocommerce_cod_settings', array( __CLASS__, 'settings_created' ), 20, 2 );
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'checkout' ), 5 );
        add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'checkout' ), 5 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'status_changed' ), 40, 4 );
		add_action( 'woocommerce_after_order_object_save', array( __CLASS__, 'saved' ), 40 );
		// Courier integrations can persist metadata without saving the order object.
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta', 'added_order_meta', 'updated_order_meta', 'deleted_order_meta' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'tracking_meta_changed' ), 40, 4 );
		}
		add_action( self::ACTION, array( __CLASS__, 'send' ) );
	}

	public static function enabled() {
		require_once __DIR__ . '/class-wc-smart-cod-ai-outbox.php';
		return Wc_Smart_Cod_Ai_Outbox::is_data_sharing_enabled();
	}

	public static function policy() {
		$s = get_option( 'woocommerce_cod_settings', array() );
		$s = is_array( $s ) ? $s : array();
		$rate = isset( $s['smart_cod_minimum_success_rate'] ) && is_numeric( $s['smart_cod_minimum_success_rate'] ) ? (int) $s['smart_cod_minimum_success_rate'] : 100;
		$rate = max( 50, min( 100, $rate ) );
		return array( 'enabled' => $rate < 100, 'minimum_success_rate' => $rate );
	}

	public static function fields( $fields ) {
		$key = 'enable_smart_cod_ai_data_sharing';
		if ( ! isset( $fields[ $key ] ) ) return $fields;
		$fields[ $key ]['label'] = __( 'Enable COD protection', 'wc-smart-cod' );
		$fields[ $key ]['desc_tip'] = __( 'Stores cancelled and completed COD orders from this store to restrict COD for customers who did not receive their orders, and keep COD available when their successful-order rate meets your threshold.', 'wc-smart-cod' );
		$fields[ $key ]['description'] = '<a href="https://woosmartcod.com/cancelled-cod-data-sharing/" target="_blank" rel="noopener">' . __( 'Read how the service uses data.', 'wc-smart-cod' ) . '</a>';
		$new = array(
			$key => $fields[ $key ],
			'smart_cod_minimum_success_rate' => array(
				'title' => __( 'Minimum successful COD rate (%)', 'wc-smart-cod' ), 'type' => 'number', 'default' => 100,
				'custom_attributes' => array( 'min' => 50, 'max' => 100, 'step' => 1 ),
				'description' => __( 'At 90%, 9 successful and 1 failed order keep COD available.', 'wc-smart-cod' ),
				'desc_tip' => __( 'Same store only; other COD rules still apply. Set 100% to prevent successful orders from overriding a qualifying failure.', 'wc-smart-cod' ),
			),
		);
		unset( $fields[ $key ], $fields['smart_cod_history_information'], $fields['smart_cod_positive_history'] );
		return $new + $fields;
	}

	public static function sanitize( $settings ) {
		// Ignore the retired flag so a newly selected rate can take effect.
		unset( $settings['smart_cod_positive_history'] );
		if ( isset( $settings['smart_cod_minimum_success_rate'] ) ) {
			$rate = is_numeric( $settings['smart_cod_minimum_success_rate'] ) ? (int) $settings['smart_cod_minimum_success_rate'] : 100;
			$settings['smart_cod_minimum_success_rate'] = max( 50, min( 100, $rate ) );
		}
		return $settings;
	}
	public static function settings_created( $option, $value ) { self::settings_changed( array(), $value, $option ); }

	public static function settings_changed( $old, $new, $option ) {
		$was = is_array( $old ) && isset( $old['enable_smart_cod_ai_data_sharing'] ) && 'yes' === $old['enable_smart_cod_ai_data_sharing'];
		$is = is_array( $new ) && isset( $new['enable_smart_cod_ai_data_sharing'] ) && 'yes' === $new['enable_smart_cod_ai_data_sharing'];
		if ( $was === $is ) return; // Unrelated saves must not create a collection gap.
		update_option( self::GENERATION, wp_generate_uuid4(), false );
		if ( ! $is ) self::stop();
	}

	public static function stop() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) as_unschedule_all_actions( self::ACTION );
		wp_clear_scheduled_hook( self::ACTION );
	}

	public static function checkout( $order ) { if ( is_object( $order ) ) self::record( $order->get_id(), 'checkout' ); }
	public static function status_changed( $id, $from, $to, $order ) {
		if ( ! self::enabled() || ! is_object( $order ) ) return;
		// Blocks promotes its draft to pending before the processed checkout hook.
		// Do not mislabel that brand-new checkout as an old/late observation.
		if ( 'checkout-draft' === $from && 'pending' === $to && ! is_array( $order->get_meta( self::STATE, true ) ) ) return;
		if ( is_array( $order->get_meta( self::STATE, true ) ) ) self::record( $id, null, $from );
		elseif ( self::eligible( $order ) ) self::record( $id, 'late_observation', $from );
	}
	public static function saved( $order ) {
		if ( self::enabled() && is_object( $order ) && method_exists( $order, 'get_meta' ) && is_array( $order->get_meta( self::STATE, true ) ) ) self::record( $order->get_id() );
	}

	public static function tracking_meta_changed( $meta_id, $order_id, $meta_key, $meta_value ) {
		if ( ! self::enabled() || ! function_exists( 'wc_get_order' ) ) return;
		require_once __DIR__ . '/class-wc-smart-cod-cancelled-cod-collector.php';
		if ( ! Wc_Smart_Cod_Cancelled_Cod_Collector::is_tracking_metadata_key( $meta_key ) ) return;
		$order_id = absint( $order_id );
		$order = $order_id ? wc_get_order( $order_id ) : false;
		// Observe only orders already in this lifecycle; metadata is not a new checkout.
		if ( ! $order || ! is_array( $order->get_meta( self::STATE, true ) ) ) return;
		// Woo fires metadata hooks before it finishes the write/cache cycle. The
		// existing worker reloads fresh metadata under the order lock and deduplicates
		// the resulting facts. Scheduling also coalesces post/Woo hooks and burst edits.
		self::schedule( $order_id, 30 );
	}

	private static function locked( $id, $callback ) {
		global $wpdb;
		$key = 'wsc_life_' . substr( hash( 'sha256', $wpdb->prefix . '|' . $id ), 0, 48 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 1)', $key ) ) ) { self::schedule( $id, 60 ); return; }
		try {
			$order = wc_get_order( $id );
			if ( $order && method_exists( $order, 'read_meta_data' ) ) $order->read_meta_data( true );
			if ( $order ) call_user_func( $callback, $order );
		} catch ( Exception $e ) {
			self::schedule( $id, 60 );
		} finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) ); }
	}

	public static function record( $id, $origin = null, $from = null ) {
		if ( ! self::enabled() ) return;
		self::locked( $id, function ( $order ) use ( $id, $origin, $from ) {
			$state = $order->get_meta( self::STATE, true );
			if ( ! is_array( $state ) ) {
				if ( null === $origin || ! self::eligible( $order ) ) return;
				$initial = self::initial( $order, $origin );
				if ( null === $initial ) return;
				$state = array( 'initial' => $initial, 'revision' => 0, 'fingerprint' => '', 'pending' => array(), 'next' => 0, 'attempts' => 0, 'generation' => get_option( self::GENERATION, '' ) );
			}
			$raw_status = sanitize_key( $order->get_status() );
			if ( ! isset( $state['last_status'] ) || $state['last_status'] !== $raw_status ) {
				$state['previous_status'] = isset( $state['last_status'] ) ? $state['last_status'] : '';
				// A later tracking edit must not move an early cancellation past 24 hours.
				$state['cancelled_at_utc'] = 'cancelled' === $raw_status && isset( $state['last_status'] ) ? gmdate( 'Y-m-d H:i:s' ) : null;
				$state['last_status'] = $raw_status;
			}
			if ( null !== $from ) {
				$state['previous_status'] = sanitize_key( $from );
				if ( 'cancelled' === $raw_status && empty( $state['cancelled_at_utc'] ) ) $state['cancelled_at_utc'] = gmdate( 'Y-m-d H:i:s' );
			}
			$status = $raw_status;
			if ( ! in_array( $status, array( 'pending', 'on-hold', 'processing', 'completed', 'cancelled', 'refunded', 'failed' ), true ) ) $status = 'other';
			$current = self::initial( $order, $state['initial']['context']['capture_origin'] );
			$changed = ! empty( $state['context_changed'] ) || $state['generation'] !== get_option( self::GENERATION, '' );
			if ( null === $current ) $changed = true;
			else {
				$before = $state['initial']['context']; $after = $current['context'];
				foreach ( array( 'captured_at_utc', 'local_hour', 'local_weekday', 'production' ) as $key ) { unset( $before[ $key ], $after[ $key ] ); }
				$changed = $changed || $before !== $after || $state['initial']['network_identity'] !== $current['network_identity'];
			}
			$facts = array( 'order_status' => $status, 'cod_active' => self::eligible( $order ), 'context_changed' => $changed, 'trackings' => Wc_Smart_Cod_Ai_Outbox::lifecycle_trackings( $order ), 'fulfilment' => self::fulfilment( $order, $state ) );
			$fingerprint = hash( 'sha256', wp_json_encode( $facts ) );
			if ( $fingerprint === $state['fingerprint'] ) {
				if ( ! empty( $state['pending'] ) ) self::schedule( $id, 30 );
				return;
			}
			$rev = $state['revision'] + 1;
			$event = array_merge( $state['initial'], $facts, array( 'revision' => $rev, 'event_type' => 1 === $rev ? 'created' : 'status_changed', 'occurred_at_utc' => 1 === $rev ? str_replace( array( 'T', 'Z' ), array( ' ', '' ), $state['initial']['context']['captured_at_utc'] ) : gmdate( 'Y-m-d H:i:s' ) ) );
			$state['revision'] = $rev; $state['fingerprint'] = $fingerprint; $state['context_changed'] = $changed; $state['pending'][] = $event;
			self::schedule( $id, 30 );
			$order->update_meta_data( self::STATE, $state ); $order->save_meta_data();
		} );
	}

	public static function send( $id ) {
		if ( ! self::enabled() ) return;
		$order = wc_get_order( $id );
		if ( ! $order ) return;
		self::record( $id, is_array( $order->get_meta( self::STATE, true ) ) ? null : 'late_observation' );
		if ( method_exists( $order, 'read_meta_data' ) ) $order->read_meta_data( true );
		$queued = $order->get_meta( self::STATE, true );
		if ( ! is_array( $queued ) || empty( $queued['pending'] ) ) return;
		if ( $queued['next'] > time() ) { self::schedule( $id, $queued['next'] - time() ); return; }
		if ( ! self::reserve_send_slot() ) { self::schedule( $id, wp_rand( 10, 30 ) ); return; }
		$batch = array();
		self::locked( $id, function ( $order ) use ( $id, &$batch ) {
			$state = $order->get_meta( self::STATE, true );
			if ( ! is_array( $state ) || empty( $state['pending'] ) ) return;
			if ( $state['next'] > time() ) { self::schedule( $id, $state['next'] - time() ); return; }
			$delay = min( 21600, 60 * pow( 2, min( 9, $state['attempts'] ) ) );
			self::schedule( $id, $delay );
			$state['next'] = time() + $delay; $state['attempts']++;
			$order->update_meta_data( self::STATE, $state ); $order->save_meta_data();
			$batch = array_slice( $state['pending'], 0, 10 );
		} );
		if ( empty( $batch ) || ! Wc_Smart_Cod_Ai_Outbox::send_lifecycle_events( $batch ) ) return;
		self::locked( $id, function ( $order ) use ( $id, $batch ) {
			$state = $order->get_meta( self::STATE, true );
			if ( ! is_array( $state ) ) return;
			$revisions = array_column( $batch, 'revision' );
			$state['pending'] = array_values( array_filter( $state['pending'], function ( $event ) use ( $revisions ) { return ! in_array( $event['revision'], $revisions, true ); } ) );
			$state['next'] = 0; $state['attempts'] = 0;
			$order->update_meta_data( self::STATE, $state ); $order->save_meta_data();
			if ( ! empty( $state['pending'] ) ) self::schedule( $id, 30 );
		} );
	}

	private static function fulfilment( $order, $state ) {
		require_once __DIR__ . '/class-wc-smart-cod-cancelled-cod-collector.php';
		$created = method_exists( $order, 'get_date_created' ) ? $order->get_date_created() : null;
		$completed = method_exists( $order, 'get_date_completed' ) ? $order->get_date_completed() : null;
		return array(
			'created_at_utc' => $created ? gmdate( 'Y-m-d H:i:s', $created->getTimestamp() ) : null,
			'completed_at_utc' => $completed ? gmdate( 'Y-m-d H:i:s', $completed->getTimestamp() ) : null,
			'cancelled_at_utc' => isset( $state['cancelled_at_utc'] ) ? $state['cancelled_at_utc'] : null,
			'needs_shipping' => method_exists( $order, 'needs_shipping_address' ) && $order->needs_shipping_address(),
			'tracking_integration' => Wc_Smart_Cod_Cancelled_Cod_Collector::store_has_known_tracking_capability(),
			'previous_status' => isset( $state['previous_status'] ) ? $state['previous_status'] : '',
		);
	}

	private static function eligible( $order ) {
        // Older WooCommerce keeps Classic support; native Blocks requires 9.9+.
        if (method_exists($order, 'get_created_via') && 'store-api' === $order->get_created_via()
            && (!defined('WC_VERSION') || version_compare(WC_VERSION, '9.9', '<'))) return false;
		if ( ! method_exists( $order, 'get_items' ) || 'cod' !== $order->get_payment_method() || $order->get_meta( '_scn_deposit_parent_order_id', true ) || $order->get_meta( '_wsc_deposit_parent_order_id', true ) ) return false;
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( $product && ! $product->is_virtual() && $item->get_quantity() > 0 ) return true;
		}
		return false;
	}

	public static function minor( $value, $scale ) {
		if ( $scale < 0 || $scale > 3 || ! is_scalar( $value ) ) return null;
		$value = (string) $value;
		if ( ! preg_match( '/^([0-9]+)(?:\.([0-9]*))?$/D', $value, $m ) ) return null;
		$fraction = isset( $m[2] ) ? $m[2] : '';
		if ( strlen( rtrim( $fraction, '0' ) ) > $scale ) return null;
		$minor = ltrim( $m[1] . substr( str_pad( $fraction, $scale, '0' ), 0, $scale ), '0' );
		if ( strlen( $minor ) > 13 || (float) $minor > 1000000000000 || PHP_INT_SIZE < 8 ) return null;
		return (int) $minor;
	}

	private static function initial( $order, $origin ) {
		$required = array( 'get_shipping_total', 'get_discount_total', 'get_total', 'get_currency', 'get_customer_id', 'get_shipping_country', 'get_billing_country', 'get_shipping_postcode', 'get_billing_postcode', 'get_shipping_methods' );
		foreach ( $required as $method ) if ( ! method_exists( $order, $method ) ) return null;
		$identity = Wc_Smart_Cod_Ai_Outbox::lifecycle_identity( $order );
		if ( empty( $identity['email_token'] ) && empty( $identity['phone_token'] ) ) return null;
		$scale = wc_get_price_decimals();
		$total = self::minor( $order->get_total(), $scale ); $shipping = self::minor( $order->get_shipping_total(), $scale ); $discount = self::minor( $order->get_discount_total(), $scale );
		$country = strtoupper( $order->get_shipping_country() ? $order->get_shipping_country() : $order->get_billing_country() );
		$currency = strtoupper( $order->get_currency() ); $items = 0; $methods = array();
		foreach ( $order->get_items() as $item ) $items += (int) $item->get_quantity();
		foreach ( $order->get_shipping_methods() as $method ) $methods[] = sanitize_key( $method->get_method_id() );
		sort( $methods, SORT_STRING ); $method = empty( $methods ) ? 'unknown' : $methods[0];
		if ( count( array_unique( $methods ) ) > 1 ) $method = 'multiple';
		if ( null === $total || $total <= 0 || null === $shipping || $shipping > $total || null === $discount || $items < 1 || $items > 65535 || ! preg_match( '/^[A-Z]{2}$/D', $country ) || ! preg_match( '/^[A-Z]{3}$/D', $currency ) ) return null;
		$postcode = strtoupper( preg_replace( '/\s+/', '', trim( $order->get_shipping_postcode() ? $order->get_shipping_postcode() : $order->get_billing_postcode() ) ) );
		$now = time(); $local = new DateTimeImmutable( '@' . $now );
		$local = $local->setTimezone( function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' ) );
		return array(
			'schema_version' => '3.0', 'order_reference' => Wc_Smart_Cod_Ai_Outbox::lifecycle_reference( $order->get_id() ), 'network_identity' => $identity,
			'context' => array( 'captured_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z', $now ), 'capture_origin' => $origin, 'production' => function_exists( 'wp_get_environment_type' ) && 'production' === wp_get_environment_type(),
				'country' => $country, 'currency' => $currency, 'currency_scale' => $scale, 'total_minor' => $total, 'shipping_minor' => $shipping, 'discount_minor' => $discount, 'item_count' => $items,
				'customer_type' => $order->get_customer_id() > 0 ? 'registered' : 'guest', 'checkout_action' => 'cod', 'local_hour' => (int) $local->format( 'G' ), 'local_weekday' => (int) $local->format( 'N' ),
				'shipping_method' => substr( $method ? $method : 'unknown', 0, 80 ), 'postcode_token' => '' === $postcode ? null : hash( 'sha256', $postcode ) ),
		);
	}

	private static function reserve_send_slot() {
		global $wpdb;
		$key = 'wsc_life_send_' . substr( hash( 'sha256', $wpdb->prefix ), 0, 40 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $key ) ) ) return false;
		try {
			$next = (int) get_option( 'wsc_cod_lifecycle_next_send', 0 );
			if ( $next > time() ) return false;
			return update_option( 'wsc_cod_lifecycle_next_send', time() + 10, false );
		} finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) ); }
	}

	private static function schedule( $id, $delay ) {
		if ( ! self::enabled() ) return;
		$args = array( absint( $id ) );
		$when = time() + max( 1, $delay );
		$next = function_exists( 'as_next_scheduled_action' ) ? as_next_scheduled_action( self::ACTION, $args, 'wsc-smart-cod-ai' ) : ( function_exists( 'wp_next_scheduled' ) ? wp_next_scheduled( self::ACTION, $args ) : false );
		// A future queued job can be reused. A running job still needs its watchdog.
		if ( is_numeric( $next ) && $next > time() && $next <= $when ) return;
		if ( function_exists( 'as_schedule_single_action' ) ) as_schedule_single_action( $when, self::ACTION, $args, 'wsc-smart-cod-ai' );
		else wp_schedule_single_event( $when, self::ACTION, $args );
	}
}

