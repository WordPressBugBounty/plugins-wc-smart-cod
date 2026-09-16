<?php

/**
 * Finds cancelled Cash on Delivery orders with evidence of dispatch to a
	 * courier. It stores no order snapshot locally.
 *
	 * The collector intentionally does not make courier API requests. The
	 * rate-limited Smart COD AI outbox receives only eligible WooCommerce IDs.
 */
class Wc_Smart_Cod_Cancelled_Cod_Collector {

	const EVENT                = 'wsc_collect_cancelled_cod_orders';
	const CURSOR_OPTION        = 'wsc_cancelled_cod_collection_cursor';
	const COMPLETE_OPTION      = 'wsc_cancelled_cod_collection_complete';
	const LOCK_OPTION          = 'wsc_cancelled_cod_collection_lock';
	const STARTED_OPTION       = 'wsc_cancelled_cod_collection_started';
	const ACTION_GROUP         = 'wsc-smart-cod-ai';
	const DEFAULT_BATCH_SIZE   = 20;

	/**
	 * Starts the historical scan with a spread-out first run.
	 */
	public static function activate() {
		self::schedule( wp_rand( 5 * MINUTE_IN_SECONDS, 6 * HOUR_IN_SECONDS ) );
	}

	/**
	 * Removes scheduled work but leaves collection progress intact.
	 */
	public static function deactivate() {
		self::clear_scheduled_work();
		delete_option( self::LOCK_OPTION );
		delete_option( self::STARTED_OPTION );
	}

	/**
	 * Runs one indexed batch. It never calls a courier API.
	 */
	public static function collect_batch() {
		if ( get_option( self::COMPLETE_OPTION ) ) {
			self::clear_scheduled_work();
			return;
		}

		if ( ! self::acquire_lock() ) {
			return;
		}

		try {
			$cursor    = absint( get_option( self::CURSOR_OPTION, 0 ) );
			$batch_size = self::batch_size();
			$order_ids  = self::get_cancelled_cod_order_ids( $cursor, $batch_size );
			if ( null === $order_ids ) {
				// Database error: preserve the cursor and retry on the next hourly run.
				return;
			}

			if ( empty( $order_ids ) ) {
				self::complete();
				return;
			}

			$records = array();
			foreach ( $order_ids as $order_id ) {
				$record = self::get_record_for_order( $order_id );
				if ( ! empty( $record ) ) {
					$records[] = $record;
				}
				$cursor = max( $cursor, absint( $order_id ) );
			}
			if ( ! empty( $records ) ) {
				if ( ! self::queue_records( $records ) ) {
					// The hourly run will try the same IDs again; do not advance the cursor.
					return;
				}
			}

			if ( ! update_option( self::CURSOR_OPTION, $cursor, false ) ) {
				return;
			}

			if ( count( $order_ids ) < $batch_size ) {
				self::complete();
			}
		} catch ( Exception $exception ) {
			// A store/plugin integration must not turn a background scan into a fatal.
		} finally {
			self::release_lock();
		}
	}

	/**
	 * Captures a newly-cancelled order immediately, avoiding repeat history scans.
	 *
	 * @param int $order_id WooCommerce order ID.
	 */
	public static function capture_cancelled_order( $order_id ) {
		self::capture_order( $order_id );
	}

	/**
	 * Rebuilds one event candidate from the live WooCommerce order. No snapshot
	 * is persisted locally.
	 *
	 * @param int $order_id WooCommerce order ID.
	 */
	public static function get_record_for_order( $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return array();
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || ! self::supports_ai_order_api( $order ) || 'cancelled' !== $order->get_status() || ! self::is_cod_order( $order ) ) {
			return array();
		}

