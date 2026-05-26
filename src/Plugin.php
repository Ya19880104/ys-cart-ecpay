<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay;

defined( 'ABSPATH' ) || exit;

use YangSheep\Ecommerce\Api\Storefront\YSRequestParser;
use YangSheep\Ecommerce\Api\Storefront\YSRestAuth;
use YangSheep\Ecommerce\Api\Storefront\YSRestResponder;
use YangSheep\Ecommerce\Gateways\YSGatewayRegistry;
use YangSheep\Ecommerce\Shipping\YSShippingRegistry;
use YangSheep\YSCartEcpay\Admin\EcpaySettings;
use YangSheep\YSCartEcpay\Api\EcpayLogisticsController;
use YangSheep\YSCartEcpay\Api\EcpayPaymentController;
use YangSheep\YSCartEcpay\Api\EcpayPrintController;
use YangSheep\YSCartEcpay\Payment\EcpayAtmGateway;
use YangSheep\YSCartEcpay\Payment\EcpayBarcodeGateway;
use YangSheep\YSCartEcpay\Payment\EcpayCreditGateway;
use YangSheep\YSCartEcpay\Payment\EcpayCvsGateway;
use YangSheep\YSCartEcpay\Services\Shipping\Adapters\EcpayShippingAdapter;
use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShipping;
use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShippingFamily;
use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShippingHilife;
use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShippingPost;
use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShippingRequester;
use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShippingTcat;
use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShippingUnimart;
use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayStoreSelector;
use YangSheep\YSCartEcpay\Support\Settings;

final class Plugin {
	private static ?self $instance = null;

	private const REGISTERED_GATEWAY_IDS = [
		'ys_ec_ecpay_credit',
		'ys_ec_ecpay_atm',
		'ys_ec_ecpay_cvs',
		'ys_ec_ecpay_barcode',
	];

	private const REGISTERED_SHIPPING_IDS = [
		'ys_ec_ecpay_ship_family',
		'ys_ec_ecpay_ship_unimart',
		'ys_ec_ecpay_ship_hilife',
		'ys_ec_ecpay_ship_tcat',
		'ys_ec_ecpay_ship_post',
	];

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function init(): void {
		EcpaySettings::register();
		EcpayPrintController::register();

		add_filter( 'ys_ec_provider_manifests', [ $this, 'register_manifest' ], 10, 1 );
		add_action( 'ys_ec_register_gateways', [ $this, 'register_gateways' ] );
		add_action( 'ys_ec_register_shipping_methods', [ $this, 'register_shipping_methods' ] );
		add_action( 'ys_ec_register_admin_rest_routes', [ $this, 'register_admin_routes' ] );
		add_action( 'ys_ec_register_storefront_routes', [ $this, 'register_storefront_routes' ] );
		add_action( 'rest_api_init', [ $this, 'register_public_routes' ] );
		add_filter( 'ys_ec_shipping_requester', [ $this, 'register_shipping_requester' ], 10, 2 );
		add_filter( 'ys_ec_shipping_carrier_adapter', [ $this, 'register_carrier_adapter' ], 10, 2 );
		add_filter( 'ys_ec_shipping_provider_labels', [ $this, 'register_shipping_provider_label' ] );
	}

