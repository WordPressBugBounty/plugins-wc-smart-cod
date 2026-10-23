<?php
/** Free-only, same-store settings backup. No orders, credentials or sharing consent. */
class Wc_Smart_Cod_Settings_Backup {
	const MAX_BYTES = 131072;
	public static function schema() {
		return array(
			'enabled' => 'yesno', 'title' => 'text', 'description' => 'text', 'instructions' => 'text',
			'enable_for_virtual' => 'yesno', 'enable_for_methods' => 'list',
			'shipping_zone_restrictions' => 'list', 'country_restrictions' => 'list', 'state_restrictions' => 'list',
			'restrict_postals' => 'text', 'city_restrictions' => 'text', 'user_role_restriction' => 'list',
			'category_restriction' => 'list', 'product_restriction' => 'list', 'shipping_class_restriction' => 'list',
			'shipping_zone_method_restriction' => 'list', 'category_restriction_mode' => 'mode',
			'product_restriction_mode' => 'mode', 'shipping_class_restriction_mode' => 'mode',
			'extra_fee' => 'amount', 'method_different_charge_local_pickup' => 'amount',
			'percentage_rounding' => 'rounding', 'extra_fee_tax' => 'tax',
			'smart_cod_minimum_success_rate' => 'rate', 'cod_unavailable_message' => 'messages',
			'restriction_settings' => 'restrictions', 'fee_settings' => 'fees',
		);
	}
	public static function store( $url ) {
		$p = parse_url( $url );
		if ( ! is_array( $p ) || empty( $p['host'] ) ) throw new InvalidArgumentException( 'store' );
		return strtolower( $p['host'] ) . ( isset( $p['port'] ) ? ':' . $p['port'] : '' ) . rtrim( isset( $p['path'] ) ? $p['path'] : '', '/' );
	}
	public static function select( $settings ) {
		$selected = array_intersect_key( is_array( $settings ) ? $settings : array(), self::schema() );
		foreach ( array( 'fee_settings', 'restriction_settings' ) as $key ) {
			if ( ! isset( $selected[$key] ) || ! is_string( $selected[$key] ) ) continue;
			$meta = json_decode( $selected[$key], true, 3 );
			if ( is_array( $meta ) ) {
				$free = array_intersect_key( $meta, array_flip( self::metadata_keys( $key ) ) );
				if ( $free !== $meta ) $selected[$key] = json_encode( $free );
			}
		}
		return $selected;
	}
	public static function metadata_keys( $key ) {
		return $key === 'fee_settings' ? array( 'extra_fee', 'method_different_charge_local_pickup' ) : array( 'role_restriction', 'shipping_zone_restrictions', 'country_restrictions', 'state_restrictions', 'restrict_postals', 'city_restrictions', 'user_role_restriction', 'category_restriction', 'product_restriction', 'shipping_class_restriction', 'shipping_zone_method_restriction' );
	}
	public static function export( $settings, $url ) {
		$data = array( 'format' => 'wc-smart-cod-free-settings', 'version' => 1, 'store' => self::store( $url ), 'settings' => self::select( $settings ) );
		$json = json_encode( $data );
		self::parse( $json, $url );
		return $json;
	}
	public static function parse( $json, $url ) {
		if ( ! is_string( $json ) || strlen( $json ) > self::MAX_BYTES ) throw new InvalidArgumentException( 'size' );
		$d = json_decode( $json, true, 8 );
		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $d ) || count( $d ) !== 4 || ! isset( $d['format'], $d['version'], $d['store'], $d['settings'] ) || $d['format'] !== 'wc-smart-cod-free-settings' || $d['version'] !== 1 || $d['store'] !== self::store( $url ) || ! is_array( $d['settings'] ) || empty( $d['settings'] ) ) throw new InvalidArgumentException( 'format' );
		$schema = self::schema();
		foreach ( $d['settings'] as $key => $value ) {
			if ( ! isset( $schema[$key] ) ) throw new InvalidArgumentException( 'field' );
			$type = $schema[$key];
			if ( $type === 'list' && $value !== '' ) {
				if ( ! is_array( $value ) || count( $value ) > 1000 || array_values( $value ) !== $value ) throw new InvalidArgumentException( 'list' );
				foreach ( $value as $item ) if ( ( ! is_string( $item ) && ! is_int( $item ) ) || strlen( (string) $item ) > 200 ) throw new InvalidArgumentException( 'item' );
			} elseif ( $type === 'messages' && ! is_string( $value ) ) {
				if ( ! is_array( $value ) || count( $value ) > 20 ) throw new InvalidArgumentException( 'messages' );
				$reasons = array( 'generic', 'shipping_method', 'shipping_zone', 'country', 'state', 'postal', 'city', 'cart_amount', 'user_role', 'category', 'product', 'shipping_class', 'shipping_zone_method' );
				foreach ( $value as $reason => $message ) if ( ! in_array( $reason, $reasons, true ) || ! is_string( $message ) || strlen( $message ) > 10000 ) throw new InvalidArgumentException( 'message' );
			} elseif ( $type === 'restrictions' || $type === 'fees' ) {
				if ( ! is_string( $value ) ) throw new InvalidArgumentException( 'metadata' );
				$meta = json_decode( $value, true, 3 );
				if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $meta ) ) throw new InvalidArgumentException( 'metadata' );
				$allowed = self::metadata_keys( $key );
				foreach ( $meta as $name => $flag ) if ( ! in_array( $name, $allowed, true ) || ( $type === 'fees' ? ! in_array( $flag, array( 'fixed', 'percentage' ), true ) : ! in_array( $flag, array( 0, 1 ), true ) ) ) throw new InvalidArgumentException( 'metadata' );
			} else {
				if ( ! is_string( $value ) && ! is_int( $value ) && ! is_float( $value ) ) throw new InvalidArgumentException( 'scalar' );
				$enums = array( 'yesno' => array( 'yes', 'no' ), 'mode' => array( 'one_product', 'all_products' ), 'rounding' => array( 'round_up', 'round_down' ), 'tax' => array( 'enable', 'disable' ) );
				if ( isset( $enums[$type] ) && ! in_array( $value, $enums[$type], true ) ) throw new InvalidArgumentException( 'enum' );
				if ( $type === 'amount' && $value !== '' && ( ! is_numeric( $value ) || ! is_finite( (float) $value ) || (float) $value < 0 ) ) throw new InvalidArgumentException( 'amount' );
				if ( $type === 'rate' && ( ! is_numeric( $value ) || (float) $value != (int) $value || $value < 50 || $value > 100 ) ) throw new InvalidArgumentException( 'rate' );
				if ( $type === 'text' && ( ! is_string( $value ) || strlen( $value ) > 10000 ) ) throw new InvalidArgumentException( 'text' );
				if ( $type === 'messages' && strlen( $value ) > 10000 ) throw new InvalidArgumentException( 'text' );
			}
		}
		return $d['settings'];
	}
	public static function merge( $current, $restored ) {
		foreach ( $restored as $key => $value ) {
			if ( ! isset( self::schema()[$key] ) ) throw new InvalidArgumentException( 'field' );
			if ( in_array( $key, array( 'fee_settings', 'restriction_settings' ), true ) ) {
				// Old paid-only metadata is not exported or restored, but must not be erased.
				$old = isset( $current[$key] ) && is_string( $current[$key] ) ? json_decode( $current[$key], true ) : array();
				$protected = is_array( $old ) ? array_diff_key( $old, array_flip( self::metadata_keys( $key ) ) ) : array();
				if ( $protected ) $value = json_encode( json_decode( $value, true ) + $protected );
			}
			if ( is_string( $value ) && in_array( $key, array( 'title', 'description', 'instructions', 'restrict_postals', 'city_restrictions' ), true ) ) $value = wp_kses_post( $value );
			if ( $key === 'cod_unavailable_message' ) $value = is_array( $value ) ? array_map( 'wp_kses_post', $value ) : wp_kses_post( $value );
			$current[$key] = $value;
		}
		return $current;
	}
}
