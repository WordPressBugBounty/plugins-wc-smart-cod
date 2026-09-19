<?php

/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link       https://woosmartcod.com/
 * @since      1.0.0
 *
 * @package    Wc_Smart_Cod
 * @subpackage Wc_Smart_Cod/includes
 */

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @since      1.0.0
 * @package    Wc_Smart_Cod
 * @subpackage Wc_Smart_Cod/includes
 * @author     FullStack <info@woosmartcod.com>
 */
class Wc_Smart_Cod {

	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      Wc_Smart_Cod_Loader    $loader    Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name    The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $version    The current version of the plugin.
	 */
	public static $version;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the admin area and
	 * the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */

	public static $plugin_friendly_name = 'Smart COD for WooCommerce';

	public static $plugin_settings_url;

	public function __construct() {

		$this->plugin_name = 'wc-smart-cod';
		
		define( 'SMART_COD_VER', '1.9.4' );

		self::$version = SMART_COD_VER;

		self::$plugin_settings_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=cod' );

		add_action( 'plugins_loaded', array( $this, 'load_dependencies' ) );
		add_filter( 'woocommerce_payment_gateways', array( $this, 'load_smart_cod' ) );
		add_action( 'admin_notices', array( $this, 'activate_notice' ) );
		add_action( 'admin_notices', array( $this, 'data_sharing_notice' ) );
		add_action( 'wp_ajax_wsc_dismiss_data_sharing_notice', array( $this, 'dismiss_data_sharing_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_data_sharing_notice_script' ) );
		add_filter( 'plugin_action_links_wc-smart-cod/wc-smart-cod.php', array( $this, 'plugin_action_links' ) );
		add_filter( 'experimental_woocommerce_admin_payment_reactify_render_sections', array( $this, 'woocommerce_smart_cod') );
	}

	public function woocommerce_smart_cod($sections) {
		$cod = 'cod';
		return array_filter($sections, function($v) use ($cod) {
			return $v !== $cod;
		});
	}
	
	public function plugin_action_links( $links ) {
		$plugin_links = array();

		if ( function_exists( 'WC' ) ) {
			$plugin_links[] = '<a href="' . esc_url( self::$plugin_settings_url ) . '">' . esc_html__( 'Settings', 'wc-smart-cod' ) . '</a>';
		}

		return array_merge( $plugin_links, $links );
	}

	/** Displays the one-time optional protection notice throughout wp-admin. */
	public function data_sharing_notice() {
		if ( ! $this->should_show_data_sharing_notice() ) {
			return;
		}

		$settings_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=cod' ) . '#woocommerce_cod_enable_smart_cod_ai_data_sharing';
		?>
		<div class="notice notice-info is-dismissible wsc-data-sharing-notice" data-wsc-dismiss-nonce="<?php echo esc_attr( wp_create_nonce( 'wsc_dismiss_data_sharing_notice' ) ); ?>">
			<p><strong><?php esc_html_e( 'Smart COD for WooCommerce', 'wc-smart-cod' ); ?></strong><?php esc_html_e( ': Reduce repeat uncollected COD orders. COD Protection can hide Cash on Delivery when the same customer matches an eligible order that was dispatched and later cancelled in your store. The service is optional and off by default.', 'wc-smart-cod' ); ?></p>
			<p><a class="button button-primary" href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Enable COD Protection', 'wc-smart-cod' ); ?></a> <a href="https://woosmartcod.com/cancelled-cod-data-sharing/" target="_blank" rel="noopener"><?php esc_html_e( 'How data is used', 'wc-smart-cod' ); ?></a></p>
		</div>
		<?php
	}

	/** Permanently stores the notice dismissal for the current administrator. */
	public function dismiss_data_sharing_notice() {
		check_ajax_referer( 'wsc_dismiss_data_sharing_notice', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error();
		}

		update_user_meta( get_current_user_id(), 'wsc_cod_protection_notice_dismissed', 1 );
		wp_send_json_success();
	}

