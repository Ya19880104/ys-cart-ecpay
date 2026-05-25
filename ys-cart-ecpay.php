<?php
/**
 * Plugin Name: YS CART - ECPay
 * Plugin URI: https://github.com/Ya19880104/ys-cart-ecpay
 * Description: ECPay AIO payment and domestic logistics provider for YS CART.
 * Version: 0.1.0
 * Requires at least: 6.2
 * Requires PHP: 8.1
 * Requires Plugins: ys-cart
 * Author: YangSheep
 * Text Domain: ys-cart-ecpay
 */

defined( 'ABSPATH' ) || exit;

define( 'YS_CART_ECPAY_VERSION', '0.1.0' );
define( 'YS_CART_ECPAY_FILE', __FILE__ );
define( 'YS_CART_ECPAY_DIR', plugin_dir_path( __FILE__ ) );
define( 'YS_CART_ECPAY_URL', plugin_dir_url( __FILE__ ) );

$ys_cart_ecpay_vendor = YS_CART_ECPAY_DIR . 'vendor/autoload.php';
if ( is_readable( $ys_cart_ecpay_vendor ) ) {
	require_once $ys_cart_ecpay_vendor;
}

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'YangSheep\\YSCartEcpay\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$file     = YS_CART_ECPAY_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( \YangSheep\Ecommerce\Gateways\YSGatewayRegistry::class )
			&& ! class_exists( \YangSheep\Ecommerce\Shipping\YSShippingRegistry::class ) ) {
			return;
		}

		if ( class_exists( \YangSheep\PluginHubClient\YSPluginHubClient::class ) ) {
			\YangSheep\PluginHubClient\YSPluginHubClient::register( [
				'slug'        => 'ys-cart-ecpay',
				'version'     => YS_CART_ECPAY_VERSION,
				'plugin_file' => __FILE__,
				'name'        => 'YS CART - ECPay',
			] );
		}

		\YangSheep\YSCartEcpay\Plugin::instance()->init();
	},
	30
);

