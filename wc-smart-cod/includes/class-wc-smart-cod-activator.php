<?php

/**
 * Fired during plugin activation
 *
 * @link       https://woosmartcod.com/
 * @since      1.0.0
 *
 * @package    Wc_Smart_Cod
 * @subpackage Wc_Smart_Cod/includes
 */

/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @since      1.0.0
 * @package    Wc_Smart_Cod
 * @subpackage Wc_Smart_Cod/includes
 * @author     FullStack <info@woosmartcod.com>
 */
class Wc_Smart_Cod_Activator {

	/**
	 * Short Description. (use period)
	 *
	 * Long Description.
	 *
	 * @since    1.0.0
	 */

	public static function activate() {
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-wc-smart-cod-cancelled-cod-collector.php';
		Wc_Smart_Cod_Cancelled_Cod_Collector::activate();
		set_transient( 'wc-smart-cod-activated', true, 30 );
	}

}
