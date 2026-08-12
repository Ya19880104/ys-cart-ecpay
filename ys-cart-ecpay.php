<?php
/**
 * Plugin Name: YS CART - ECPay
 * Plugin URI: https://github.com/Ya19880104/ys-cart-ecpay
 * Description: ECPay AIO payment and domestic logistics provider for YS CART.
 * Version: 0.3.0
 * Requires at least: 6.2
 * Requires PHP: 8.1
 * Requires Plugins: ys-cart
 * Author: YangSheep
 * Text Domain: ys-cart-ecpay
 */

defined( 'ABSPATH' ) || exit;

define( 'YS_CART_ECPAY_VERSION', '0.3.0' );
define( 'YS_CART_ECPAY_FILE', __FILE__ );
define( 'YS_CART_ECPAY_DIR', plugin_dir_path( __FILE__ ) );
define( 'YS_CART_ECPAY_URL', plugin_dir_url( __FILE__ ) );
define( 'YS_CART_ECPAY_BASENAME', plugin_basename( __FILE__ ) );

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
		// 🔴 v0.3.0（#2H）：核心缺席或版本太舊時，**仍然要讓後台看得見原因**。
		//
		// 舊版在這裡直接 `return`：核心沒載入時外掛完全靜默——沒有 gateway、
		// 沒有物流、也沒有任何訊息。站方只會看到「綠界不見了」，然後去猜。
		//
		// `Plugin::init()` 內部已經有版本 gate（不符合就只掛 admin notice），
		// 所以這裡只要處理「核心整個不在」這一種，並且掛上同一種通知。
		if ( ! class_exists( \YangSheep\Ecommerce\Gateways\YSGatewayRegistry::class )
			&& ! class_exists( \YangSheep\Ecommerce\Shipping\YSShippingRegistry::class ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					printf(
						'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
						esc_html__( 'YS CART - ECPay 未啟用：', 'ys-cart-ecpay' ),
						esc_html__( '找不到 YS CART 核心外掛。請先安裝並啟用 YS CART 2.57.0 或更新版本。', 'ys-cart-ecpay' )
					);
				}
			);

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
