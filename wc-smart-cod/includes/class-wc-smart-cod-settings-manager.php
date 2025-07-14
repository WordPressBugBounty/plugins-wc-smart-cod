<?php

class Wc_Smart_Cod_Settings_Manager {

    private $settings_url;

    public function __construct( $pro_url ) {
        $this->settings_url = $pro_url . '/settings-manager/';
    }

    public function get_settings_manager() {
    
		try {

			$headers = array('Content-Type' => 'application/json; charset=utf-8');

			$res = wp_remote_get(
				$this->settings_url,
                array( 'timeout' => 5, 'headers' => $headers )
			);

			if( is_wp_error( $res ) ) {
				return array();
			}

			$ok = $res
                && isset($res['response'])
                && isset($res['response']['code'])
                && $res['response']['code'] === 200;
	
			if($ok) {
				return json_decode( $res['body'], true );
			}
		}
		catch(Exception $e) {
		} 
		
		return array('e' => array(), 'd' => array(), 'l' => ""); 
    }
    
}