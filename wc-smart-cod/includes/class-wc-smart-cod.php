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

	public static $pro_url = 'https://woosmartcod.com';

	public static $promo_texts;

	public static $plugin_friendly_name = 'Smart COD for WooCommerce';

	public static $plugin_settings_url;

	const SETTINGS_CACHE = 'wc-smart-cod-settings';
	const PROMOS_CACHE = 'wc-smart-cod-notifications';
	const REMOTE_REFRESH_LOCK = 'wc-smart-cod-remote-configuration-refreshing';

	public function __construct() {

		$this->plugin_name = 'wc-smart-cod';
		
		define( 'SMART_COD_VER', '1.9.1' );

		self::$version = SMART_COD_VER;

		$this->load_notification_manager();
		$this->load_settings_manager();

		// Frontend requests and gateway construction never wait for remote copy.
		self::setup_promos();

		self::$plugin_settings_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=cod' );

		add_action( 'plugins_loaded', array( $this, 'load_dependencies' ) );
		add_filter( 'woocommerce_payment_gateways', array( $this, 'load_smart_cod' ) );
		add_action( 'admin_notices', array( $this, 'activate_notice' ) );
		add_action( 'after_plugin_row_wc-smart-cod/wc-smart-cod.php', array( $this, 'add_promo' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'admin_notices', array( $this, 'show_wsc_notice' ) );
		add_filter( 'plugin_action_links_wc-smart-cod/wc-smart-cod.php', array( $this, 'plugin_action_links' ) );
		add_filter( 'experimental_woocommerce_admin_payment_reactify_render_sections', array( $this, 'woocommerce_smart_cod') );
		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'refresh_remote_configuration' ) );
		}
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

		$plugin_links[] = '<a style="font-weight: bold;" href="' . self::$pro_url . '?utm_source=plugin&utm_medium=support">' . esc_html__( 'Premium Support', 'wc-smart-cod' ) . '</a>';

		return array_merge( $plugin_links, $links );
	}

	public function show_wsc_notice() {

		if( ! function_exists( 'get_current_screen' ) || ! function_exists( 'wc_get_screen_ids' ) ) {
			return;
		}

		$screen    = get_current_screen();
		$screen_id = $screen ? $screen->id : '';

		if ( ! in_array( $screen_id, wc_get_screen_ids(), true ) && 'dashboard' !== $screen_id && 'plugins' !== $screen_id ) {
			return;
		}

		if ( get_transient( 'wsc-notice-dismissed' ) ) {
			return;
		}

		$notification    = self::get_promo( 'notification' );
		$current_section = isset( $_GET['section'] ) ? $_GET['section'] : '';

		if ( isset( $screen->base ) && $screen->base === 'woocommerce_page_wc-settings' && $current_section === 'cod' ) {
			$notification .= '<span class="extra-line">' . self::get_promo( 'user-upgrade', 'coupon' ) . '</span>';
		}

		ob_start(); ?>
			<div class="wsc-pro-notice notice notice-success">
				<p>
					<button type="button" class="wsc-dismiss notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>
					<?php echo $notification; ?>
				</p>
			</div>
		<?php
		return ob_end_flush();
	}

	public function add_promo( $plugins ) {
		echo '
		<tr class="active wsc-pro-after-plugin">
			<td colspan="3">
				<div class="notice inline notice-success notice-alt">
					<p class="small">
					' . self::get_promo( 'plugins_screen' ) . '
					</p>
				</div>
			</td>
		</tr>';
	}

	private static function setup_promos() {
		
		$promos = get_transient( self::PROMOS_CACHE );

		if ( self::valid_promos( $promos ) ) {
			self::$promo_texts = $promos;
			return;
		}

		$promos = array(
			'generic'  => 'Try <strong><a href="%s" target="_blank" target="_blank" rel="noopener">Smart COD PRO for WooCommerce</a></strong> for <strong>more restrictions</strong>, <strong>unlimited extra fees</strong> and <strong>Risk Free COD</strong>, to list only some, of the new features!',
			'sidebar'  => '<strong>A business class, cash on delivery management tool</strong>. Reliable, secure and fully customizable, <span>with a highly engaged and dedicated support team!</span>',
			'coupon'   => 'As a <strong>valued Smart COD for WooCommerce free user, you receive 20%% off</strong>, by visiting <a href="%s" target="_blank" target="_blank" rel="noopener">our shop</a> and using the coupon, you will find there!',
			'features' => array(
				'Risk Free COD (advance payment to secure cod)',
				'Unlimited extra fees (as many restriction scenario\'s as you need)',
				'COD extra fees, now applicable on order-pay page (after failed order)',
				'All available restrictions, now applicable on extra fees',
				'Upload restrictions with CSV',
				'Cart amount range restrictions',
				'Cart quantity range restrictions',
				'User based restrictions',
				'Stock based restrictions',
				'Backorder based restrictions',
				'Coupon based restrictions',
				'Hide/show fee on cart page',
			),
		);

		self::$promo_texts = $promos;
	}

	public static function get_promo( $utm_medium, $pos = 'generic' ) {
		self::setup_promos();
		$promo  = self::$promo_texts[ $pos ];
		$url    = self::$pro_url . '?utm_source=plugin&utm_medium=' . $utm_medium;
		return sprintf( $promo, $url );
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

	public static function get_settings_manager() {
		$settings = get_transient( self::SETTINGS_CACHE );

		if ( self::valid_settings_manager( $settings ) ) {
			return $settings;
		}

		// This fallback is used only until an administrator refreshes the remote
		// configuration. It must never trigger a storefront HTTP request.
		return array( 'e' => array(), 'd' => array(), 'l' => '' );
	}

	private static function valid_settings_manager( $settings ) {
		if ( ! is_array( $settings ) || ! isset( $settings['e'], $settings['d'], $settings['l'] )
			|| ! is_array( $settings['e'] ) || ! is_array( $settings['d'] ) || ! is_string( $settings['l'] ) ) {
			return false;
		}
		foreach ( array_merge( $settings['e'], $settings['d'] ) as $key ) {
			if ( ! is_string( $key ) ) {
				return false;
			}
		}
		return true;
	}

	private static function valid_promos( $promos ) {
		if ( ! is_array( $promos ) || ! isset( $promos['generic'], $promos['coupon'], $promos['sidebar'], $promos['features'] )
			|| ! is_array( $promos['features'] ) ) {
			return false;
		}
		foreach ( array( 'generic', 'coupon', 'sidebar' ) as $key ) {
			if ( ! is_string( $promos[ $key ] ) ) {
				return false;
			}
		}
		foreach ( $promos['features'] as $feature ) {
			if ( ! is_string( $feature ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Refreshes optional remote configuration only in wp-admin. A short lock
	 * avoids duplicate concurrent refreshes; failures retry on the next eligible
	 * admin request.
	 */
	public static function refresh_remote_configuration() {
		if ( ! is_admin() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$needs_settings = ! self::valid_settings_manager( get_transient( self::SETTINGS_CACHE ) );
		$needs_promos   = ! self::valid_promos( get_transient( self::PROMOS_CACHE ) );
		if ( ( ! $needs_settings && ! $needs_promos ) || get_transient( self::REMOTE_REFRESH_LOCK ) ) {
			return;
		}

		set_transient( self::REMOTE_REFRESH_LOCK, 1, 3 * MINUTE_IN_SECONDS );
		try {
			if ( $needs_settings ) {
				$settings = ( new Wc_Smart_Cod_Settings_Manager( self::$pro_url ) )->fetch_settings_manager();
				if ( self::valid_settings_manager( $settings ) ) {
					set_transient( self::SETTINGS_CACHE, $settings, 12 * HOUR_IN_SECONDS );
				}
			}

			if ( $needs_promos ) {
				$promos = ( new Wc_Smart_Cod_Notification_Settings( self::$pro_url ) )->fetch_settings();
				if ( self::valid_promos( $promos ) ) {
					set_transient( self::PROMOS_CACHE, $promos, 12 * HOUR_IN_SECONDS );
				}
			}
		} catch ( Exception $exception ) {
			// Optional remote settings must never break the merchant admin.
		} finally {
			delete_transient( self::REMOTE_REFRESH_LOCK );
		}
	}

	public function activate_notice() {

		if ( get_transient( 'wc-smart-cod-activated' ) ) :
			?>
			<div class="updated notice is-dismissible">
				<p>Thank you for using <strong>Smart COD for WooCommerce</strong>! Setup your settings <a href="<?php echo self::$plugin_settings_url; ?>">here</a>. If you liked our plugin, consider to give us a rating on <a href="https://wordpress.org/plugins/wc-smart-cod/" target="_blank">wordpress.org</a>!</p>
				<p><?php self::get_promo( 'activation' ); ?></p>
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

	public function load_notification_manager() {
		/**
		 * Class responsible for notifications
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-wc-smart-cod-notification-settings.php';
	}

	public function load_settings_manager() {
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-wc-smart-cod-settings-manager.php';
	}

	public function load_dependencies() {

		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// Keep Smart COD AI classes off ordinary storefront requests. They are
		// loaded only by a cancellation, an Action Scheduler job, or wp-admin.
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'capture_ai_cancelled_order' ), 10, 1 );
		add_action( 'wsc_collect_cancelled_cod_orders', array( $this, 'run_ai_history_batch' ) );
		add_action( 'wsc_send_smart_cod_ai_events', array( $this, 'send_ai_order_ids' ), 10, 1 );
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
		add_action( 'wp_ajax_dismiss_wsc_notice', array( $this, 'dismiss_wsc_notice' ) );

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

	public function capture_ai_cancelled_order( $order_id ) {
		$this->load_ai_collector();
		Wc_Smart_Cod_Cancelled_Cod_Collector::capture_cancelled_order( $order_id );
	}

	public function run_ai_history_batch() {
		$this->load_ai_collector();
		Wc_Smart_Cod_Cancelled_Cod_Collector::collect_batch();
	}

	public function send_ai_order_ids( $order_ids = array() ) {
		$this->load_ai_collector();
		$this->load_ai_outbox();
		Wc_Smart_Cod_Ai_Outbox::send_order_ids( $order_ids );
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

	public function dismiss_wsc_notice() {
		set_transient( 'wsc-notice-dismissed', true, 5184000 );
		die();
	}

	public function enqueue_scripts() {
		wp_enqueue_style( $this->get_plugin_name() . '-pro', dirname( plugin_dir_url( __FILE__ ) ) . '/admin/css/wc-smart-cod-pro-admin.css', array(), self::$version, 'all' );
		wp_enqueue_script( $this->get_plugin_name() . '-pro', dirname( plugin_dir_url( __FILE__ ) ) . '/admin/js/wc-smart-cod-pro-admin.js', array( 'jquery' ), self::$version, false );
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