		$record = self::build_record( $order );
		if ( ! self::has_dispatch_evidence( $record, $order ) ) {
			return array();
		}
		return $record;
	}

	/**
	 * The AI relay is optional. On a legacy WooCommerce order object that lacks
	 * the modern getters, skip collection rather than risking a storefront or
	 * background fatal error.
	 */
	private static function supports_ai_order_api( $order ) {
		$methods = array(
			'get_status', 'get_payment_method', 'get_date_modified', 'get_id',
			'get_shipping_methods', 'get_meta_data', 'get_billing_email',
			'get_billing_phone', 'get_shipping_country', 'get_shipping_postcode',
			'get_shipping_city', 'get_shipping_state', 'get_billing_country',
			'get_billing_postcode', 'get_billing_city', 'get_billing_state',
			'get_total', 'get_item_count', 'get_currency', 'get_date_created',
			'get_shipping_total', 'get_shipping_tax', 'get_discount_total',
			'get_discount_tax', 'get_customer_id'
		);
		foreach ( $methods as $method ) {
			if ( ! method_exists( $order, $method ) ) {
				return false;
			}
		}
		return true;
	}

	public static function capture_order( $order_id ) {
		$record = self::get_record_for_order( $order_id );
		if ( empty( $record ) ) {
			return false;
		}

		return self::queue_records( array( $record ) );
	}

	private static function queue_records( $records ) {
		try {
			if ( ! class_exists( 'Wc_Smart_Cod_Ai_Outbox' ) ) {
				require_once __DIR__ . '/class-wc-smart-cod-ai-outbox.php';
			}
			return Wc_Smart_Cod_Ai_Outbox::queue_order_events( $records );
		} catch ( Exception $exception ) {
			return false;
		}
	}

	/**
	 * Registers one hourly recurring scan through WooCommerce Action Scheduler.
	 * Unlike page-load WP-Cron, its persistent queue can be run by WooCommerce's
	 * async runner. WP-Cron is retained only as a compatibility fallback when a
	 * host lacks Action Scheduler altogether.
	 *
	 * @param int $delay Seconds before the first run.
	 * @return bool Whether a recurring job exists.
	 */
	public static function schedule( $delay = 0 ) {
		if ( get_option( self::COMPLETE_OPTION ) ) {
			self::clear_scheduled_work();
			self::mark_started( 'complete' );
			return true;
		}

		if ( $delay <= 0 ) {
			$delay = wp_rand( 5 * MINUTE_IN_SECONDS, 6 * HOUR_IN_SECONDS );
		}
		$timestamp = time() + max( 60, absint( $delay ) );

		if ( function_exists( 'as_next_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
			if ( false !== as_next_scheduled_action( self::EVENT, array(), self::ACTION_GROUP ) ) {
				self::mark_started( 'action-scheduler' );
				return true;
			}
			// Replace an old direct WP-Cron event during the upgrade once only.
			wp_clear_scheduled_hook( self::EVENT );
			if ( as_schedule_recurring_action( $timestamp, HOUR_IN_SECONDS, self::EVENT, array(), self::ACTION_GROUP ) > 0 ) {
				self::mark_started( 'action-scheduler' );
				return true;
			}
			return false;
		}

		if ( wp_next_scheduled( self::EVENT ) ) {
			if ( 'hourly' === wp_get_schedule( self::EVENT ) ) {
				self::mark_started( 'wp-cron' );
				return true;
			}
			$cleared = wp_clear_scheduled_hook( self::EVENT );
			if ( false === $cleared || is_wp_error( $cleared ) ) {
				return false;
			}
		}

		if ( wp_schedule_event( $timestamp, 'hourly', self::EVENT ) ) {
			self::mark_started( 'wp-cron' );
			return true;
		}
		return false;
	}

	/**
	 * @return int
	 */
	private static function batch_size() {
		$size = absint( apply_filters( 'wsc_cancelled_cod_collection_batch_size', self::DEFAULT_BATCH_SIZE ) );
		return min( 100, max( 1, $size ) );
	}

	private static function mark_started( $runner ) {
		update_option( self::STARTED_OPTION, $runner, true );
	}

	private static function complete() {
		update_option( self::COMPLETE_OPTION, 1, false );
		self::clear_scheduled_work();
	}

	private static function clear_scheduled_work() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::EVENT, array(), self::ACTION_GROUP );
		}
		wp_clear_scheduled_hook( self::EVENT );
	}

	/**
	 * @param int $cursor Last processed order ID.
	 * @param int $limit  Batch size.
	 * @return int[]|null Null means the query failed and must be retried.
	 */
	private static function get_cancelled_cod_order_ids( $cursor, $limit ) {
		global $wpdb;

		$methods      = (array) apply_filters( 'wsc_cancelled_cod_payment_methods', array( 'cod' ) );
		$methods      = array_values( array_filter( array_map( 'sanitize_key', $methods ) ) );
		$placeholders = implode( ', ', array_fill( 0, count( $methods ), '%s' ) );

		if ( empty( $methods ) ) {
			return array();
		}

		if ( self::uses_hpos() ) {
			$query  = 'SELECT id FROM ' . $wpdb->prefix . 'wc_orders WHERE id > %d AND type = %s AND status = %s AND payment_method IN (' . $placeholders . ') ORDER BY id ASC LIMIT %d';
			$params = array_merge( array( $cursor, 'shop_order', 'wc-cancelled' ), $methods, array( $limit ) );
		} else {
			$query  = 'SELECT DISTINCT p.ID FROM ' . $wpdb->posts . ' p INNER JOIN ' . $wpdb->postmeta . ' pm ON pm.post_id = p.ID AND pm.meta_key = %s WHERE p.ID > %d AND p.post_type = %s AND p.post_status = %s AND pm.meta_value IN (' . $placeholders . ') ORDER BY p.ID ASC LIMIT %d';
			$params = array_merge( array( '_payment_method', $cursor, 'shop_order', 'wc-cancelled' ), $methods, array( $limit ) );
		}

		$ids = $wpdb->get_col( $wpdb->prepare( $query, $params ) );
		return $wpdb->last_error || ! is_array( $ids ) ? null : array_map( 'absint', $ids );
	}

	/**
	 * @param WC_Order $order WooCommerce order.
	 * @return bool
	 */
	private static function is_cod_order( $order ) {
		$methods = (array) apply_filters( 'wsc_cancelled_cod_payment_methods', array( 'cod' ) );
		return in_array( sanitize_key( $order->get_payment_method() ), array_map( 'sanitize_key', $methods ), true );
	}

	/**
	 * @param WC_Order $order WooCommerce order.
	 * @return array
	 */
	private static function build_record( $order ) {
		$now       = current_time( 'mysql', true );
		$cancelled = $order->get_date_modified();

		return array(
			'order_id'          => $order->get_id(),
			'cancelled_at_gmt'  => $cancelled ? gmdate( 'Y-m-d H:i:s', $cancelled->getTimestamp() ) : $now,
			'shipping_methods'  => self::encode_json( self::get_shipping_methods( $order ) ),
			'tracking_data'     => self::encode_json( self::get_tracking_data( $order ) ),
			'collected_at_gmt'  => $now,
			'updated_at_gmt'    => $now,
		);
	}

	/**
	 * By default a valid tracking number is the required dispatch evidence.
	 * Integrations with a trustworthy fulfilment status can opt in through the
	 * filter, but shipping methods alone must never qualify an order.
	 *
	 * @param array    $record Local collector record.
	 * @param WC_Order $order  WooCommerce order.
	 * @return bool
	 */
	private static function has_dispatch_evidence( $record, $order ) {
		$tracking     = json_decode( $record['tracking_data'], true );
		$has_tracking = false;
		foreach ( is_array( $tracking ) ? $tracking : array() as $item ) {
			if ( is_array( $item ) && ! empty( $item['tracking_number'] ) ) {
				$has_tracking = true;
				break;
			}
		}

		return (bool) apply_filters( 'wsc_cancelled_cod_has_dispatch_evidence', $has_tracking, $order, $record );
	}

	/**
	 * Stores portable shipping method slugs only. WooCommerce instance IDs are
	 * auto-incremented within one shop and have no cross-shop meaning.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array
	 */
	private static function get_shipping_methods( $order ) {
		$methods = array();
		foreach ( $order->get_shipping_methods() as $shipping_method ) {
			$method_id = sanitize_key( $shipping_method->get_method_id() );
			if ( '' !== $method_id ) {
				$methods[ $method_id ] = $method_id;
			}
		}

		return array_values( $methods );
	}

	/**
	 * Extracts tracking identifiers and courier/provider labels from known and
	 * generic tracking metadata without copying arbitrary order metadata.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array
	 */
	private static function get_tracking_data( $order ) {
		$tracking = array();
		$providers = array();

		foreach ( $order->get_meta_data() as $meta ) {
			$data  = $meta->get_data();
			$key   = isset( $data['key'] ) ? (string) $data['key'] : '';
			$value = isset( $data['value'] ) ? $data['value'] : null;
			$lower = strtolower( $key );

			if ( self::is_provider_key( $lower ) && is_scalar( $value ) ) {
				$providers[ $lower ] = self::sanitize_provider( $value );
			}

			if ( '_wc_shipment_tracking_items' === $lower || self::is_tracking_key( $lower ) ) {
				self::extract_tracking_candidates( $value, $lower, $providers, $tracking );
			}
		}

		$encoded_tracking = array();
		foreach ( $tracking as $item ) {
			$encoded_tracking[] = self::encode_json( $item );
		}
		$tracking = array_values( array_unique( $encoded_tracking ) );
		$tracking = array_map( 'json_decode', $tracking, array_fill( 0, count( $tracking ), true ) );

		return apply_filters( 'wsc_cancelled_cod_tracking_data', $tracking, $order );
	}

	private static function encode_json( $value ) {
		return function_exists( 'wp_json_encode' ) ? wp_json_encode( $value ) : json_encode( $value );
	}

	/**
	 * @param mixed  $value     Metadata value.
	 * @param string $source    Metadata key.
	 * @param array  $providers Known providers.
	 * @param array  $tracking  Collected tracking rows, passed by reference.
	 */
	private static function extract_tracking_candidates( $value, $source, $providers, &$tracking ) {
		if ( is_object( $value ) ) {
			$value = (array) $value;
		}

		if ( is_array( $value ) ) {
			$provider = self::first_provider( $value, $source, $providers );
			foreach ( $value as $key => $item ) {
				$key = strtolower( (string) $key );
				if ( self::is_tracking_key( $key ) && is_scalar( $item ) ) {
					self::add_tracking_item( $tracking, $provider, $item, $source );
				} elseif ( is_array( $item ) || is_object( $item ) ) {
					self::extract_tracking_candidates( $item, $source, $providers, $tracking );
				}
			}
			return;
		}

		if ( is_scalar( $value ) && self::is_tracking_key( $source ) ) {
			self::add_tracking_item( $tracking, self::first_provider( array(), $source, $providers ), $value, $source );
		}
	}

	/**
	 * @param array  $tracking Tracking rows, passed by reference.
	 * @param string $provider Courier/provider name.
	 * @param mixed  $number   Candidate tracking value.
	 * @param string $source   Metadata key.
	 */
	private static function add_tracking_item( &$tracking, $provider, $number, $source ) {
		$number = self::sanitize_tracking_number( $number );
		if ( '' === $number ) {
			return;
		}

		$tracking[] = array(
			'provider'        => $provider,
			'tracking_number' => $number,
			'source'          => sanitize_key( $source ),
		);
	}

	/**
	 * @param array  $value     Metadata value.
	 * @param string $source    Metadata key.
	 * @param array  $providers Known providers.
	 * @return string
	 */
	private static function first_provider( $value, $source, $providers ) {
		foreach ( array( 'tracking_provider', 'custom_tracking_provider', 'provider', 'carrier', 'courier', 'shipping_provider' ) as $key ) {
			if ( isset( $value[ $key ] ) && is_scalar( $value[ $key ] ) ) {
				return self::sanitize_provider( $value[ $key ] );
			}
		}

		foreach ( $providers as $provider ) {
			if ( '' !== $provider ) {
				return $provider;
			}
		}

		if ( preg_match( '/(acs|dhl|ups|fedex|elta|speedex|geniki|boxnow|dpd|gls|usps|royal-mail)/i', $source, $matches ) ) {
			return strtolower( $matches[1] );
		}

		return '';
	}

	/**
	 * @param string $key Metadata key.
	 * @return bool
	 */
	private static function is_tracking_key( $key ) {
		if ( self::is_provider_key( $key ) ) {
			return false;
		}

		return (bool) preg_match( '/(^|_)(tracking(_(number|code))?|track(_(number|code))?|awb(_number)?|waybill(_number)?|voucher(_number)?|consignment(_number)?|parcel(_number)?|shipment(_number)?)$/i', $key );
	}

	/**
	 * @param string $key Metadata key.
	 * @return bool
	 */
	private static function is_provider_key( $key ) {
		return (bool) preg_match( '/(tracking_provider|shipping_provider|courier|carrier)/i', $key );
	}

	/**
	 * @param mixed $number Candidate tracking value.
	 * @return string
	 */
	private static function sanitize_tracking_number( $number ) {
		$number = trim( (string) $number );
		if ( strlen( $number ) < 5 || strlen( $number ) > 128 || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $number ) ) {
			return '';
		}

		return $number;
	}

	/**
	 * @param mixed $provider Candidate provider value.
	 * @return string
	 */
	private static function sanitize_provider( $provider ) {
		return substr( sanitize_text_field( (string) $provider ), 0, 191 );
	}

	/**
	 * @return bool
	 */
	private static function acquire_lock() {
		$now  = time();
		$lock = absint( get_option( self::LOCK_OPTION, 0 ) );
		if ( $lock && $lock > $now - 15 * MINUTE_IN_SECONDS ) {
			return false;
		}

		update_option( self::LOCK_OPTION, $now, false );
		return true;
	}

	/**
	 * @return void
	 */
	private static function release_lock() {
		delete_option( self::LOCK_OPTION );
	}

	/**
	 * @return bool
	 */
	private static function uses_hpos() {
		return class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

}