	/** Loads the small persistence handler for the native WordPress notice close button. */
	public function enqueue_data_sharing_notice_script() {
		if ( ! $this->should_show_data_sharing_notice() ) {
			return;
		}
		wp_enqueue_script( 'wc-smart-cod-data-sharing-notice', dirname( plugin_dir_url( __FILE__ ) ) . '/admin/js/wc-smart-cod-data-sharing-notice.js', array( 'jquery' ), self::$version, true );
	}

	private function should_show_data_sharing_notice() {
		$dismissed = (bool) get_user_meta( get_current_user_id(), 'wsc_cod_protection_notice_dismissed', true );
		if ( ! current_user_can( 'manage_woocommerce' ) || $dismissed ) {
			return false;
		}
		$settings = get_option( 'woocommerce_cod_settings', array() );
		if ( is_array( $settings ) && isset( $settings['enable_smart_cod_ai_data_sharing'] ) && 'yes' === $settings['enable_smart_cod_ai_data_sharing'] ) {
			return false;
		}
		return true;
	}

	public static function wc_version_check( $version = '3.4' ) {
		if ( class_exists( 'WooCommerce' ) ) {
			global $woocommerce;
			if ( version_compare( $woocommerce->version, $version, '>=' ) ) {
				return true;
			}
		}
		return false;
	}

	public function activate_notice() {

		if ( get_transient( 'wc-smart-cod-activated' ) ) :
			?>
			<div class="updated notice is-dismissible">
				<p>
					Thank you for using <strong>Smart COD for WooCommerce</strong>! Setup your settings
					<a href="<?php echo esc_url( self::$plugin_settings_url ); ?>">here</a>.
					If you liked our plugin, consider to give us a rating on
					<a href="https://wordpress.org/plugins/wc-smart-cod/" target="_blank">wordpress.org</a>!
				</p>
			</div>
			<?php
			delete_transient( 'wc-smart-cod-activated' );
		endif;
	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * Include the following files that make up the plugin:
	 *
	 * - Wc_Smart_Cod_Loader. Orchestrates the hooks of the plugin.
	 * - Wc_Smart_Cod_i18n. Defines internationalization functionality.
	 * - Wc_Smart_Cod_Admin. Defines all hooks for the admin area.
	 * - Wc_Smart_Cod_Public. Defines all hooks for the public side of the site.
	 *
	 * Create an instance of the loader which will be used to register the hooks
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */

	public function load_smart_cod( $gateways ) {

		$key = array_search( 'WC_Gateway_COD', $gateways );
		if ( $key ) {
			$gateways[ $key ] = 'Wc_Smart_Cod_Admin';
		}

		return $gateways;
	}

	public function load_dependencies() {

		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// Keep Smart COD AI classes off ordinary storefront requests. They are
		// loaded only by a cancellation, an Action Scheduler job, or wp-admin.
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'capture_ai_cancelled_order' ), 10, 3 );
		add_action( 'wsc_collect_cancelled_cod_orders', array( $this, 'run_ai_history_batch' ) );
		add_action( 'wsc_send_smart_cod_ai_events', array( $this, 'send_ai_order_ids' ), 10, 2 );
		add_action( 'init', array( $this, 'maybe_start_ai_history_schedule' ), 20 );
		if ( is_admin() ) {
			add_action( 'admin_init', array( $this, 'ensure_ai_history_schedule' ) );
		}

		/**
		 * The class responsible for orchestrating the actions and filters of the
		 * core plugin.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-wc-smart-cod-loader.php';

		/**
		 * The class responsible for defining internationalization functionality
		 * of the plugin.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-wc-smart-cod-i18n.php';

		/**
		 * The class responsible for defining all actions that occur in the admin area.
		 */

		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-wc-smart-cod-admin.php';

