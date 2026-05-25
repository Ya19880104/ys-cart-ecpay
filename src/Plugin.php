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

		add_action( 'ys_ec_register_gateways', [ $this, 'register_gateways' ] );
		add_action( 'ys_ec_register_shipping_methods', [ $this, 'register_shipping_methods' ] );
		add_filter( 'ys_ec_providers', [ $this, 'register_provider' ] );
		add_action( 'ys_ec_admin_payment_menus', [ $this, 'register_admin_menu' ], 10, 2 );
		add_action( 'ys_ec_register_admin_rest_routes', [ $this, 'register_admin_routes' ] );
		add_action( 'ys_ec_register_storefront_routes', [ $this, 'register_storefront_routes' ] );
		add_action( 'rest_api_init', [ $this, 'register_public_routes' ] );
		add_filter( 'ys_ec_shipping_requester', [ $this, 'register_shipping_requester' ], 10, 2 );
		add_filter( 'ys_ec_shipping_carrier_adapter', [ $this, 'register_carrier_adapter' ], 10, 2 );
		add_filter( 'ys_ec_shipping_provider_labels', [ $this, 'register_shipping_provider_label' ] );
		add_filter( 'ys_ec_external_admin_pages', [ $this, 'register_external_admin_page' ] );
	}

	public function register_gateways(): void {
		if ( ! class_exists( YSGatewayRegistry::class ) ) {
			return;
		}

		YSGatewayRegistry::register( new EcpayCreditGateway() );
		YSGatewayRegistry::register( new EcpayAtmGateway() );
		YSGatewayRegistry::register( new EcpayCvsGateway() );
		YSGatewayRegistry::register( new EcpayBarcodeGateway() );
	}

	public function register_shipping_methods(): void {
		if ( ! class_exists( YSShippingRegistry::class ) ) {
			return;
		}

		YSShippingRegistry::register( new EcpayShippingFamily() );
		YSShippingRegistry::register( new EcpayShippingUnimart() );
		YSShippingRegistry::register( new EcpayShippingHilife() );
		YSShippingRegistry::register( new EcpayShippingTcat() );
		YSShippingRegistry::register( new EcpayShippingPost() );
	}

	/**
	 * @param array<string,array<string,mixed>> $providers
	 * @return array<string,array<string,mixed>>
	 */
	public function register_provider( array $providers ): array {
		$providers['ecpay'] = [
			'name'        => 'ECPay',
			'icon'        => 'dashicons-money-alt',
			'description' => 'ECPay AIO payment and domestic logistics for YS CART.',
			'payment'     => [ 'Credit Card', 'ATM', 'CVS Code', 'Barcode' ],
			'shipping'    => [ '7-ELEVEN', 'FamilyMart', 'Hi-Life', 'TCAT', 'Post' ],
			'setting_key' => 'ys_ec_ecpay_enabled',
			'admin_url'   => 'admin.php?page=ys-ecommerce-ecpay',
		];

		return $providers;
	}

	public function register_admin_menu( string $parent_slug, string $capability ): void {
		add_submenu_page(
			$parent_slug,
			'ECPay Settings',
			'ECPay',
			$capability,
			'ys-ecommerce-ecpay',
			[ EcpaySettings::class, 'render_page' ]
		);
	}

	public function register_admin_routes( $registrar = null ): void {
		unset( $registrar );
	}

	public function register_storefront_routes( string $namespace ): void {
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
		EcpayPaymentController::register_routes();
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
		$params      = YSRequestParser::params( $request );
		$shipping_id = sanitize_text_field( $params['shipping_id'] ?? '' );
		$context     = sanitize_key( $params['context'] ?? 'checkout' );
		$order_id    = absint( $params['order_id'] ?? 0 );

		if ( '' === $shipping_id ) {
			return YSRestResponder::error( 'missing_shipping_id', 'Missing shipping method ID.' );
		}

		$result = EcpayStoreSelector::build_map_form_data( $shipping_id, $context, $order_id );
		if ( $result ) {
			return YSRestResponder::success( 'map_url_ready', '', $result );
		}

		return YSRestResponder::error( 'map_url_failed', 'ECPay logistics settings are incomplete or unsupported.' );
	}

	public function register_shipping_requester( $requester, $method ) {
		if ( null !== $requester ) {
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
		$labels['ecpay'] = 'ECPay';

		return $labels;
	}

	/**
	 * @param array<int,string> $pages
	 * @return array<int,string>
	 */
	public function register_external_admin_page( array $pages ): array {
		$pages[] = 'ys-ecommerce-ecpay';
		return array_values( array_unique( $pages ) );
	}
}
