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
		<table class="form-table">
			<?php
			// WooCommerce generates this form markup; escaping the whole fragment would break its controls.
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $template_data['settings_html'];
			?>
		</table>
		<p class="description wsc-pro-features">
			<?php esc_html_e( 'A separate Smart COD PRO plugin offers advance-payment COD, multiple conditional extra fees, order-pay fee handling, CSV restriction imports, and additional cart, customer, stock, backorder and coupon rules. These features are not part of the Free plugin.', 'wc-smart-cod' ); ?>
			<a href="<?php echo esc_url( 'https://woosmartcod.com/product/woocommerce-smart-cod-pro/' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Explore PRO features', 'wc-smart-cod' ); ?></a>.
		</p>
	</div>
</div>
