<?php

/**
 * Relays cancelled-COD shipment events to Smart COD AI in deliberately small,
 * authenticated batches. Courier APIs are never called by this class.
 *
 * It keeps no plugin-owned outbox table: the payload is built from the order
 * only when its small scheduled batch runs.
 */
class Wc_Smart_Cod_Ai_Outbox {

	const EVENT                 = 'wsc_send_smart_cod_ai_events';
	const LOCK_OPTION           = 'wsc_smart_cod_ai_outbox_lock';
	const INSTALLATION_OPTION   = 'wsc_smart_cod_ai_installation_id';
	const HASH_SECRET_OPTION    = 'wsc_smart_cod_ai_hash_secret';
	const ENROLLED_OPTION       = 'wsc_smart_cod_ai_enrolled';
	const PRIVATE_KEY_OPTION    = 'wsc_smart_cod_ai_signing_private_key';
	const PUBLIC_KEY_OPTION     = 'wsc_smart_cod_ai_signing_public_key';
	const KEY_ALGORITHM_OPTION  = 'wsc_smart_cod_ai_signing_algorithm';
	const MAX_BATCH_SIZE        = 10;
	const DEFAULT_BATCH_SIZE    = self::MAX_BATCH_SIZE;
	const DEFAULT_INTERVAL      = 10 * MINUTE_IN_SECONDS;

	/**
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::EVENT );
		delete_option( self::LOCK_OPTION );
	}

	public static function queue_order_events( $records ) {
		$ids = array();
		foreach ( (array) $records as $record ) {
			if ( isset( $record['order_id'] ) ) {
				$ids[] = absint( $record['order_id'] );
			}
		}
		return self::schedule_order_ids( $ids, 60 );
	}

	/**
	 * Returns the WooCommerce base country for this merchant. This identifies
	 * where the e-shop operates and is intentionally separate from a shopper's
	 * billing or shipping country.
	 *
	 * @return string ISO 3166-1 alpha-2 code, or an empty string if unavailable.
	 */
	public static function get_operating_country() {
		$country = strtoupper( (string) get_option( 'woocommerce_default_country', '' ) );
		$country = substr( $country, 0, 2 );
		return (bool) preg_match( '/^[A-Z]{2}$/', $country ) ? $country : '';
	}