		/**
		 * The class responsible for defining all actions that occur in the public-facing
		 * side of the site.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'public/class-wc-smart-cod-public.php';

		$this->loader = new Wc_Smart_Cod_Loader();
		$admin_class  = 'Wc_Smart_Cod_Admin';

		add_action( 'wp_ajax_wcsmartcod_json_search_categories', array( $admin_class, 'ajax_search_categories' ) );

		// /$this->define_admin_hooks();
		$this->set_locale();
		$this->define_public_hooks();
		$this->loader->run();

	}

	private function load_ai_collector() {
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-wc-smart-cod-cancelled-cod-collector.php';
	}

	private function load_ai_outbox() {
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-wc-smart-cod-ai-outbox.php';
	}

	public function capture_ai_cancelled_order( $order_id, $order = null, $status_transition = array() ) {
		$this->load_ai_collector();
		Wc_Smart_Cod_Cancelled_Cod_Collector::capture_cancelled_order( $order_id, $order, $status_transition );
	}

	public function run_ai_history_batch() {
		$this->load_ai_collector();
		Wc_Smart_Cod_Cancelled_Cod_Collector::collect_batch();
	}

	public function send_ai_order_ids( $order_ids = array(), $scheduled_evidence = array() ) {
		$this->load_ai_collector();
		$this->load_ai_outbox();
		Wc_Smart_Cod_Ai_Outbox::send_order_ids( $order_ids, $scheduled_evidence );
	}

	public function ensure_ai_history_schedule() {
		try {
			$this->load_ai_collector();
			Wc_Smart_Cod_Cancelled_Cod_Collector::schedule();
		} catch ( Exception $exception ) {
			// A failed local scheduling attempt will be retried on a later request.
		}
	}

	public function maybe_start_ai_history_schedule() {
		try {
			$this->load_ai_collector();
			if ( ! Wc_Smart_Cod_Cancelled_Cod_Collector::is_data_sharing_enabled() ) {
				return;
			}
			if ( Wc_Smart_Cod_Cancelled_Cod_Collector::needs_scan_revision() ) {
				// This only creates one delayed background schedule after an upgrade.
				// It never queries orders or makes an HTTP request on this page load.
				Wc_Smart_Cod_Cancelled_Cod_Collector::schedule();
				return;
			}
		} catch ( Exception $exception ) {
			return;
		}

		$option  = 'wsc_cancelled_cod_collection_started';
		$started = get_option( $option, false );
		if ( in_array( $started, array( 'action-scheduler', 'wp-cron', 'complete' ), true )
			|| ( is_numeric( $started ) && absint( $started ) > time() - 5 * MINUTE_IN_SECONDS ) ) {
			return;
		}
		if ( false === $started ) {
			// add_option is atomic, so concurrent first visits do not both schedule.
			if ( ! add_option( $option, time(), '', true ) ) {
				return;
			}
		} else {
			update_option( $option, time(), true );
		}
		$this->ensure_ai_history_schedule();
	}

	/**
	 * Define the locale for this plugin for internationalization.
	 *
	 * Uses the Wc_Smart_Cod_i18n class in order to set the domain and to register the hook
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function set_locale() {

		$plugin_i18n = new Wc_Smart_Cod_i18n();

		$this->loader->add_action( 'plugins_loaded', $plugin_i18n, 'load_plugin_textdomain' );

	}

	/**
	 * Register all of the hooks related to the public-facing functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_public_hooks() {

		$plugin_public = new Wc_Smart_Cod_Public( $this->get_plugin_name() );

		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_scripts' );

	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @since    1.0.0
	 */
	public function run() {
		$this->loader->run();
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @since     1.0.0
	 * @return    string    The name of the plugin.
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * The reference to the class that orchestrates the hooks with the plugin.
	 *
	 * @since     1.0.0
	 * @return    Wc_Smart_Cod_Loader    Orchestrates the hooks of the plugin.
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @since     1.0.0
	 * @return    string    The version number of the plugin.
	 */
	public function get_version() {
		return self::$version;
	}
}
