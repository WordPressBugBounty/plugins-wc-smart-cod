<?php

class Wc_Smart_Cod_Settings_Manager {

	private $settings_url;

	public function __construct( $pro_url ) {
		$this->settings_url = $pro_url . '/settings-manager/';
	}

	/**
	 * Fetches configuration during an explicitly admin-only refresh.
	 *
	 * @return array|null Null means that no usable response was received.
	 */
	public function fetch_settings_manager() {
		try {
			$res = wp_safe_remote_get(
				$this->settings_url,
				array(
					'timeout'             => 2,
					'redirection'         => 0,
					'sslverify'           => true,
					'limit_response_size' => 262144,
					'headers'             => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				)
			);

			if ( is_wp_error( $res ) ) {
				return null;
			}

			$ok = $res
				&& isset( $res['response']['code'] )
				&& 200 === $res['response']['code'];
			if ( ! $ok ) {
				return null;
			}

			$settings = json_decode( $res['body'], true );
			return is_array( $settings ) ? $settings : null;
		} catch ( Exception $e ) {
			return null;
		}
	}
}