	/**
	 * @param array<int,array<string,mixed>> $manifests
	 * @return array<int,array<string,mixed>>
	 */
	public function register_manifest( array $manifests ): array {
		$manifests[] = self::manifest();

		return $manifests;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function manifest(): array {
		static $manifest = null;

		if ( null === $manifest ) {
			$manifest = require YS_CART_ECPAY_DIR . 'manifest.php';
		}

		return $manifest;
	}

	public function register_gateways(): void {
		if ( ! class_exists( YSGatewayRegistry::class ) || ! $this->is_payment_enabled() ) {
			return;
		}

		if ( $this->is_method_enabled( 'payment', 'ys_ec_ecpay_credit' ) ) {
			YSGatewayRegistry::register( new EcpayCreditGateway() );
		}
		if ( $this->is_method_enabled( 'payment', 'ys_ec_ecpay_atm' ) ) {
			YSGatewayRegistry::register( new EcpayAtmGateway() );
		}
		if ( $this->is_method_enabled( 'payment', 'ys_ec_ecpay_cvs' ) ) {
			YSGatewayRegistry::register( new EcpayCvsGateway() );
		}
		if ( $this->is_method_enabled( 'payment', 'ys_ec_ecpay_barcode' ) ) {
			YSGatewayRegistry::register( new EcpayBarcodeGateway() );
		}
	}

	public function register_shipping_methods(): void {
		if ( ! class_exists( YSShippingRegistry::class ) || ! $this->is_shipping_enabled() ) {
			return;
		}

		if ( $this->is_method_enabled( 'shipping', 'ys_ec_ecpay_ship_family' ) ) {
			YSShippingRegistry::register( new EcpayShippingFamily() );
		}

		if ( $this->is_method_enabled( 'shipping', 'ys_ec_ecpay_ship_unimart' ) ) {
			YSShippingRegistry::register( new EcpayShippingUnimart() );
		}

		if ( $this->is_method_enabled( 'shipping', 'ys_ec_ecpay_ship_hilife' ) ) {
			YSShippingRegistry::register( new EcpayShippingHilife() );
		}

		if ( $this->is_method_enabled( 'shipping', 'ys_ec_ecpay_ship_tcat' ) ) {
			YSShippingRegistry::register( new EcpayShippingTcat() );
		}

		if ( $this->is_method_enabled( 'shipping', 'ys_ec_ecpay_ship_post' ) ) {
			YSShippingRegistry::register( new EcpayShippingPost() );
		}
	}

	public function register_admin_routes( $registrar = null ): void {
		unset( $registrar );
	}

	public function register_storefront_routes( string $namespace ): void {
		if ( ! $this->is_shipping_enabled() ) {
			return;
		}

		register_rest_route(
			$namespace,
			'/stores/ecpay/map-url',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'ecpay_map_url' ],
				'permission_callback' => [ YSRestAuth::class, 'permission_customer_or_guest_write' ],
			]
		);
	}

	public function register_public_routes(): void {
		if ( $this->is_payment_enabled() ) {
			EcpayPaymentController::register_routes();
		}

		if ( ! $this->is_shipping_enabled() ) {
			return;
		}

		EcpayLogisticsController::register_routes();

		register_rest_route(
			'ys-ecommerce/v1',
			'/ecpay/store-callback',
			[
				'methods'             => 'POST',
				'callback'            => [ EcpayStoreSelector::class, 'handle_store_callback' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	public function ecpay_map_url( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->is_shipping_enabled() ) {
			return YSRestResponder::error( 'provider_disabled', '綠界物流尚未啟用。' );
		}

		$params      = YSRequestParser::params( $request );
		$shipping_id = sanitize_text_field( $params['shipping_id'] ?? '' );
		$context     = sanitize_key( $params['context'] ?? 'checkout' );
		$order_id    = absint( $params['order_id'] ?? 0 );

		if ( '' === $shipping_id ) {
			return YSRestResponder::error( 'missing_shipping_id', '缺少物流方式 ID。' );
		}

		if ( ! $this->is_method_enabled( 'shipping', $shipping_id ) ) {
			return YSRestResponder::error( 'shipping_method_disabled', '綠界物流方式尚未啟用。' );
		}

		$result = EcpayStoreSelector::build_map_form_data( $shipping_id, $context, $order_id );
		if ( $result ) {
			return YSRestResponder::success( 'map_url_ready', '', $result );
		}

		return YSRestResponder::error( 'map_url_failed', '綠界物流設定尚未完成或不支援此物流方式。' );
	}

	public function register_shipping_requester( $requester, $method ) {
		if ( null !== $requester ) {
			return $requester;
		}

		if ( ! $this->is_shipping_enabled() ) {
			return $requester;
		}

		if ( $method instanceof EcpayShipping ) {
			return new EcpayShippingRequester( $method );
		}

		return $requester;
	}

	public function register_carrier_adapter( $adapter, string $provider_key ) {
		if ( null !== $adapter ) {
			return $adapter;
		}

		if ( ! $this->is_shipping_enabled() ) {
			return $adapter;
		}

		if ( 'ecpay' === $provider_key ) {
			return new EcpayShippingAdapter();
		}

		return $adapter;
	}

	/**
	 * @param array<string,string> $labels
	 * @return array<string,string>
	 */
	public function register_shipping_provider_label( array $labels ): array {
		if ( ! $this->is_shipping_enabled() ) {
			return $labels;
		}

		$labels['ecpay'] = 'ECPay';

		return $labels;
	}

	private function is_provider_enabled(): bool {
		if ( class_exists( '\YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleState' ) ) {
			return \YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleState::is_provider_enabled( 'ys_ecpay', self::manifest() );
		}

		return Settings::enabled();
	}

	private function is_payment_enabled(): bool {
		if ( class_exists( '\YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleState' ) ) {
			return \YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleState::is_capability_enabled( 'ys_ecpay', 'payment', self::manifest() );
		}

		return $this->is_provider_enabled();
	}

	private function is_shipping_enabled(): bool {
		if ( class_exists( '\YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleState' ) ) {
			return \YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleState::is_capability_enabled( 'ys_ecpay', 'shipping', self::manifest() );
		}

		return $this->is_provider_enabled();
	}

	private function is_method_enabled( string $domain, string $method_id ): bool {
		if ( class_exists( '\YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleState' ) ) {
			return \YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleState::is_method_enabled( $domain, $method_id, self::manifest() );
		}

		if ( 'payment' === $domain ) {
			$legacy_map = [
				'ys_ec_ecpay_credit'  => 'credit',
				'ys_ec_ecpay_atm'     => 'atm',
				'ys_ec_ecpay_cvs'     => 'cvs',
				'ys_ec_ecpay_barcode' => 'barcode',
			];
			return isset( $legacy_map[ $method_id ] ) && Settings::gateway_enabled( $legacy_map[ $method_id ] );
		}

		if ( 'shipping' === $domain ) {
			$legacy_map = [
				'ys_ec_ecpay_ship_family'  => 'ship_family',
				'ys_ec_ecpay_ship_unimart' => 'ship_unimart',
				'ys_ec_ecpay_ship_hilife'  => 'ship_hilife',
				'ys_ec_ecpay_ship_tcat'    => 'ship_tcat',
				'ys_ec_ecpay_ship_post'    => 'ship_post',
			];
			return isset( $legacy_map[ $method_id ] ) && Settings::shipping_enabled( $legacy_map[ $method_id ] );
		}

		return false;
	}
}
