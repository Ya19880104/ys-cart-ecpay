<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Admin;

defined( 'ABSPATH' ) || exit;

use YangSheep\Ecommerce\Admin\YSAdminApp;
use YangSheep\YSCartEcpay\Support\Settings;

final class EcpaySettings {
	private const NONCE_ACTION = 'ys_cart_ecpay_save_settings';
	private const DEFAULT_TAB = 'api';
	private const TABS = [
		'api'         => 'API',
		'payment'     => 'Payment',
		'shipping'    => 'Shipping',
		'diagnostics' => 'Diagnostics',
	];

	public static function register(): void {
		add_action( 'admin_post_ys_cart_ecpay_save_settings', [ __CLASS__, 'handle_save' ] );
	}

	public static function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ys-cart-ecpay' ), 403 );
		}

		check_admin_referer( self::NONCE_ACTION );

		$tab = self::normalize_tab( sanitize_key( wp_unslash( (string) ( $_POST['ys_ec_ecpay_tab'] ?? self::DEFAULT_TAB ) ) ) );

		Settings::update( Settings::ENABLED, isset( $_POST['ys_ec_ecpay_enabled'] ) ? '1' : '0' );

		if ( 'api' === $tab ) {
			self::save_credentials_group( 'payment', Settings::PAYMENT_KEYS );
			self::save_credentials_group( 'logistics', Settings::LOGISTICS_KEYS );
		}

		if ( 'payment' === $tab ) {
			self::save_method_switches( [ 'credit', 'atm', 'cvs', 'barcode' ] );
		}

		if ( 'shipping' === $tab ) {
			self::save_method_switches( [ 'ship_family', 'ship_unimart', 'ship_hilife', 'ship_tcat', 'ship_post' ] );
			self::save_sender_fields();
		}

		wp_safe_redirect(
			add_query_arg(
				[
					'page'    => 'ys-ecommerce-ecpay',
					'tab'     => $tab,
					'updated' => '1',
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * @param array<string,string> $keys
	 */
	private static function save_credentials_group( string $prefix, array $keys ): void {
		Settings::update( $keys['test_mode'], isset( $_POST[ 'ys_ec_ecpay_' . $prefix . '_test_mode' ] ) ? '1' : '0' );
		Settings::update(
			$keys['merchant_id'],
			sanitize_text_field( wp_unslash( (string) ( $_POST[ 'ys_ec_ecpay_' . $prefix . '_merchant_id' ] ?? '' ) ) )
		);

		foreach ( [ 'hash_key', 'hash_iv' ] as $secret_key ) {
			$raw = trim( (string) wp_unslash( $_POST[ 'ys_ec_ecpay_' . $prefix . '_' . $secret_key ] ?? '' ) );
			if ( '' !== $raw ) {
				Settings::update( $keys[ $secret_key ], Settings::encrypt_secret( $raw ) );
			}
		}
	}

	/**
	 * @param array<int,string> $aliases
	 */
	private static function save_method_switches( array $aliases ): void {
		foreach ( $aliases as $alias ) {
			$setting_key = Settings::METHOD_KEYS[ $alias ] ?? '';
			if ( '' === $setting_key ) {
				continue;
			}
			Settings::update( $setting_key, isset( $_POST[ 'ys_ec_ecpay_' . $alias . '_enabled' ] ) ? '1' : '0' );
		}
	}

	private static function save_sender_fields(): void {
		foreach ( Settings::SENDER_KEYS as $alias => $setting_key ) {
			$value = sanitize_text_field( wp_unslash( (string) ( $_POST[ 'ys_ec_ecpay_sender_' . $alias ] ?? '' ) ) );
			Settings::update( $setting_key, $value );
		}
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ys-cart-ecpay' ), 403 );
		}

		$settings = self::settings_for_render();
		$nonce_action = self::NONCE_ACTION;

		if ( class_exists( YSAdminApp::class ) ) {
			YSAdminApp::open( 'ECPay Settings', 'Payment / ECPay' );
		}

		$template = YS_CART_ECPAY_DIR . 'templates/admin/ecpay-settings.php';
		if ( is_readable( $template ) ) {
			include $template;
		} else {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'ECPay settings template is missing.', 'ys-cart-ecpay' ) . '</p></div>';
		}

		if ( class_exists( YSAdminApp::class ) ) {
			YSAdminApp::close();
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function settings_for_render(): array {
		$tab = self::normalize_tab( sanitize_key( wp_unslash( (string) ( $_GET['tab'] ?? self::DEFAULT_TAB ) ) ) );
		$out = [
			'enabled'               => '1' === (string) Settings::get( Settings::ENABLED, '0' ),
			'tab'                   => $tab,
			'tabs'                  => self::TABS,
			'page_url'              => admin_url( 'admin.php?page=ys-ecommerce-ecpay' ),
			'shipping_settings_url' => admin_url( 'admin.php?page=ys-ec-shipping' ),
			'callback_urls'         => [
				'payment_notify'   => rest_url( 'ys-ecommerce/v1/ecpay/notify' ),
				'payment_info'     => rest_url( 'ys-ecommerce/v1/ecpay/payment-info' ),
				'payment_return'   => rest_url( 'ys-ecommerce/v1/ecpay/return' ),
				'store_callback'   => rest_url( 'ys-ecommerce/v1/ecpay/store-callback' ),
				'logistics_notify' => rest_url( 'ys-ecommerce/v1/ecpay/logistics-notify' ),
				'store_map'        => rest_url( 'ys-ecommerce-headless/v1/stores/ecpay/map-url' ),
			],
			'payment_methods'       => [
				'credit'  => 'Credit Card',
				'atm'     => 'ATM',
				'cvs'     => 'CVS Code',
				'barcode' => 'Barcode',
			],
			'shipping_methods'      => [
				'ship_family'  => [ 'label' => 'FamilyMart', 'id' => 'ys_ec_ecpay_ship_family' ],
				'ship_unimart' => [ 'label' => '7-ELEVEN', 'id' => 'ys_ec_ecpay_ship_unimart' ],
				'ship_hilife'  => [ 'label' => 'Hi-Life', 'id' => 'ys_ec_ecpay_ship_hilife' ],
				'ship_tcat'    => [ 'label' => 'TCAT', 'id' => 'ys_ec_ecpay_ship_tcat' ],
				'ship_post'    => [ 'label' => 'Post', 'id' => 'ys_ec_ecpay_ship_post' ],
			],
		];

		foreach ( [ 'payment' => Settings::PAYMENT_KEYS, 'logistics' => Settings::LOGISTICS_KEYS ] as $prefix => $keys ) {
			$out[ $prefix . '_test_mode' ] = '1' === (string) Settings::get( $keys['test_mode'], '1' );
			$out[ $prefix . '_merchant_id' ] = (string) Settings::get( $keys['merchant_id'], '' );
			$out[ $prefix . '_hash_key_is_set' ] = '' !== (string) Settings::get( $keys['hash_key'], '' );
			$out[ $prefix . '_hash_iv_is_set' ] = '' !== (string) Settings::get( $keys['hash_iv'], '' );
		}

		foreach ( Settings::METHOD_KEYS as $alias => $setting_key ) {
			$out[ $alias . '_enabled' ] = '1' === (string) Settings::get( $setting_key, '0' );
		}

		foreach ( Settings::SENDER_KEYS as $alias => $setting_key ) {
			$out[ 'sender_' . $alias ] = (string) Settings::get( $setting_key, '' );
		}

		return $out;
	}

	private static function normalize_tab( string $tab ): string {
		return array_key_exists( $tab, self::TABS ) ? $tab : self::DEFAULT_TAB;
	}
}

