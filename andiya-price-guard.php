<?php
/**
 * Plugin Name: Pricing Manager for WooCommerce
 * Description: مدیریت فارسی اعتبار قیمت، تغییر گروهی قیمت و استعلام در ووکامرس.
 * Version: 0.2.0
 * Author: Amin Ansari
 * Author URI: https://aminansari.ir
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: andiya-price-guard
 * Domain Path: /languages
 * Requires at least: 6.8
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * WC requires at least: 10.0
 * WC tested up to: 11.1
 */
defined( 'ABSPATH' ) || exit;
define( 'APG_VERSION', '0.2.0' );
define( 'APG_FILE', __FILE__ );
define( 'APG_DIR', plugin_dir_path( __FILE__ ) );
define( 'APG_URL', plugin_dir_url( __FILE__ ) );
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( 'Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);
foreach ( array( 'support', 'storage', 'operations', 'policy', 'quotes', 'sources', 'reports', 'admin', 'privacy' ) as $andiya_pg_module ) {
	require_once APG_DIR . 'includes/class-' . $andiya_pg_module . '.php';
}
register_activation_hook( __FILE__, array( 'Andiya\\PriceGuard\\Storage', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Andiya\\PriceGuard\\Storage', 'deactivate' ) );
add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-warning"><p>' . esc_html__( 'برای استفاده از «مدیریت قیمت ووکامرس»، ووکامرس را فعال کنید.', 'andiya-price-guard' ) . '</p></div>';
				}
			);
			return;
		}
		foreach ( array( 'Storage', 'Operations', 'Policy', 'Quotes', 'Sources', 'Reports', 'Admin', 'Privacy' ) as $andiya_pg_class ) {
			call_user_func( array( 'Andiya\\PriceGuard\\' . $andiya_pg_class, 'register' ) );
		}
	},
	20
);
