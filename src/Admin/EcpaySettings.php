<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Admin;

defined( 'ABSPATH' ) || exit;

use YangSheep\Ecommerce\Admin\YSAdminApp;
use YangSheep\YSCartEcpay\Support\Settings;

final class EcpaySettings {
	private const NONCE_ACTION = 'ys_cart_ecpay_save_settings';

	public static function register(): void {
		add_action( 'admin_post_ys_cart_ecpay_save_settings', [ __CLASS__, 'handle_save' ] );
	}

	public static function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ys-cart-ecpay' ), 403 );
		}

		check_admin_referer( self::NONCE_ACTION );

		Settings::update( Settings::ENABLED, isset( $_POST['ys_ec_ecpay_enabled'] ) ? '1' : '0' );

		self::save_credentials_group( 'payment', Settings::PAYMENT_KEYS );
		self::save_credentials_group( 'logistics', Settings::LOGISTICS_KEYS );

		foreach ( Settings::METHOD_KEYS as $alias => $setting_key ) {
			Settings::update( $setting_key, isset( $_POST[ 'ys_ec_ecpay_' . $alias . '_enabled' ] ) ? '1' : '0' );
		}

		foreach ( Settings::SHIPPING_COST_KEYS as $alias => $keys ) {
			$cost = max( 0, (float) wp_unslash( (string) ( $_POST[ 'ys_ec_ecpay_' . $alias . '_cost' ] ?? '0' ) ) );
			$free = max( 0, (float) wp_unslash( (string) ( $_POST[ 'ys_ec_ecpay_' . $alias . '_free_threshold' ] ?? '0' ) ) );
			Settings::update( $keys['cost'], (string) $cost );
			Settings::update( $keys['free'], (string) $free );
		}

		foreach ( Settings::SENDER_KEYS as $alias => $setting_key ) {
			$value = sanitize_text_field( wp_unslash( (string) ( $_POST[ 'ys_ec_ecpay_sender_' . $alias ] ?? '' ) ) );
			Settings::update( $setting_key, $value );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=ys-ecommerce-ecpay&updated=1' ) );
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
		$out = [
			'enabled' => '1' === (string) Settings::get( Settings::ENABLED, '0' ),
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

		foreach ( Settings::SHIPPING_COST_KEYS as $alias => $keys ) {
			$out[ $alias . '_cost' ] = (string) Settings::get( $keys['cost'], '0' );
			$out[ $alias . '_free_threshold' ] = (string) Settings::get( $keys['free'], '0' );
		}

		foreach ( Settings::SENDER_KEYS as $alias => $setting_key ) {
			$out[ 'sender_' . $alias ] = (string) Settings::get( $setting_key, '' );
		}

		return $out;
	}
}