	/**
	 * Returns only the shop hostname for installation support and operations.
	 * Paths, ports, credentials and query strings are deliberately excluded.
	 *
	 * @return string
	 */
	public static function get_site_domain() {
		$domain = strtolower( rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ), '.' ) );
		if ( function_exists( 'idn_to_ascii' ) ) {
			$ascii = idn_to_ascii( $domain );
			if ( false !== $ascii ) {
				$domain = strtolower( $ascii );
			}
		}
		if ( '' === $domain || false !== filter_var( $domain, FILTER_VALIDATE_IP ) ) {
			return '';
		}
		// FILTER_VALIDATE_DOMAIN was added in PHP 7.0. Keep this validation
		// portable for older, still-supported PHP installations.
		return preg_match( '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z]{2,63}$/i', $domain ) ? $domain : '';
	}

	/**
	 * Sends at most one small batch. The installation enrolls itself once using
	 * a locally generated public key; there is no API key to provision or enter.
	 */
	public static function send_order_ids( $order_ids = array() ) {
		$order_ids = array_values( array_filter( array_unique( array_map( 'absint', (array) $order_ids ) ) ) );
		if ( empty( $order_ids ) ) {
			return;
		}
		if ( ! self::acquire_lock() ) {
			self::schedule_order_ids( $order_ids, self::retry_delay(), true );
			return;
		}

		try {
			$current_ids = array_slice( $order_ids, 0, self::batch_size() );
			$remaining_ids = array_slice( $order_ids, self::batch_size() );
			if ( ! empty( $remaining_ids ) ) {
				// Also protects jobs scheduled by an older plugin version with too many IDs.
				self::schedule_order_ids( $remaining_ids, self::interval() );
			}
			if ( '' === self::get_operating_country() ) {
				self::schedule_order_ids( $current_ids, DAY_IN_SECONDS, true );
				return;
			}
			$endpoint = self::endpoint();
			$event_order_ids = array();
			$events = self::build_events( $current_ids, $event_order_ids );
			if ( empty( $events ) ) {
				return;
			}
			if ( ! self::is_allowed_endpoint( $endpoint ) || ! self::ensure_enrolled() ) {
				self::schedule_order_ids( $current_ids, DAY_IN_SECONDS, true );
				return;
			}

			$body = self::encode_json(
				array(
					'schema_version' => '1.0',
					'installation_id' => self::installation_id(),
					'events' => $events,
				)
			);
			if ( false === $body ) {
				self::schedule_order_ids( $current_ids, self::retry_delay(), true );
				return;
			}

			$response = wp_safe_remote_post(
				$endpoint,
				array(
					'timeout'     => 3,
					'redirection' => 0,
					'sslverify'   => true,
					'headers'     => self::signed_headers( $body ),
					'body'        => $body,
				)
			);

			$code = ( is_wp_error( $response ) || ! isset( $response['response']['code'] ) ) ? 0 : absint( $response['response']['code'] );
			if ( 202 === $code ) {
				$ack = json_decode( wp_remote_retrieve_body( $response ), true );
				if ( is_array( $ack ) && isset( $ack['accepted'], $ack['duplicates'] )
					&& is_int( $ack['accepted'] ) && is_int( $ack['duplicates'] )
					&& $ack['accepted'] >= 0 && $ack['duplicates'] >= 0
					&& $ack['accepted'] + $ack['duplicates'] === count( $events ) ) {
					return;
				}
				self::schedule_order_ids( $current_ids, self::retry_delay(), true );
				return;
			}
			if ( in_array( $code, array( 400, 413, 422 ), true ) ) {
				$failure = json_decode( wp_remote_retrieve_body( $response ), true );
				$reason = ( is_array( $failure ) && isset( $failure['code'] ) ) ? $failure['code'] : '';
				$index = ( is_array( $failure ) && isset( $failure['event_index'] ) ) ? $failure['event_index'] : null;
				if ( in_array( $reason, array( 'invalid_event', 'invalid_event_source', 'unsupported_event', 'invalid_event_payload', 'missing_dispatch_evidence', 'source_country_mismatch' ), true )
					&& is_int( $index ) && isset( $event_order_ids[ $index ] ) ) {
					$rejected_id = $event_order_ids[ $index ];
					$other_ids = array_values( array_diff( $current_ids, array( $rejected_id ) ) );
					self::schedule_order_ids( $other_ids, wp_rand( HOUR_IN_SECONDS, 2 * HOUR_IN_SECONDS ), true );
					self::schedule_order_ids( array( $rejected_id ), DAY_IN_SECONDS, true );
					return;
				}
				if ( 413 === $code && count( $current_ids ) > 1 ) {
					$parts = array_chunk( $current_ids, (int) ceil( count( $current_ids ) / 2 ) );
					foreach ( $parts as $part_index => $part ) {
						self::schedule_order_ids( $part, HOUR_IN_SECONDS + ( $part_index * self::interval() ), true );
					}
					return;
				}
				self::schedule_order_ids( $current_ids, DAY_IN_SECONDS, true );
				return;
			}
			if ( 401 === $code ) {
				delete_option( self::ENROLLED_OPTION );
			}
			$delay = 403 === $code ? DAY_IN_SECONDS : ( in_array( $code, array( 401, 429, 502, 503, 504 ), true )
				? wp_rand( HOUR_IN_SECONDS, 2 * HOUR_IN_SECONDS )
				: self::retry_delay() );
			self::schedule_order_ids( $current_ids, $delay, true );
		} catch ( Exception $exception ) {
			self::schedule_order_ids( isset( $current_ids ) ? $current_ids : $order_ids, self::retry_delay(), true );
		} finally {
			self::release_lock();
		}
	}

	private static function build_events( $order_ids, &$event_order_ids = array() ) {
		$events = array();
		$event_order_ids = array();
		foreach ( array_unique( array_map( 'absint', (array) $order_ids ) ) as $order_id ) {
			$order  = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
			$record = class_exists( 'Wc_Smart_Cod_Cancelled_Cod_Collector' ) ? Wc_Smart_Cod_Cancelled_Cod_Collector::get_record_for_order( $order_id ) : array();
			if ( ! $order || empty( $record ) ) {
				continue;
			}
			$tracking = self::tracking_payload( self::decode_list( $record['tracking_data'] ) );
			if ( empty( $tracking ) ) {
				continue;
			}
			$network_identity = self::network_identity( $order );
			if ( empty( $network_identity['email_token'] ) && empty( $network_identity['phone_token'] ) ) {
				continue;
			}
			$payload = array(
				'schema_version' => '1.3', 'identity_token_scheme' => 1, 'event_type' => 'cancelled_cod_order', 'occurred_at_utc' => $record['cancelled_at_gmt'],
				'source' => array( 'installation_id' => self::installation_id(), 'operating_country' => self::get_operating_country(), 'plugin' => 'wc-smart-cod', 'plugin_version' => defined( 'SMART_COD_VER' ) ? SMART_COD_VER : '' ),
				'order' => array( 'reference' => self::order_reference( $order_id ), 'payment_method' => 'cod' ),
				'network_identity' => $network_identity, 'location_identity' => self::location_identity( $order ), 'ml_context' => self::ml_context( $order, $record['cancelled_at_gmt'] ),
				'shipment' => array( 'shipping_method_slug' => self::shipping_method_slug( $record['shipping_methods'] ), 'trackings' => $tracking ),
			);
			$events[] = array( 'payload' => $payload );
			$event_order_ids[] = $order_id;
		}
		return $events;
	}

	/**
	 * Normalises and tokenises identity locally. Raw email and telephone values
	 * never leave the merchant's WordPress installation.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array
	 */
	private static function network_identity( $order ) {
		$email   = strtolower( trim( sanitize_email( (string) $order->get_billing_email() ) ) );
		if ( '' !== $email && ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			$email = '';
		}
		$country = self::get_operating_country();
		$phone   = self::normalise_network_phone( $order->get_billing_phone(), $country );

		$email = '' !== $email ? self::identity_token( $email ) : '';
		$phone = strlen( preg_replace( '/\D/', '', $phone ) ) >= 5 ? self::identity_token( $phone ) : '';

		return array(
			'country'     => preg_match( '/^[A-Z]{2}$/', $country ) ? $country : '',
			'email_token' => $email,
			'phone_token' => $phone,
		);
	}

	/**
	 * Version 1 is a deterministic SHA-256 token over a locally normalised
	 * value. The protocol version is sent separately in identity_token_scheme;
	 * keeping it out of the input makes the token format straightforward to
	 * inspect and reproduce at checkout.
	 *
	 * @param string $value Normalised non-empty value.
	 * @return string
	 */
	private static function identity_token( $value ) {
		return hash( 'sha256', (string) $value );
	}

	/**
	 * Uses WooCommerce's country metadata to make a national phone number and
	 * its E.164 equivalent converge to the same token where possible.
	 *
	 * @param string $phone   Submitted billing phone.
	 * @param string $country Operating country.
	 * @return string
	 */
	private static function normalise_network_phone( $phone, $country ) {
		$phone = preg_replace( '/[^0-9+]/', '', (string) $phone );
		if ( 0 === strpos( $phone, '00' ) ) {
			return '+' . substr( $phone, 2 );
		}
		if ( '+' === substr( $phone, 0, 1 ) ) {
			return $phone;
		}

		$digits       = preg_replace( '/\D/', '', $phone );
		$calling_code = '';
		$countries    = function_exists( 'WC' ) && WC() ? WC()->countries : null;
		if ( is_object( $countries ) && method_exists( $countries, 'get_country_calling_code' ) ) {
			$calling_code = preg_replace( '/\D/', '', (string) $countries->get_country_calling_code( $country ) );
		}
		if ( '' === $calling_code || '' === $digits ) {
			return $digits;
		}
		if ( 0 === strpos( $digits, $calling_code ) ) {
			return '+' . $digits;
		}

		return '+' . $calling_code . ltrim( $digits, '0' );
	}

	/**
	 * Returns the minimum neutral facts required for central model processing.
	 * Bucketing rules deliberately do not live in the plugin: the service keeps
	 * those rules private, consistent and currency-aware. The transport still
	 * never includes address, IP, product lines or other order contents.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param string   $cancelled_at_gmt Cancellation timestamp in UTC.
	 * @return array
	 */
	private static function ml_context( $order, $cancelled_at_gmt ) {
		$total    = self::decimal_amount( $order->get_total() );
		$count    = min( 65535, max( 0, absint( $order->get_item_count() ) ) );
		$currency = strtoupper( preg_replace( '/[^A-Z]/', '', (string) $order->get_currency() ) );
		$created  = $order->get_date_created();
		$created_stamp   = $created && method_exists( $created, 'getTimestamp' ) ? $created->getTimestamp() : 0;
		$cancelled_stamp = strtotime( trim( (string) $cancelled_at_gmt ) . ' UTC' );
		$age_seconds     = ( $created_stamp && $cancelled_stamp && $cancelled_stamp >= $created_stamp ) ? min( 2147483647, $cancelled_stamp - $created_stamp ) : 0;

		return array(
			'currency'          => preg_match( '/^[A-Z]{3}$/', $currency ) ? $currency : 'XXX',
			'order_total'       => $total,
			'shipping_amount'   => self::decimal_amount( $order->get_shipping_total() ),
			'shipping_tax'      => self::decimal_amount( $order->get_shipping_tax() ),
			'discount_amount'   => self::decimal_amount( $order->get_discount_total() ),
			'discount_tax'      => self::decimal_amount( $order->get_discount_tax() ),
			'item_count'        => $count,
			'customer_type'     => absint( $order->get_customer_id() ) > 0 ? 'registered' : 'guest',
			'order_hour_local'  => $created ? (int) $created->format( 'G' ) : 255,
			'order_weekday_local' => $created ? (int) $created->format( 'N' ) : 255,
			'order_age_seconds' => $age_seconds,
		);
	}

	/**
	 * Produces a canonical decimal string; the central service converts it to
	 * ISO-4217 minor units. This avoids embedding currency/bucketing rules in
	 * every customer installation.
	 *
	 * @param mixed $value WooCommerce monetary amount.
	 * @return string
	 */
	private static function decimal_amount( $value ) {
		$value = trim( (string) $value );
		if ( ! preg_match( '/^\d+(?:\.\d+)?$/', $value ) ) {
			return '0';
		}
		$value = ltrim( $value, '0' );
		return '' === $value || '.' === substr( $value, 0, 1 ) ? '0' . $value : $value;
	}

	/** @return string */
	private static function shipping_method_slug( $value ) {
		$methods = self::decode_list( $value );
		foreach ( $methods as $method ) {
			$method = is_array( $method ) ? ( isset( $method['method_id'] ) ? $method['method_id'] : '' ) : $method;
			$method = strtolower( sanitize_key( (string) $method ) );
			if ( '' !== $method ) {
				return substr( $method, 0, 100 );
			}
		}
		return '';
	}

	/**
	 * Tokenises delivery location locally. Country remains in clear only to scope
	 * the network; postcode, city and region never leave the merchant
	 * installation in raw form.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array
	 */
	private static function location_identity( $order ) {
		$country = strtoupper( preg_replace( '/[^A-Z]/', '', (string) $order->get_shipping_country() ) );
		$country = substr( $country, 0, 2 );
		$postcode = trim( (string) $order->get_shipping_postcode() );
		$city     = trim( (string) $order->get_shipping_city() );
		$region   = trim( (string) $order->get_shipping_state() );

		if ( '' === $country ) {
			$country  = strtoupper( preg_replace( '/[^A-Z]/', '', (string) $order->get_billing_country() ) );
			$country  = substr( $country, 0, 2 );
			$postcode = '' !== $postcode ? $postcode : trim( (string) $order->get_billing_postcode() );
			$city     = '' !== $city ? $city : trim( (string) $order->get_billing_city() );
			$region   = '' !== $region ? $region : trim( (string) $order->get_billing_state() );
		}

		$postcode = strtoupper( preg_replace( '/[^A-Z0-9]/', '', $postcode ) );
		$city     = self::normalise_location_text( $city );
		$region   = self::normalise_location_text( $region );

		return array(
			'country'              => preg_match( '/^[A-Z]{2}$/', $country ) ? $country : '',
			'postcode_exact_token' => '' === $postcode ? '' : self::identity_token( $postcode ),
			'city_token'           => '' === $city ? '' : self::identity_token( $city ),
			'region_token'         => '' === $region ? '' : self::identity_token( $region ),
		);
	}

	/**
	 * @param string $value Submitted city or region.
	 * @return string
	 */
	private static function normalise_location_text( $value ) {
		$value = strtolower( trim( sanitize_text_field( (string) $value ) ) );
		if ( function_exists( 'remove_accents' ) ) {
			$value = strtolower( remove_accents( $value ) );
		} elseif ( function_exists( 'iconv' ) ) {
			$ascii = iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $value );
			if ( false !== $ascii ) {
				$value = strtolower( $ascii );
			}
		}
		$value = preg_replace( '/\s+/', ' ', $value );
		return strlen( $value ) >= 2 ? substr( $value, 0, 100 ) : '';
	}

	/**
	 * Removes plugin-internal metadata keys from the relay payload. Tracking code
	 * and courier label are retained because they are required for later delivery
	 * enrichment by Smart COD AI.
	 *
	 * @param array $tracking Local tracking records.
	 * @return array
	 */
	private static function tracking_payload( $tracking ) {
		$payload = array();
		foreach ( $tracking as $item ) {
			if ( ! is_array( $item ) || empty( $item['tracking_number'] ) ) {
				continue;
			}
			$payload[] = array(
				'courier'       => isset( $item['provider'] ) ? sanitize_text_field( $item['provider'] ) : '',
				'tracking_code' => sanitize_text_field( $item['tracking_number'] ),
			);
		}

		return array_values( $payload );
	}

	/**
	 * @param int $order_id WooCommerce order ID; it is never sent in raw form.
	 * @return string
	 */
	private static function order_reference( $order_id ) {
		return hash_hmac( 'sha256', 'order|' . absint( $order_id ), self::hash_secret() );
	}

	/**
	 * PHP 5.6 has hash_equals(), but keep enrollment validation safe when an
	 * older WordPress/PHP stack has not supplied its compatibility shim.
	 */
	private static function safe_equals( $known, $user ) {
		if ( function_exists( 'hash_equals' ) ) {
			return hash_equals( (string) $known, (string) $user );
		}
		$known = (string) $known;
		$user  = (string) $user;
		if ( strlen( $known ) !== strlen( $user ) ) {
			return false;
		}
		$result = 0;
		for ( $index = 0, $length = strlen( $known ); $index < $length; $index++ ) {
			$result |= ord( $known[ $index ] ) ^ ord( $user[ $index ] );
		}
		return 0 === $result;
	}

	/**
	 * @return string
	 */
	private static function installation_id() {
		$id = (string) get_option( self::INSTALLATION_OPTION, '' );
		if ( '' === $id ) {
			$id = self::uuid();
			update_option( self::INSTALLATION_OPTION, $id, false );
		}
		return $id;
	}

	/**
	 * @return string
	 */
	private static function hash_secret() {
		$secret = (string) get_option( self::HASH_SECRET_OPTION, '' );
		if ( '' === $secret ) {
			$secret = wp_generate_password( 64, true, true );
			update_option( self::HASH_SECRET_OPTION, $secret, false );
		}
		return $secret;
	}

	/**
	 * Automatically registers this plugin installation's public signing key.
	 * Smart COD AI must make this endpoint idempotent by installation ID so a
	 * harmless retry never creates a duplicate merchant installation.
	 *
	 * @return bool
	 */
	private static function ensure_enrolled() {
		$domain     = self::get_site_domain();
		$country    = self::get_operating_country();
		$enrollment = get_option( self::ENROLLED_OPTION );
		if ( is_array( $enrollment ) && isset( $enrollment['site_domain'], $enrollment['operating_country'] )
			&& self::safe_equals( $domain, (string) $enrollment['site_domain'] )
			&& self::safe_equals( $country, (string) $enrollment['operating_country'] ) ) {
			return true;
		}

		$public_key = self::public_signing_key();
		$endpoint   = self::registration_endpoint();
		if ( '' === $public_key || ! self::is_allowed_endpoint( $endpoint ) ) {
			return false;
		}

		$body = self::encode_json(
			array(
				'schema_version'  => '1.0',
				'installation_id' => self::installation_id(),
				'key_algorithm'   => self::signing_algorithm(),
				'public_key'      => 'ed25519' === self::signing_algorithm() ? $public_key : base64_encode( $public_key ),
				'source'          => array(
					'plugin'            => 'wc-smart-cod',
					'plugin_version'    => defined( 'SMART_COD_VER' ) ? SMART_COD_VER : '',
					'operating_country' => $country,
					'site_domain'       => $domain,
				),
			)
		);
		if ( false === $body ) {
			return false;
		}

		try {
			$response = wp_safe_remote_post(
				$endpoint,
				array(
					'timeout'     => 3,
					'redirection' => 0,
					'sslverify'   => true,
					'headers'     => self::signed_headers( $body ),
					'body'        => $body,
				)
			);
		} catch ( Exception $exception ) {
			return false;
		}

		if ( ! is_wp_error( $response ) && isset( $response['response']['code'] ) && in_array( absint( $response['response']['code'] ), array( 200, 201 ), true ) ) {
			update_option( self::ENROLLED_OPTION, array( 'site_domain' => $domain, 'operating_country' => $country ), false );
			return true;
		}

		return false;
	}

	/**
	 * Signs a request using the local private key. Smart COD AI verifies this
	 * signature using the public key received during automatic enrollment.
	 *
	 * @param string $body JSON request body.
	 * @return array
	 */
	private static function signed_headers( $body ) {
		$timestamp = (string) time();
		$nonce     = self::uuid();
		$signature = self::sign_request( $timestamp . "\n" . $nonce . "\n" . $body );

		return array(
			'Content-Type'      => 'application/json',
			'X-WSC-Installation' => self::installation_id(),
			'X-WSC-Timestamp'    => $timestamp,
			'X-WSC-Nonce'        => $nonce,
			'X-WSC-Signature-Algorithm' => self::signing_algorithm(),
			'X-WSC-Signature'    => $signature,
		);
	}

	/**
	 * @param string $data Canonical timestamp, nonce, and request body.
	 * @return string Base64-encoded detached signature.
	 */
	private static function sign_request( $data ) {
		$private = self::private_signing_key();
		if ( '' === $private ) {
			return '';
		}

		if ( 'ed25519' === self::signing_algorithm() && function_exists( 'sodium_crypto_sign_detached' ) ) {
			return base64_encode( sodium_crypto_sign_detached( $data, base64_decode( $private ) ) );
		}

		if ( 'rsa-sha256' === self::signing_algorithm() && function_exists( 'openssl_sign' ) && openssl_sign( $data, $signature, $private, OPENSSL_ALGO_SHA256 ) ) {
			return base64_encode( $signature );
		}

		return '';
	}

	/**
	 * Creates an Ed25519 private/public key pair once, kept only in this
	 * WordPress installation. PHP's sodium extension is used where available;
	 * an RSA/OpenSSL fallback keeps older hosts compatible. If neither works,
	 * the outbox safely remains local.
	 *
	 * @return bool
	 */
	private static function ensure_signing_keys() {
		if ( '' !== (string) get_option( self::PRIVATE_KEY_OPTION, '' ) && '' !== (string) get_option( self::PUBLIC_KEY_OPTION, '' ) ) {
			return true;
		}

		if ( function_exists( 'sodium_crypto_sign_keypair' ) ) {
			$keypair = sodium_crypto_sign_keypair();
			update_option( self::PRIVATE_KEY_OPTION, base64_encode( sodium_crypto_sign_secretkey( $keypair ) ), false );
			update_option( self::PUBLIC_KEY_OPTION, base64_encode( sodium_crypto_sign_publickey( $keypair ) ), false );
			update_option( self::KEY_ALGORITHM_OPTION, 'ed25519', false );
			return true;
		}

		if ( ! function_exists( 'openssl_pkey_new' ) || ! function_exists( 'openssl_pkey_export' ) || ! function_exists( 'openssl_pkey_get_details' ) ) {
			return false;
		}
		$key = openssl_pkey_new(
			array(
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);
		if ( false === $key || ! openssl_pkey_export( $key, $private_key ) ) {
			return false;
		}
		$details = openssl_pkey_get_details( $key );
		if ( empty( $details['key'] ) ) {
			return false;
		}

		update_option( self::PRIVATE_KEY_OPTION, $private_key, false );
		update_option( self::PUBLIC_KEY_OPTION, $details['key'], false );
		update_option( self::KEY_ALGORITHM_OPTION, 'rsa-sha256', false );
		return true;
	}

	/**
	 * @return string
	 */
	private static function private_signing_key() {
		return self::ensure_signing_keys() ? (string) get_option( self::PRIVATE_KEY_OPTION, '' ) : '';
	}

	/**
	 * @return string
	 */
	private static function public_signing_key() {
		return self::ensure_signing_keys() ? (string) get_option( self::PUBLIC_KEY_OPTION, '' ) : '';
	}

	/**
	 * @return string
	 */
	private static function signing_algorithm() {
		$algorithm = (string) get_option( self::KEY_ALGORITHM_OPTION, '' );
		return '' !== $algorithm ? $algorithm : 'rsa-sha256';
	}

	private static function encode_json( $value ) {
		return function_exists( 'wp_json_encode' ) ? wp_json_encode( $value ) : json_encode( $value );
	}

	/**
	 * wp_generate_uuid4() is unavailable on some old WordPress installations.
	 * The fallback is only an installation/nonce identifier; request signatures
	 * remain the authentication boundary.
	 */
	private static function uuid() {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}
		$seed = function_exists( 'wp_generate_password' ) ? wp_generate_password( 64, true, true ) : uniqid( '', true );
		$hash = hash( 'sha256', $seed . '|' . uniqid( '', true ) . '|' . mt_rand() );
		return substr( $hash, 0, 8 ) . '-' . substr( $hash, 8, 4 ) . '-4' . substr( $hash, 13, 3 ) . '-a' . substr( $hash, 17, 3 ) . '-' . substr( $hash, 20, 12 );
	}

	/**
	 * Endpoint paths may be changed by Smart COD AI internally, but merchants
	 * never receive a credential or need to configure one.
	 *
	 * @return string
	 */
	private static function endpoint() {
		$endpoint = defined( 'WSC_SMART_COD_AI_ENDPOINT' ) ? WSC_SMART_COD_AI_ENDPOINT : 'https://api.woosmartcod.com/v1/cancelled-cod-events';
		return esc_url_raw( apply_filters( 'wsc_smart_cod_ai_endpoint', $endpoint ) );
	}

	/**
	 * @return string
	 */
	private static function registration_endpoint() {
		$endpoint = defined( 'WSC_SMART_COD_AI_REGISTRATION_ENDPOINT' ) ? WSC_SMART_COD_AI_REGISTRATION_ENDPOINT : 'https://api.woosmartcod.com/v1/installations';
		return esc_url_raw( apply_filters( 'wsc_smart_cod_ai_registration_endpoint', $endpoint ) );
	}

	/**
	 * Limits automatic transmission to the Smart COD domain and HTTPS. A filter
	 * may customise a path, but cannot turn this client into an arbitrary relay.
	 *
	 * @param string $endpoint Candidate endpoint URL.
	 * @return bool
	 */
	private static function is_allowed_endpoint( $endpoint ) {
		$parts = wp_parse_url( $endpoint );
		$host  = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
		return isset( $parts['scheme'] ) && 'https' === strtolower( $parts['scheme'] ) && in_array( $host, array( 'woosmartcod.com', 'api.woosmartcod.com' ), true );
	}

	/**
	 * Queues only WooCommerce order IDs. The event payload is rebuilt at send
	 * time and is never persisted in a plugin-owned database table. Split a
	 * historical collector batch to the same maximum size as an HTTP payload.
	 */
	private static function schedule_order_ids( $order_ids, $delay, $retry = false ) {
		$order_ids = array_values( array_filter( array_unique( array_map( 'absint', (array) $order_ids ) ) ) );
		if ( empty( $order_ids ) ) {
			return true;
		}
		$delay = max( 60, absint( $delay ) );
		$all_scheduled = true;
		foreach ( array_chunk( $order_ids, self::batch_size() ) as $index => $chunk ) {
			$run_delay = $delay + ( $index * self::interval() );
			try {
				if ( function_exists( 'as_next_scheduled_action' ) && function_exists( 'as_schedule_single_action' ) ) {
					$pending = $retry && function_exists( 'as_get_scheduled_actions' )
						? as_get_scheduled_actions( array( 'hook' => self::EVENT, 'args' => array( $chunk ), 'group' => 'wsc-smart-cod-ai', 'status' => 'pending', 'per_page' => 1 ), 'ids' )
						: array();
					$exists = $retry ? ! empty( $pending ) : false !== as_next_scheduled_action( self::EVENT, array( $chunk ), 'wsc-smart-cod-ai' );
					if ( $exists || as_schedule_single_action( time() + $run_delay, self::EVENT, array( $chunk ), 'wsc-smart-cod-ai' ) > 0 ) {
						continue;
					}
				}
			} catch ( Exception $exception ) {
				// Action Scheduler may fail while WP-Cron can still persist the job.
			}
			try {
				if ( ! wp_next_scheduled( self::EVENT, array( $chunk ) ) && ! wp_schedule_single_event( time() + $run_delay, self::EVENT, array( $chunk ) ) ) {
					$all_scheduled = false;
				}
			} catch ( Exception $exception ) {
				$all_scheduled = false;
			}
		}
		return $all_scheduled;
	}

	/**
	 * @return int
	 */
	private static function batch_size() {
		$size = absint( apply_filters( 'wsc_smart_cod_ai_batch_size', self::DEFAULT_BATCH_SIZE ) );
		return min( self::MAX_BATCH_SIZE, max( 1, $size ) );
	}

	/**
	 * @return int
	 */
	private static function interval() {
		$interval = absint( apply_filters( 'wsc_smart_cod_ai_interval', self::DEFAULT_INTERVAL ) );
		return max( 5 * MINUTE_IN_SECONDS, $interval );
	}

	/**
	 * Avoids synchronised retry waves after a central-service outage or 429.
	 *
	 * @return int
	 */
	private static function retry_delay() {
		$interval = self::interval();
		return wp_rand( $interval, min( DAY_IN_SECONDS, 2 * $interval ) );
	}

	/**
	 * @param string $json JSON-encoded list.
	 * @return array
	 */
	private static function decode_list( $json ) {
		$list = json_decode( (string) $json, true );
		return is_array( $list ) ? $list : array();
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

}
