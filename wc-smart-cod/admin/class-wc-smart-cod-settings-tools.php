<?php
require_once dirname( __DIR__ ) . '/includes/class-wc-smart-cod-ai-upgrade-link.php';
/** Store-manager backup workflow; never loaded by checkout. */
class Wc_Smart_Cod_Settings_Tools {
	const PAGE = 'wsc-settings-backup';
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_wsc_settings_backup', array( __CLASS__, 'handle' ) );
		add_action( 'admin_notices', array( __CLASS__, 'promotion' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'promotion_assets' ) );
		add_action( 'wp_ajax_wsc_dismiss_ai_offer', array( __CLASS__, 'dismiss_promotion' ) );
	}
	public static function menu() {
		// Keep the backup route accessible from COD settings without adding a menu item.
		add_submenu_page( '', 'Smart COD', 'Smart COD', 'manage_woocommerce', self::PAGE, array( __CLASS__, 'render' ) );
	}
	private static function key() { return 'wsc_backup_preview_' . get_current_user_id(); }
	private static function settings() { $s = get_option( 'woocommerce_cod_settings', array() ); return is_array( $s ) ? $s : array(); }
	public static function fingerprint( $s ) { return hash( 'sha256', serialize( $s ) ); }
	public static function display_value( $value ) {
		return json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}
	public static function field_labels() {
		return array(
			'enabled' => __( 'Enable/Disable', 'woocommerce' ), 'title' => __( 'Title', 'woocommerce' ),
			'description' => __( 'Description', 'woocommerce' ), 'instructions' => __( 'Instructions', 'woocommerce' ),
			'enable_for_virtual' => __( 'Accept COD if the order is virtual', 'woocommerce' ),
			'enable_for_methods' => __( 'Enable for shipping methods', 'woocommerce' ),
			'shipping_zone_restrictions' => __( 'Shipping zones', 'woocommerce' ),
			'country_restrictions' => __( 'Countries', 'woocommerce' ), 'state_restrictions' => __( 'States', 'woocommerce' ),
			'restrict_postals' => __( 'Postcodes', 'woocommerce' ), 'city_restrictions' => __( 'Cities', 'woocommerce' ),
			'user_role_restriction' => __( 'User roles', 'woocommerce' ), 'category_restriction' => __( 'Product categories', 'woocommerce' ),
			'product_restriction' => __( 'Products', 'woocommerce' ), 'shipping_class_restriction' => __( 'Shipping classes', 'woocommerce' ),
			'shipping_zone_method_restriction' => __( 'Shipping methods', 'woocommerce' ),
			'category_restriction_mode' => __( 'Product Category Restriction Mode', 'wc-smart-cod' ),
			'product_restriction_mode' => __( 'Product Restriction Mode', 'wc-smart-cod' ),
			'shipping_class_restriction_mode' => __( 'Shipping Class Restriction Mode', 'wc-smart-cod' ),
			'extra_fee' => __( 'Extra Fee', 'wc-smart-cod' ),
			'method_different_charge_local_pickup' => __( 'Charge extra fee differently for shipping method: ', 'wc-smart-cod' ) . __( 'Local pickup', 'woocommerce' ),
			'percentage_rounding' => __( 'Percentage Rounding Settings', 'wc-smart-cod' ), 'extra_fee_tax' => __( 'Extra Fee Tax', 'wc-smart-cod' ),
			'smart_cod_minimum_success_rate' => __( 'Minimum successful COD rate (%)', 'wc-smart-cod' ),
			'cod_unavailable_message' => __( 'COD Unavailable Message', 'wc-smart-cod' ),
			'restriction_settings' => __( 'Restriction modes', 'wc-smart-cod' ), 'fee_settings' => __( 'Fee types', 'wc-smart-cod' ),
		);
	}
	public static function consume_preview( $token, $current ) {
		$p = get_transient( self::key() );
		delete_transient( self::key() );
		if ( ! is_array( $p ) || ! is_string( $token ) || ! hash_equals( $p['token'], $token ) || ! hash_equals( $p['hash'], self::fingerprint( $current ) ) ) throw new InvalidArgumentException( 'preview' );
		return Wc_Smart_Cod_Settings_Backup::parse( Wc_Smart_Cod_Settings_Backup::export( $p['settings'], home_url( '/' ) ), home_url( '/' ) );
	}
	public static function handle() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( esc_html__( 'Sorry, you are not allowed to access this page.' ), '', array( 'response' => 403 ) );
		check_admin_referer( 'wsc_settings_backup' );
		$action = isset( $_POST['backup_action'] ) ? $_POST['backup_action'] : '';
		try {
			if ( $action === 'export' ) {
				$json = Wc_Smart_Cod_Settings_Backup::export( self::settings(), home_url( '/' ) );
				nocache_headers();
				header( 'Content-Type: application/json; charset=utf-8' );
				header( 'Content-Disposition: attachment; filename="smart-cod-free-settings.json"' );
				header( 'X-Content-Type-Options: nosniff' );
				echo $json;
				exit;
			}
			if ( $action === 'dismiss_ai' ) {
				update_user_meta( get_current_user_id(), 'wsc_ai_offer_dismissed_v1', 1 );
			} elseif ( $action === 'preview' ) {
				delete_transient( self::key() );
				$file = isset( $_FILES['backup_file'] ) ? $_FILES['backup_file'] : null;
				if ( ! is_array( $file ) || ! isset( $file['error'], $file['tmp_name'] ) || $file['error'] !== UPLOAD_ERR_OK || ! is_string( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) || filesize( $file['tmp_name'] ) > Wc_Smart_Cod_Settings_Backup::MAX_BYTES ) throw new InvalidArgumentException( 'upload' );
				$settings = Wc_Smart_Cod_Settings_Backup::parse( file_get_contents( $_FILES['backup_file']['tmp_name'] ), home_url( '/' ) );
				set_transient( self::key(), array( 'settings' => $settings, 'hash' => self::fingerprint( self::settings() ), 'token' => wp_generate_password( 32, false ) ), 10 * MINUTE_IN_SECONDS );
			} elseif ( $action === 'restore' ) {
				$current = self::settings();
				// Revalidate server-side preview and keep all excluded keys untouched.
				$restored = self::consume_preview( isset( $_POST['preview_token'] ) ? $_POST['preview_token'] : null, $current );
				update_option( 'woocommerce_cod_settings', Wc_Smart_Cod_Settings_Backup::merge( $current, $restored ) );
			} elseif ( $action === 'cancel' ) {
				delete_transient( self::key() );
			} else throw new InvalidArgumentException( 'action' );
		} catch ( InvalidArgumentException $e ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&backup_result=invalid' ) ); exit;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . ( $action === 'restore' ? '&backup_result=restored' : '' ) ) ); exit;
	}
	private static function form( $action ) {
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo str_replace( 'id="_wpnonce"', 'id="wsc-backup-nonce-' . esc_attr( $action ) . '"', wp_nonce_field( 'wsc_settings_backup', '_wpnonce', true, false ) );
		echo '<input type="hidden" name="action" value="wsc_settings_backup"><input type="hidden" name="backup_action" value="' . esc_attr( $action ) . '">';
	}
	public static function promotion_enabled() {
		return defined( 'WSC_SMART_COD_AI_LIVE' ) && WSC_SMART_COD_AI_LIVE === true && current_user_can( 'manage_woocommerce' );
	}
	public static function promotion_assets() {
		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? $_GET['page'] : '';
		$cod = $page === 'wc-settings' && isset( $_GET['tab'], $_GET['section'] ) && $_GET['tab'] === 'checkout' && $_GET['section'] === 'cod';
		$notice = self::promotion_enabled() && ! get_user_meta( get_current_user_id(), 'wsc_ai_offer_dismissed_v1', true );
		if ( ! current_user_can( 'manage_woocommerce' ) || ( ! $notice && ! $cod && $page !== self::PAGE ) ) return;
		$css = 'css/wc-smart-cod-ai-promotion.css';
		wp_enqueue_style( 'wc-smart-cod-ai-promotion', plugin_dir_url( __FILE__ ) . $css, array(), (string) filemtime( __DIR__ . '/' . $css ) );
		if ( $notice ) {
			$js = 'js/wc-smart-cod-ai-promotion.js';
			wp_enqueue_script( 'wc-smart-cod-ai-promotion', plugin_dir_url( __FILE__ ) . $js, array( 'jquery' ), (string) filemtime( __DIR__ . '/' . $js ), true );
		}
	}
	public static function dismiss_promotion() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( array(), 403 );
		check_ajax_referer( 'wsc_dismiss_ai_offer', 'nonce' );
		update_user_meta( get_current_user_id(), 'wsc_ai_offer_dismissed_v1', 1 );
		wp_send_json_success();
	}
	public static function promotion( $dismiss_form = true ) {
		// Explicit launch switch. No automatic network probes, analytics or tracking.
		if ( ! self::promotion_enabled() || get_user_meta( get_current_user_id(), 'wsc_ai_offer_dismissed_v1', true ) ) return;
		$url = Wc_Smart_Cod_AI_Upgrade_Link::url( 'free' );
		echo '<div class="notice notice-info is-dismissible wsc-ai-upgrade-notice" data-wsc-dismiss-nonce="' . esc_attr( wp_create_nonce( 'wsc_dismiss_ai_offer' ) ) . '"><div class="wsc-ai-upgrade-notice__inner">'
			. '<p class="wsc-ai-upgrade-notice__source"><strong>Smart COD for WooCommerce</strong><span aria-hidden="true"> — </span>'
			. esc_html__( 'Meet Smart COD AI', 'wc-smart-cod' ) . '</p>'
			. '<p class="wsc-ai-upgrade-notice__message"><strong>' . esc_html__( 'Protect your COD orders from non-collection.', 'wc-smart-cod' ) . '</strong> '
			. esc_html__( 'Add delivery history from connected stores in your country, machine learning and clear reasons for every checkout decision.', 'wc-smart-cod' ) . '</p>'
			. '<p class="wsc-ai-upgrade-notice__actions"><a class="button button-primary" target="_blank" rel="noopener noreferrer" href="' . esc_url( $url ) . '">'
			. esc_html__( 'Try it free for 7 days', 'wc-smart-cod' ) . '</a><a class="wsc-ai-upgrade-notice__learn-more" target="_blank" rel="noopener noreferrer" href="' . esc_url( $url . '#upgrade-benefits-title' ) . '">'
			. esc_html__( 'Learn more', 'wc-smart-cod' ) . '</a></p>';
		echo '</div></div>';
	}
	/** Remains visible on the settings page after the optional notice is dismissed. */
	public static function persistent_promotion() {
		if ( ! self::promotion_enabled() ) return;
		?>
		<section class="wsc-ai-upgrade-card" aria-labelledby="wsc-ai-upgrade-title">
			<div class="wsc-ai-upgrade-card__story">
				<span class="wsc-ai-upgrade-card__brand">Smart COD AI</span>
				<h3 id="wsc-ai-upgrade-title"><?php esc_html_e( 'Your country’s network. Stronger COD protection.', 'wc-smart-cod' ); ?></h3>
				<p><?php esc_html_e( 'New to your store? Their delivery history may already be available in your country’s network. Add network insights, ML and clear checkout decisions.', 'wc-smart-cod' ); ?></p>
			</div>
			<div class="wsc-ai-upgrade-card__start">
				<span class="wsc-ai-upgrade-card__trial"><?php esc_html_e( '7-day free trial', 'wc-smart-cod' ); ?></span>
				<a class="button button-primary" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url( Wc_Smart_Cod_AI_Upgrade_Link::url( 'free' ) ); ?>"><?php esc_html_e( 'Try it free for 7 days', 'wc-smart-cod' ); ?><span aria-hidden="true"> ↗</span></a>
				<p class="wsc-ai-upgrade-card__migration"><?php esc_html_e( 'Your settings come with you — Smart COD AI detects them automatically for review.', 'wc-smart-cod' ); ?></p>
			</div>
			<details class="wsc-ai-upgrade-card__details">
				<summary><?php esc_html_e( 'What you gain with Smart COD AI', 'wc-smart-cod' ); ?></summary>
				<ul>
					<li data-feature="network"><span aria-hidden="true">✓</span><div><strong><?php esc_html_e( 'Protection from your country’s network', 'wc-smart-cod' ); ?></strong><p><?php esc_html_e( 'Use delivery history from connected stores in the same country, even for new customers. Keep COD available for reliable customers; supported tracking adds courier evidence of delivery or return.', 'wc-smart-cod' ); ?></p></div></li>
					<li data-feature="audit"><span aria-hidden="true">✓</span><div><strong><?php esc_html_e( 'See why each checkout decision was made', 'wc-smart-cod' ); ?></strong><p><?php esc_html_e( 'Inspect reasons and checks, with filters and a link to the order when available.', 'wc-smart-cod' ); ?></p></div></li>
					<li data-feature="ml"><span aria-hidden="true">✓</span><div><strong><?php esc_html_e( 'Machine learning', 'wc-smart-cod' ); ?></strong><p><?php esc_html_e( 'Estimate non-collection risk from delivery history and order details.', 'wc-smart-cod' ); ?></p></div></li>
					<li data-feature="fees"><span aria-hidden="true">✓</span><div><strong><?php esc_html_e( 'Flexible COD fees', 'wc-smart-cod' ); ?></strong><p><?php esc_html_e( 'Multiple rules for fixed amounts or percentage fees.', 'wc-smart-cod' ); ?></p></div></li>
					<li data-feature="advance"><span aria-hidden="true">✓</span><div><strong><?php esc_html_e( 'COD with an advance payment', 'wc-smart-cod' ); ?></strong><p><?php esc_html_e( 'Collect a fixed amount or percentage online. Leave the rest payable on delivery.', 'wc-smart-cod' ); ?></p></div></li>
					<li data-feature="rules"><span aria-hidden="true">✓</span><div><strong><?php esc_html_e( 'More flexible restriction rules', 'wc-smart-cod' ); ?></strong><p><?php esc_html_e( 'Combine conditions for customers, carts and shipping.', 'wc-smart-cod' ); ?></p></div></li>
				</ul>
				<p class="wsc-ai-upgrade-card__continuity"><?php esc_html_e( 'Protection continues when a failed card payment is followed by COD.', 'wc-smart-cod' ); ?></p>
			</details>
		</section>
		<?php
	}
	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) return;
		echo '<div class="wrap"><h1>Smart COD — ' . esc_html__( 'Settings backup', 'wc-smart-cod' ) . '</h1><p>' . esc_html__( 'Save and restore Free settings on this store. Sharing consent, keys and order data are not included.', 'wc-smart-cod' ) . '</p>';
		if ( isset( $_GET['backup_result'] ) ) echo '<div class="notice notice-info"><p>' . ( $_GET['backup_result'] === 'restored' ? esc_html__( 'Settings restored.', 'wc-smart-cod' ) : esc_html__( 'The backup is invalid, belongs to another store, or the preview has expired. Create a new preview.', 'wc-smart-cod' ) ) . '</p></div>';
		self::form( 'export' ); submit_button( __( 'Export' ), 'secondary', 'wsc-backup-export' ); echo '</form>';
		self::form( 'preview' ); echo '<label for="backup-file">' . esc_html__( 'Choose a settings backup to preview before restoring.', 'wc-smart-cod' ) . '</label><p><input id="backup-file" type="file" name="backup_file" accept="application/json,.json" required></p>'; submit_button( __( 'Preview' ), 'secondary', 'wsc-backup-preview' ); echo '</form>';
		$p = get_transient( self::key() );
		if ( is_array( $p ) ) {
			$current = Wc_Smart_Cod_Settings_Backup::select( self::settings() );
			$labels = self::field_labels();
			echo '<h2>' . esc_html__( 'Preview' ) . '</h2><table class="widefat striped" style="table-layout:fixed"><thead><tr><th scope="col">' . esc_html__( 'Settings' ) . '</th><th scope="col">' . esc_html__( 'Current settings', 'wc-smart-cod' ) . '</th><th scope="col">' . esc_html__( 'Backup', 'wc-smart-cod' ) . '</th></tr></thead><tbody>';
			foreach ( $p['settings'] as $key => $value ) {
				$old = isset( $current[$key] ) ? $current[$key] : null;
				if ( in_array( $key, array( 'fee_settings', 'restriction_settings' ), true ) ) { $old = is_string( $old ) ? json_decode( $old, true ) : $old; $value = json_decode( $value, true ); }
				echo '<tr><th scope="row" style="overflow-wrap:anywhere">' . esc_html( isset( $labels[$key] ) ? $labels[$key] : $key ) . '</th><td><pre style="white-space:pre-wrap;overflow-wrap:anywhere">' . esc_html( self::display_value( $old ) ) . '</pre></td><td><pre style="white-space:pre-wrap;overflow-wrap:anywhere">' . esc_html( self::display_value( $value ) ) . '</pre></td></tr>';
			}
			echo '</tbody></table><p>' . esc_html__( 'Only the settings shown above will be replaced. Other settings remain unchanged.', 'wc-smart-cod' ) . '</p>';
			self::form( 'restore' ); echo '<input type="hidden" name="preview_token" value="' . esc_attr( $p['token'] ) . '">'; submit_button( __( 'Restore settings', 'wc-smart-cod' ), 'primary', 'wsc-backup-restore' ); echo '</form>';
			self::form( 'cancel' ); submit_button( __( 'Cancel' ), 'secondary', 'wsc-backup-cancel' ); echo '</form>';
		}
		echo '</div>';
	}
}
