<?php
/** Display preferences only; never changes billing, licensing or store settings. */
if ( ! class_exists( 'Wc_Smart_Cod_AI_Upgrade_Link', false ) ) {
	class Wc_Smart_Cod_AI_Upgrade_Link {
		public static function url( $edition ) {
			$edition = $edition === 'pro' ? 'pro' : 'free';
			$location = get_option( 'woocommerce_default_country', '' );
			$parts = explode( ':', is_string( $location ) ? $location : '', 2 );
			$country = strtoupper( trim( $parts[0] ) );
			if ( ! preg_match( '/^[A-Z]{2}$/D', $country ) ) $country = '';
			$locale = get_option( 'WPLANG', '' );
			$language = is_string( $locale ) ? strtolower( preg_split( '/[_-]/', $locale )[0] ) : '';
			$by_country = array( 'GR' => 'el', 'IT' => 'it', 'IN' => 'en' );
			$language = isset( $by_country[$country] ) ? $by_country[$country] : $language;
			$prefix = in_array( $language, array( 'el', 'it' ), true ) ? '/' . $language : '';
			$url = 'https://ai.woosmartcod.com' . $prefix . '/upgrade/' . $edition . '/';
			return $country === '' ? $url : $url . '?shop_country=' . rawurlencode( $country );
		}
	}
}
