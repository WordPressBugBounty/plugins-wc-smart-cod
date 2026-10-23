<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provide a admin area view for the plugin
 *
 * This file is used to markup the admin-facing aspects of the plugin.
 *
 * @link       https://woosmartcod.com/
 * @since      1.0.0
 *
 * @package    Wc_Smart_Cod
 * @subpackage Wc_Smart_Cod/admin/partials
 */
?>

<!-- This file should primarily consist of HTML with a little bit of PHP. -->
<div class="wsc-columns">
	<div class="wsc-content">
		<div class="wc-smart-cod-info">
			<h4><?php echo esc_html( WC_Smart_Cod::$plugin_friendly_name ); ?></h4>
			<p>Version: <strong><?php echo esc_html( WC_Smart_Cod::$version ); ?></strong></p>
		</div>
		<?php Wc_Smart_Cod_Settings_Tools::persistent_promotion(); ?>
		<div class="wsc-backup-shortcut">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=wsc-settings-backup' ) ); ?>"><?php esc_html_e( 'Export or restore your Free settings', 'wc-smart-cod' ); ?></a>
			<span><?php esc_html_e( 'Save your settings to a file or restore a previous backup on this store.', 'wc-smart-cod' ); ?></span>
		</div>
		<table class="form-table">
			<?php
			// WooCommerce generates this form markup; escaping the whole fragment would break its controls.
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $template_data['settings_html'];
			?>
		</table>
	</div>
</div>
