<?php
/**
 * Plugin Name:       Midocean Import & Configurator
 * Description:       Synchroniseert Midocean-producten, prijzen, voorraad en printdata naar WooCommerce en voegt een bedrukkingsconfigurator toe aan de productpagina.
 * Version:           0.13.12
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            PromoFun
 * Text Domain:       midocean-import
 * GitHub Plugin URI: justinw012/Midocean-import
 * Primary Branch:    main
 * WC requires at least: 9.0
 */

defined( 'ABSPATH' ) || exit;

define( 'MDO_VERSION', '0.13.12' );
define( 'MDO_FILE', __FILE__ );
define( 'MDO_DIR', plugin_dir_path( __FILE__ ) );
define( 'MDO_URL', plugin_dir_url( __FILE__ ) );

require_once MDO_DIR . 'includes/class-mdo-log.php';
require_once MDO_DIR . 'includes/class-mdo-settings.php';
require_once MDO_DIR . 'includes/class-mdo-api.php';
require_once MDO_DIR . 'includes/class-mdo-json-stream.php';
require_once MDO_DIR . 'includes/class-mdo-normalize.php';
require_once MDO_DIR . 'includes/class-mdo-pricing.php';
require_once MDO_DIR . 'includes/class-mdo-printdata.php';
require_once MDO_DIR . 'includes/class-mdo-importer.php';
require_once MDO_DIR . 'includes/class-mdo-sync.php';
require_once MDO_DIR . 'includes/class-mdo-admin.php';
require_once MDO_DIR . 'includes/class-mdo-frontend.php';
require_once MDO_DIR . 'includes/class-mdo-cart.php';
require_once MDO_DIR . 'includes/class-mdo-order.php';
require_once MDO_DIR . 'includes/class-mdo-dashboard.php';
require_once MDO_DIR . 'includes/class-mdo-stats.php';
require_once MDO_DIR . 'includes/class-mdo-preview.php';
require_once MDO_DIR . 'includes/class-mdo-mockup.php';
require_once MDO_DIR . 'includes/class-mdo-shop.php';
require_once MDO_DIR . 'includes/class-mdo-diag.php';
require_once MDO_DIR . 'includes/class-mdo-shipping.php';
require_once MDO_DIR . 'includes/class-mdo-filter.php';
require_once MDO_DIR . 'includes/class-mdo-home.php';
require_once MDO_DIR . 'includes/class-mdo-tax.php';
require_once MDO_DIR . 'includes/class-mdo-crumbs.php';
require_once MDO_DIR . 'includes/class-mdo-footer.php';
require_once MDO_DIR . 'includes/class-mdo-theme.php';
require_once MDO_DIR . 'includes/class-mdo-search.php';

add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>Midocean Import heeft WooCommerce nodig.</p></div>';
		} );
		return;
	}
	MDO_Sync::init();
	MDO_Admin::init();
	MDO_Frontend::init();
	MDO_Cart::init();
	MDO_Order::init();
	MDO_Dashboard::init();
	MDO_Preview::init();
	MDO_Mockup::init();
	MDO_Shop::init();
	MDO_Diag::init();
	MDO_Shipping::init(); MDO_Filter::init(); MDO_Home::init(); MDO_Tax::init(); MDO_Crumbs::init(); MDO_Footer::init(); MDO_Theme::init(); MDO_Search::init();
	add_action( 'admin_init', array( 'MDO_Importer', 'fix_size_order' ) );
} );

register_activation_hook( __FILE__, function () {
	MDO_Settings::ensure_dirs();
} );

register_deactivation_hook( __FILE__, function () {
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		foreach ( MDO_Sync::all_hooks() as $hook ) {
			as_unschedule_all_actions( $hook, array(), MDO_Sync::GROUP );
		}
	}
} );
