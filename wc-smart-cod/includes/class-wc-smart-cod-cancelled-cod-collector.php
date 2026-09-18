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
	const DISPATCH_TRACKING    = 1;
	const DISPATCH_COMPLETED   = 2;
	const DISPATCH_SHIPPED     = 4;
	const DISPATCH_UNTRACKED_FALLBACK = 8;
	// A bounded snapshot is enough for the risk model: the central service uses
	// the largest snapshot for one customer/shop, rather than adding snapshots
	// from successive cancellations together.
	const MAX_PRIOR_COMPLETED_TRACKED_COD_ORDERS = 50;
	const CURSOR_OPTION        = 'wsc_cancelled_cod_collection_cursor';
	const COMPLETE_OPTION      = 'wsc_cancelled_cod_collection_complete';
	const LOCK_OPTION          = 'wsc_cancelled_cod_collection_lock';
	const STARTED_OPTION       = 'wsc_cancelled_cod_collection_started';
	const SCAN_REVISION_OPTION = 'wsc_cancelled_cod_collection_revision';
	const SCAN_REVISION        = '2';
	const ACTION_GROUP         = 'wsc-smart-cod-ai';
	const DEFAULT_BATCH_SIZE   = 20;
	const DATA_SHARING_SETTING = 'enable_smart_cod_ai_data_sharing';

	/**
	 * Only an explicit merchant opt-in enables collection and transmission.
	 * Existing installations must not inherit consent from the old opt-out setting.
	 *
	 * @return bool
	 */
	public static function is_data_sharing_enabled() {
		$settings = get_option( 'woocommerce_cod_settings', array() );
		return is_array( $settings ) && isset( $settings[ self::DATA_SHARING_SETTING ] ) && 'yes' === $settings[ self::DATA_SHARING_SETTING ];
	}

	/**
	 * @return bool Whether this plugin release needs one slow historical re-scan.
	 */
	public static function needs_scan_revision() {
		return self::SCAN_REVISION !== (string) get_option( self::SCAN_REVISION_OPTION, '' );
	}

	/**
	 * Starts the historical scan with a spread-out first run.
	 */
	public static function activate() {
		if ( ! self::is_data_sharing_enabled() ) {
			return;
		}
		self::schedule( wp_rand( 5 * MINUTE_IN_SECONDS, 6 * HOUR_IN_SECONDS ) );
	}

	/**
	 * Removes scheduled work but leaves collection progress intact.
	 */
	public static function deactivate() {
		self::stop_scheduled_work();
	}

	/**
	 * Stops all collection work after the merchant disables data sharing. No
	 * order data is read by a scheduled job afterwards.
	 *
	 * @return void
	 */
	public static function stop_scheduled_work() {
		self::clear_scheduled_work();
		delete_option( self::LOCK_OPTION );
		delete_option( self::STARTED_OPTION );
	}

	/**
	 * A fresh opt-in must include orders cancelled while sharing was disabled.
	 * Previously accepted events are deduplicated by the central service.
	 *
	 * @return void
	 */
	public static function restart_history_scan() {
		delete_option( self::COMPLETE_OPTION );
		update_option( self::CURSOR_OPTION, 0, false );
		self::schedule( MINUTE_IN_SECONDS );
	}

	/**
	 * Runs one indexed batch. It never calls a courier API.
	 */
	public static function collect_batch() {
		if ( ! self::is_data_sharing_enabled() ) {
			self::stop_scheduled_work();
			return;
		}
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
	public static function capture_cancelled_order( $order_id, $order = null, $status_transition = array() ) {
		if ( ! self::is_data_sharing_enabled() ) {
			return false;
		}
		self::capture_order( $order_id, $status_transition );
	}

	/**
	 * Rebuilds one event candidate from the live WooCommerce order. No snapshot
	 * is persisted locally.
	 *
	 * @param int $order_id WooCommerce order ID.
	 */
	public static function get_record_for_order( $order_id, $status_transition = array(), $queued_evidence = 0 ) {
		if ( ! self::is_data_sharing_enabled() || ! function_exists( 'wc_get_order' ) ) {
			return array();
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || ! self::supports_ai_order_api( $order ) || 'cancelled' !== $order->get_status() || ! self::is_cod_order( $order ) ) {
			return array();
		}

		$record = self::build_record( $order, $status_transition );
		$record['dispatch_evidence'] |= absint( $queued_evidence ) & ( self::DISPATCH_COMPLETED | self::DISPATCH_SHIPPED | self::DISPATCH_UNTRACKED_FALLBACK );
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

	public static function capture_order( $order_id, $status_transition = array() ) {
		$record = self::get_record_for_order( $order_id, $status_transition );
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
		if ( ! self::is_data_sharing_enabled() ) {
			return false;
		}
		self::prepare_scan_revision();
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

	/**
	 * A new evidence rule needs one deliberately slow re-scan of historical
	 * cancelled COD orders. The central unique key updates the same event row,
	 * so this never creates a second event for an existing order.
	 *
	 * @return void
	 */
	private static function prepare_scan_revision() {
		if ( ! self::needs_scan_revision() ) {
			return;
		}

		update_option( self::SCAN_REVISION_OPTION, self::SCAN_REVISION, false );
		update_option( self::CURSOR_OPTION, 0, false );
		delete_option( self::COMPLETE_OPTION );
	}

	private static function complete() {
		update_option( self::COMPLETE_OPTION, 1, false );
		self::clear_scheduled_work();
	}

	private static function clear_scheduled_work() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::EVENT );
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

		// Bounded ID-only query supports both HPOS and posts; table names are from $wpdb,
		// every order value is passed through prepare(), and IN placeholders are generated above.
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared
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
	private static function build_record( $order, $status_transition = array() ) {
		$now       = current_time( 'mysql', true );
		$cancelled = $order->get_date_modified();
		$tracking  = self::get_tracking_data( $order );
		$evidence  = self::dispatch_evidence( $order, $tracking, $cancelled, $status_transition );
		$cancelled_at_gmt = $cancelled ? gmdate( 'Y-m-d H:i:s', $cancelled->getTimestamp() ) : $now;

		return array(
			'order_id'          => $order->get_id(),
			'cancelled_at_gmt'  => $cancelled_at_gmt,
			'shipping_methods'  => self::encode_json( self::get_shipping_methods( $order ) ),
			'tracking_data'     => self::encode_json( $tracking ),
			'dispatch_evidence' => $evidence,
			// This is deliberately a cumulative per-shop snapshot as at this
			// cancellation. It is not a delta and must never be summed across
			// cancelled events from the same installation.
			'prior_completed_tracked_cod_count' => $evidence ? self::prior_completed_tracked_cod_count( $order, $cancelled ) : 0,
			'collected_at_gmt'  => $now,
			'updated_at_gmt'    => $now,
		);
	}

	/**
	 * A tracking number is strong evidence, but many merchants use Completed as
	 * their physical-order fulfillment step without a tracking plugin. Shipping
	 * methods alone are never evidence.
	 *
	 * @param array    $record Local collector record.
	 * @param WC_Order $order  WooCommerce order.
	 * @return bool
	 */
	private static function has_dispatch_evidence( $record, $order ) {
		$has_evidence = ! empty( $record['dispatch_evidence'] );
		return (bool) apply_filters( 'wsc_cancelled_cod_has_dispatch_evidence', $has_evidence, $order, $record );
	}

	/**
	 * Counts a customer's earlier completed COD orders that contain a recognised
	 * tracking number. The lookup is intentionally bounded and runs only for an
	 * already eligible cancelled order; it never scans all shop orders.
	 *
	 * The current customer's account ID is the most precise and cheapest local
	 * key. Guest orders use an exact billing email and fall back to the exact
	 * stored billing phone only when no usable email exists.
	 *
	 * @param WC_Order    $order Current cancelled order.
	 * @param WC_DateTime $cancelled Cancellation timestamp.
	 * @return int
	 */
	private static function prior_completed_tracked_cod_count( $order, $cancelled ) {
		if ( ! $cancelled || ! function_exists( 'wc_get_order' ) ) {
			return 0;
		}

		$order_ids = self::get_prior_completed_cod_order_ids( $order, $cancelled );
		if ( null === $order_ids ) {
			return 0;
		}

		$count = 0;
		foreach ( $order_ids as $order_id ) {
			if ( $count >= self::MAX_PRIOR_COMPLETED_TRACKED_COD_ORDERS || absint( $order_id ) === absint( $order->get_id() ) ) {
				continue;
			}

			$candidate = wc_get_order( $order_id );
			if ( ! $candidate || ! self::supports_ai_order_api( $candidate ) || 'completed' !== $candidate->get_status() || ! self::is_cod_order( $candidate ) ) {
				continue;
			}
			if ( ! method_exists( $candidate, 'needs_shipping_address' ) || ! $candidate->needs_shipping_address() || ! method_exists( $candidate, 'get_date_completed' ) ) {
				continue;
			}

			$completed = $candidate->get_date_completed();
			if ( ! $completed || $completed->getTimestamp() > $cancelled->getTimestamp() || empty( self::get_tracking_data( $candidate ) ) ) {
				continue;
			}
			++$count;
		}

		return min( self::MAX_PRIOR_COMPLETED_TRACKED_COD_ORDERS, absint( apply_filters( 'wsc_cancelled_cod_prior_completed_tracked_cod_count', $count, $order, $order_ids ) ) );
	}

	/**
	 * Fetches a small, newest-first candidate set using indexed WooCommerce
	 * fields. Completion time and tracking metadata are verified afterwards,
	 * because neither is portable enough to encode in this database query.
	 *
	 * @param WC_Order    $order Current cancelled order.
	 * @param WC_DateTime $cancelled Cancellation timestamp.
	 * @return int[]|null Null indicates a database error.
	 */
	private static function get_prior_completed_cod_order_ids( $order, $cancelled ) {
		global $wpdb;

		$methods = array_values( array_filter( array_map( 'sanitize_key', (array) apply_filters( 'wsc_cancelled_cod_payment_methods', array( 'cod' ) ) ) ) );
		if ( empty( $methods ) ) {
			return array();
		}

		$limit       = min( 100, max( self::MAX_PRIOR_COMPLETED_TRACKED_COD_ORDERS, absint( apply_filters( 'wsc_cancelled_cod_prior_completed_candidate_limit', self::MAX_PRIOR_COMPLETED_TRACKED_COD_ORDERS ) ) ) );
		$before_gmt  = gmdate( 'Y-m-d H:i:s', $cancelled->getTimestamp() );
		$customer_id = absint( $order->get_customer_id() );
		$email       = strtolower( trim( sanitize_email( (string) $order->get_billing_email() ) ) );
		$phone       = trim( sanitize_text_field( (string) $order->get_billing_phone() ) );
		$placeholders = implode( ', ', array_fill( 0, count( $methods ), '%s' ) );

		if ( $customer_id > 0 ) {
			$identity = array( 'type' => 'customer', 'value' => $customer_id );
		} elseif ( '' !== $email && filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			$identity = array( 'type' => 'email', 'value' => $email );
		} elseif ( strlen( preg_replace( '/\D/', '', $phone ) ) >= 5 ) {
			$identity = array( 'type' => 'phone', 'value' => $phone );
		} else {
			return array();
		}

		if ( self::uses_hpos() ) {
			$orders_table = $wpdb->prefix . 'wc_orders';
			$join = '';
			$join_params = array();
			$where_params = array();
			if ( 'customer' === $identity['type'] ) {
				$where = 'o.customer_id = %d';
				$where_params = array( $identity['value'] );
			} elseif ( 'email' === $identity['type'] ) {
				$where = 'o.billing_email = %s';
				$where_params = array( $identity['value'] );
			} else {
				$addresses_table = $wpdb->prefix . 'wc_order_addresses';
				$join = ' INNER JOIN ' . $addresses_table . ' a ON a.order_id = o.id AND a.address_type = %s AND a.phone = %s';
				$where = '1 = 1';
				$join_params = array( 'billing', $identity['value'] );
			}
			$query = 'SELECT o.id FROM ' . $orders_table . ' o' . $join . ' WHERE o.id <> %d AND o.type = %s AND o.status = %s AND o.date_created_gmt <= %s AND ' . $where . ' AND o.payment_method IN (' . $placeholders . ') ORDER BY o.date_created_gmt DESC, o.id DESC LIMIT %d';
			$params = array_merge( $join_params, array( $order->get_id(), 'shop_order', 'wc-completed', $before_gmt ), $where_params, $methods, array( $limit ) );
		} else {
			$posts_table = $wpdb->posts;
			$meta_table  = $wpdb->postmeta;
			$join = ' INNER JOIN ' . $meta_table . ' payment ON payment.post_id = p.ID AND payment.meta_key = %s';
			$join_params = array( '_payment_method' );
			$where_params = array();
			if ( 'customer' === $identity['type'] ) {
				$where = 'p.post_author = %d';
				$where_params[] = $identity['value'];
			} else {
				$meta_key = 'email' === $identity['type'] ? '_billing_email' : '_billing_phone';
				$join .= ' INNER JOIN ' . $meta_table . ' identity_meta ON identity_meta.post_id = p.ID AND identity_meta.meta_key = %s AND identity_meta.meta_value = %s';
				$join_params[] = $meta_key;
				$join_params[] = $identity['value'];
				$where = '1 = 1';
			}
			$query = 'SELECT DISTINCT p.ID FROM ' . $posts_table . ' p' . $join . ' WHERE p.ID <> %d AND p.post_type = %s AND p.post_status = %s AND p.post_date_gmt <= %s AND ' . $where . ' AND payment.meta_value IN (' . $placeholders . ') ORDER BY p.post_date_gmt DESC, p.ID DESC LIMIT %d';
			$params = array_merge( $join_params, array( $order->get_id(), 'shop_order', 'wc-completed', $before_gmt ), $where_params, $methods, array( $limit ) );
		}

		// Bounded ID-only query supports both HPOS and posts; table names and SQL fragments
		// come from fixed branches, while all order values are passed through prepare().
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( $query, $params ) );
		return $wpdb->last_error || ! is_array( $ids ) ? null : array_values( array_unique( array_map( 'absint', $ids ) ) );
	}

	/**
	 * Returns a bitmask so the central service can distinguish a trackable
	 * shipment from a merchant fulfillment signal. A completed status only
	 * qualifies a physical order and must predate its cancellation. The final
	 * fallback is deliberately lower confidence: it is available only where no
	 * known tracking integration is active and the COD order was open for at
	 * least one day.
	 */
	private static function dispatch_evidence( $order, $tracking, $cancelled, $status_transition = array() ) {
		$evidence = 0;
		foreach ( (array) $tracking as $item ) {
			if ( is_array( $item ) && ! empty( $item['tracking_number'] ) ) {
				$evidence |= self::DISPATCH_TRACKING;
				break;
			}
		}

		if ( ! method_exists( $order, 'needs_shipping_address' ) || ! $order->needs_shipping_address() ) {
			return $evidence;
		}

		if ( method_exists( $order, 'get_date_completed' ) ) {
			$completed = $order->get_date_completed();
			if ( $completed && $cancelled && $completed->getTimestamp() < $cancelled->getTimestamp() ) {
				$evidence |= self::DISPATCH_COMPLETED;
			}
		}

		$from = is_array( $status_transition ) && isset( $status_transition['from'] ) ? sanitize_key( $status_transition['from'] ) : '';
		if ( in_array( $from, array( 'shipped', 'partially-shipped', 'fulfilled', 'partially-fulfilled' ), true ) ) {
			$evidence |= self::DISPATCH_SHIPPED;
		}

		if ( 0 === $evidence && self::should_use_untracked_fallback( $order, $cancelled, $tracking, $from ) ) {
			$evidence |= self::DISPATCH_UNTRACKED_FALLBACK;
		}

		return $evidence;
	}

	/**
	 * Avoids treating ordinary merchant cancellations as delivery failures. This
	 * route is used only on shops without a recognised tracking integration. A
	 * live cancellation must also come from a fulfilment-stage status; historical
	 * orders lack that transition, so their one-day minimum age is retained.
	 *
	 * @param WC_Order    $order Order being evaluated.
	 * @param WC_DateTime $cancelled Cancellation timestamp.
	 * @param array       $tracking Tracking data already found on this order.
	 * @param string      $from Live source status, when WooCommerce supplied it.
	 * @return bool
	 */
	private static function should_use_untracked_fallback( $order, $cancelled, $tracking, $from ) {
		if ( ! empty( $tracking ) || self::store_has_known_tracking_capability() || ! $cancelled || ! method_exists( $order, 'get_date_created' ) ) {
			return false;
		}

		$created = $order->get_date_created();
		if ( ! $created || $cancelled->getTimestamp() < $created->getTimestamp() ) {
			return false;
		}

		$minimum_age = max( DAY_IN_SECONDS, absint( apply_filters( 'wsc_cancelled_cod_untracked_fallback_minimum_age', DAY_IN_SECONDS, $order ) ) );
		if ( $cancelled->getTimestamp() - $created->getTimestamp() < $minimum_age ) {
			return false;
		}

		if ( '' !== $from && ! in_array( $from, array( 'processing', 'completed', 'shipped', 'partially-shipped', 'fulfilled', 'partially-fulfilled' ), true ) ) {
			return false;
		}

		return (bool) apply_filters( 'wsc_cancelled_cod_use_untracked_fallback', true, $order, $cancelled, $from );
	}

	/**
	 * Checks the small set of integrations with explicit tracking adapters. The
	 * test is intentionally cheap and runs only in a background batch or during
	 * one cancellation event, never at checkout.
	 *
	 * @return bool
	 */
	private static function store_has_known_tracking_capability() {
		static $has_capability = null;
		if ( null !== $has_capability ) {
			return $has_capability;
		}

		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$known_plugins = (array) apply_filters(
			'wsc_cancelled_cod_known_tracking_plugins',
			array(
				'woocommerce-shipment-tracking/woocommerce-shipment-tracking.php',
				'ast-pro/ast-pro.php',
				'woocommerce-order-tracking/woocommerce-order-tracking.php',
				'yith-woocommerce-order-tracking/init.php',
				'aftership-woocommerce-tracking/aftership-woocommerce-tracking.php',
				'woocommerce-shipment-tracking-pro/woocommerce-shipment-tracking-pro.php',
			)
		);
		$has_capability = (bool) array_intersect( $known_plugins, $active );
		return $has_capability;
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
		self::extract_known_tracking_adapters( $order, $tracking );

		foreach ( $order->get_meta_data() as $meta ) {
			$data  = $meta->get_data();
			$key   = isset( $data['key'] ) ? (string) $data['key'] : '';
			$value = isset( $data['value'] ) ? $data['value'] : null;
			$lower = strtolower( $key );
			if ( self::is_known_tracking_adapter_key( $lower ) ) {
				continue;
			}

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

	/**
	 * Explicit adapters for tracking plugins whose order-meta schemas are
	 * documented or verified in their source. They keep the tracking number and
	 * courier paired even when WooCommerce returns meta rows in a different
	 * order. Generic key heuristics below remain a compatibility fallback only.
	 *
	 * Shared _wc_shipment_tracking_items schema:
	 * - WooCommerce Shipment Tracking (official)
	 * - Advanced Shipment Tracking / TrackShip
	 * - PluginHive Shipment Tracking Pro when its documented official-plugin
	 *   integration is enabled
	 *
	 * @param WC_Order $order Order being inspected.
	 * @param array    $tracking Tracking rows, passed by reference.
	 */
	private static function extract_known_tracking_adapters( $order, &$tracking ) {
		if ( ! method_exists( $order, 'get_meta' ) ) {
			return;
		}

		// Official WooCommerce Shipment Tracking and AST use an array of shipment
		// records here. PluginHive is covered only when its documented integration
		// with the official WooCommerce plugin is enabled.
		self::extract_tracking_candidates( $order->get_meta( '_wc_shipment_tracking_items', true ), '_wc_shipment_tracking_items', array(), $tracking );

		// WooCommerce Order Tracking by WebExpert / Opportus.
		self::add_tracking_item( $tracking, $order->get_meta( 'wcot_shipper', true ), $order->get_meta( 'wcot_number', true ), 'wcot_number' );

		// YITH WooCommerce Order Tracking's published free-version schema. This
		// remains a compatibility adapter; proprietary current builds need their
		// own source fixture before they are treated as a new contract.
		self::add_tracking_item( $tracking, $order->get_meta( 'ywot_carrier_name', true ), $order->get_meta( 'ywot_tracking_code', true ), 'ywot_tracking_code' );

		// AfterShip - WooCommerce Tracking. Each current record has a carrier slug
		// alongside its number; do not depend on generic meta iteration order.
		$has_aftership_items = self::extract_aftership_tracking_items( $order->get_meta( '_aftership_tracking_items', true ), $tracking );
		if ( ! $has_aftership_items ) {
			$provider = $order->get_meta( '_aftership_tracking_provider_name', true );
			if ( '' === (string) $provider ) {
				$provider = $order->get_meta( '_aftership_tracking_provider', true );
			}
			self::add_tracking_item( $tracking, $provider, $order->get_meta( '_aftership_tracking_number', true ), '_aftership_tracking_number' );
		}
	}

	/**
	 * Extract the current AfterShip array while preserving the number/carrier
	 * pairing for every parcel in a split shipment.
	 *
	 * @param mixed $items AfterShip tracking records.
	 * @param array $tracking Tracking rows, passed by reference.
	 * @return bool Whether at least one valid tracking item was found.
	 */
	private static function extract_aftership_tracking_items( $items, &$tracking ) {
		if ( is_object( $items ) ) {
			$items = (array) $items;
		}

		if ( ! is_array( $items ) ) {
			return false;
		}

		$found = false;
		foreach ( $items as $item ) {
			if ( is_object( $item ) ) {
				$item = (array) $item;
			}
			if ( ! is_array( $item ) ) {
				continue;
			}

			$provider = isset( $item['slug'] ) ? $item['slug'] : '';
			$number   = isset( $item['tracking_number'] ) ? $item['tracking_number'] : '';
			$before = count( $tracking );
			self::add_tracking_item( $tracking, $provider, $number, '_aftership_tracking_items' );
			$found = $found || count( $tracking ) > $before;
		}

		return $found;
	}

	/**
	 * Known adapter fields must not fall through to the generic scan: a provider
	 * from an unrelated meta row could otherwise be paired with their number.
	 *
	 * @param string $key Meta key, lower-cased.
	 * @return bool
	 */
	private static function is_known_tracking_adapter_key( $key ) {
		return in_array(
			$key,
			array(
				'_wc_shipment_tracking_items',
				'wcot_shipper', 'wcot_number',
				'ywot_carrier_name', 'ywot_tracking_code',
				'_aftership_tracking_items',
				'_aftership_tracking_provider_name',
				'_aftership_tracking_provider', '_aftership_tracking_number',
			),
			true
		);
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
