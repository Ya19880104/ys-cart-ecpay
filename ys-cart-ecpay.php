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

/**
 * v0.3.0 pair contract: shared payment_detail CAS (YSPaymentDetailStore), stable
 * payment operation keys, refund finalization sync — on top of the 2.56.12
 * typed fulfillment / saved-CVS authority / durable query / address identity set.
 */
define( 'YS_CART_ECPAY_REQUIRES_CORE', '2.57.0' );

add_action(
	'plugins_loaded',
	static function (): void {
		// 🔴 v0.3.0（#2H）：核心缺席時**仍然要讓後台看得見原因**。
		// 直接靜默 return 的結果是：站方只會看到「綠界不見了」，然後去猜。
		if ( ! class_exists( \YangSheep\Ecommerce\Gateways\YSGatewayRegistry::class )
			&& ! class_exists( \YangSheep\Ecommerce\Shipping\YSShippingRegistry::class ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					if ( ! current_user_can( 'activate_plugins' ) ) {
						return;
					}
					printf(
						'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
						esc_html__( 'YS CART - ECPay 未啟用：', 'ys-cart-ecpay' ),
						esc_html( sprintf(
							/* translators: %s: required core version */
							__( '找不到 YS CART 核心外掛。請先安裝並啟用 YS CART %s 或更新版本。', 'ys-cart-ecpay' ),
							YS_CART_ECPAY_REQUIRES_CORE
						) )
					);
				}
			);

			return;
		}

		// 🔴 核心版本／能力不符時：**一個 hook 都不掛**，只顯示後台提示。
		//
		// 「先發核心再發本外掛」是流程約定，不能取代 runtime gate：降版、部分部署、
		// 安裝順序錯誤都會讓本外掛在缺少物流落盤契約的核心上跑起來——而那個組合的
		// 後果是綠界那邊建好了單、本地寫不進去（孤兒單）。
		$ys_cart_ecpay_gate = \YangSheep\YSCartEcpay\Plugin::core_requirements();
		if ( ! $ys_cart_ecpay_gate['met'] ) {
			add_action(
				'admin_notices',
				static function () use ( $ys_cart_ecpay_gate ): void {
					if ( ! current_user_can( 'activate_plugins' ) ) {
						return;
					}
					echo '<div class="notice notice-error"><p><strong>YS CART - ECPay</strong>：'
						. esc_html( $ys_cart_ecpay_gate['message'] )
						. '</p></div>';
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
