<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Admin;

defined( 'ABSPATH' ) || exit;

use YangSheep\Ecommerce\Admin\YSAdminApp;
use YangSheep\YSCartEcpay\Plugin;
use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShippingCatalog;
use YangSheep\YSCartEcpay\Support\Settings;

final class EcpaySettings {
	private const NONCE_ACTION = 'ys_cart_ecpay_save_settings';
	private const DEFAULT_TAB = 'api';
	private const TABS = [
		'api'         => 'API 設定',
		'payment'     => '金流方式',
		'shipping'    => '物流方式',
		'diagnostics' => '串接資訊',
	];
	private const PAYMENT_GATEWAY_IDS = [
		'credit'  => 'ys_ec_ecpay_credit',
		'atm'     => 'ys_ec_ecpay_atm',
		'cvs'     => 'ys_ec_ecpay_cvs',
		'barcode' => 'ys_ec_ecpay_barcode',
	];
	/**
	 * alias → method_id。由型錄導出，不維護第二份清單。
	 *
	 * @return array<string,string>
	 */
	private static function shipping_method_ids(): array {
		return EcpayShippingCatalog::alias_to_id();
	}

	public static function register(): void {
		add_action( 'admin_post_ys_cart_ecpay_save_settings', [ __CLASS__, 'handle_save' ] );
	}

	public static function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '權限不足。', 'ys-cart-ecpay' ), 403 );
		}

		check_admin_referer( self::NONCE_ACTION );

		$tab = self::normalize_tab( sanitize_key( wp_unslash( (string) ( $_POST['ys_ec_ecpay_tab'] ?? self::DEFAULT_TAB ) ) ) );

		$provider_enabled = isset( $_POST['ys_ec_ecpay_enabled'] );
		Settings::update( Settings::ENABLED, $provider_enabled ? '1' : '0' );
		self::sync_provider_lifecycle( $provider_enabled );

		if ( 'api' === $tab ) {
			self::save_credentials_group( 'payment', Settings::PAYMENT_KEYS );
			self::save_credentials_group( 'logistics', Settings::LOGISTICS_KEYS );
		}

		if ( 'payment' === $tab ) {
			$aliases      = [ 'credit', 'atm', 'cvs', 'barcode' ];
			$selected_ids = self::selected_ids_from_post( $aliases, self::PAYMENT_GATEWAY_IDS );
			self::save_method_switches( $aliases );
			self::sync_gateway_enabled_list( $selected_ids );
			self::sync_lifecycle_methods( 'payment', self::PAYMENT_GATEWAY_IDS, $selected_ids );
		}

		if ( 'shipping' === $tab ) {
			$ids     = self::shipping_method_ids();
			$aliases = array_keys( $ids );

			// 🔴 先存每個方式的專屬設定（C2C 退貨門市、郵局包裹重量），再決定
			// 誰可以被啟用——因為「能不能啟用」取決於這些值填了沒有。
			self::save_shipping_method_options();

			$selected_aliases = self::selectable_aliases( $aliases );
			$selected_ids     = [];
			foreach ( $selected_aliases as $alias ) {
				$selected_ids[] = $ids[ $alias ];
			}

			self::save_method_switches( $aliases, $selected_aliases );
			self::sync_shipping_enabled_list( $selected_ids );
			self::sync_lifecycle_methods( 'shipping', $ids, $selected_ids );
			self::save_sender_fields();
		}

		wp_safe_redirect(
			add_query_arg(
				[
					'page'    => 'ys-provider-ecpay',
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
			sanitize_text_field( wp_unslash( (string) ( $_POST[ 'ys_ec_ecpay_' . $prefix . '_merchant_id'] ?? '' ) ) )
		);

		foreach ( [ 'hash_key', 'hash_iv' ] as $secret_key ) {
			$raw = trim( (string) wp_unslash( $_POST[ 'ys_ec_ecpay_' . $prefix . '_' . $secret_key ] ?? '' ) );
			if ( '' !== $raw ) {
				Settings::update( $keys[ $secret_key ], Settings::encrypt_secret( $raw ) );
			}
		}
	}

	/**
	 * @param array<int,string>      $aliases
	 * @param array<int,string>|null $allowed 實際獲准啟用的 alias；null 表示照勾選存。
	 */
	private static function save_method_switches( array $aliases, ?array $allowed = null ): void {
		foreach ( $aliases as $alias ) {
			$setting_key = Settings::method_key( $alias );
			if ( '' === $setting_key ) {
				continue;
			}

			$checked = isset( $_POST[ 'ys_ec_ecpay_' . $alias . '_enabled' ] );
			$enabled = null === $allowed ? $checked : in_array( $alias, $allowed, true );

			Settings::update( $setting_key, $enabled ? '1' : '0' );
		}
	}

	/**
	 * 存每個物流方式的專屬設定。
	 *
	 * 🔴 C2C 的退貨門市**每個方式一把 key**。舊版全部共用一個隱藏設定，而且後台
	 * 根本沒有輸入欄位——業主無從填起，送單必然失敗；就算手動塞進資料庫，全家的
	 * 退貨門市也會被 7-ELEVEN 拿去用。
	 */
	private static function save_shipping_method_options(): void {
		foreach ( EcpayShippingCatalog::all() as $method_id => $descriptor ) {
			$alias = (string) $descriptor['alias'];

			if ( true === $descriptor['supports_return_store'] ) {
				$option = (string) $descriptor['return_store_option'];
				if ( '' !== $option ) {
					Settings::update(
						$option,
						sanitize_text_field( wp_unslash( (string) ( $_POST[ 'ys_ec_ecpay_' . $alias . '_return_store_id' ] ?? '' ) ) )
					);
				}
			}

			if ( true === $descriptor['requires_goods_weight'] ) {
				$raw    = (string) wp_unslash( $_POST[ 'ys_ec_ecpay_' . $alias . '_goods_weight' ] ?? '' );
				$weight = (float) $raw;

				// 🔴 超過上限**不 clamp**。悄悄把 25 公斤存成 20 公斤，業主看到的是
				// 一個他沒填的數字，而送出去的是一張運費算錯、到門市才被退的單。
				// 超出範圍就當沒填（該方式因此無法啟用，錯誤是看得見的）。
				$valid = $weight > 0.0 && $weight <= 20.0;

				Settings::update(
					'shipping_' . $method_id . '_goods_weight',
					$valid ? number_format( $weight, 3, '.', '' ) : ''
				);
			}
		}
	}

	/**
	 * 勾選了、而且**設定完整**因此真的可以啟用的 alias。
	 *
	 * 沒填重量的郵局擋下來：讓它「開著但送不出」是最糟的狀態——後台看起來是好的，
	 * 顧客選得到，錯誤要到出貨那天才出現。
	 *
	 * 🔴 退貨門市**不在這裡**。官方規格是選填（未設定時退回原寄件門市），把它當
	 * 必填會讓一個完全合法的設定被判定成「未設定完成」而無法啟用。
	 *
	 * @param array<int,string> $aliases
	 * @return array<int,string>
	 */
	private static function selectable_aliases( array $aliases ): array {
		$out = [];
		foreach ( $aliases as $alias ) {
			if ( ! isset( $_POST[ 'ys_ec_ecpay_' . $alias . '_enabled' ] ) ) {
				continue;
			}

			$descriptor = EcpayShippingCatalog::get_by_alias( $alias );
			if ( null === $descriptor ) {
				continue;
			}

			if ( true === $descriptor['requires_goods_weight']
				&& (float) Settings::get( 'shipping_' . (string) $descriptor['method_id'] . '_goods_weight', '0' ) <= 0.0 ) {
				continue;
			}

			$out[] = $alias;
		}

		return $out;
	}

	private static function save_sender_fields(): void {
		foreach ( Settings::SENDER_KEYS as $alias => $setting_key ) {
			$value = sanitize_text_field( wp_unslash( (string) ( $_POST[ 'ys_ec_ecpay_sender_' . $alias ] ?? '' ) ) );
			Settings::update( $setting_key, $value );
		}
	}

	/**
	 * @param array<int,string>    $aliases
	 * @param array<string,string> $ids
	 * @return array<int,string>
	 */
	private static function selected_ids_from_post( array $aliases, array $ids ): array {
		$selected = [];
		foreach ( $aliases as $alias ) {
			$id = $ids[ $alias ] ?? '';
			if ( '' !== $id && isset( $_POST[ 'ys_ec_ecpay_' . $alias . '_enabled' ] ) ) {
				$selected[] = $id;
			}
		}

		return $selected;
	}

	/**
	 * Keep YS CART's legacy gateway visibility list in sync when it exists.
	 *
	 * @param array<int,string> $selected_ids
	 */
	private static function sync_gateway_enabled_list( array $selected_ids ): void {
		self::sync_enabled_list( 'gateway_enabled_list', array_values( self::PAYMENT_GATEWAY_IDS ), $selected_ids );
	}

	/**
	 * Keep YS CART's legacy shipping visibility list in sync when it exists.
	 *
	 * @param array<int,string> $selected_ids
	 */
	private static function sync_shipping_enabled_list( array $selected_ids ): void {
		self::sync_enabled_list( 'ys_ec_shipping_enabled_list', array_values( self::shipping_method_ids() ), $selected_ids );
	}

	/**
	 * @param array<int,string> $owned_ids
	 * @param array<int,string> $selected_ids
	 */
	private static function sync_enabled_list( string $setting_key, array $owned_ids, array $selected_ids ): void {
		$raw = (string) Settings::get( $setting_key, '' );
		if ( '' === $raw ) {
			return;
		}

		$current = json_decode( $raw, true );
		if ( ! is_array( $current ) ) {
			return;
		}

		$owned_ids    = array_values( array_unique( array_map( 'sanitize_key', $owned_ids ) ) );
		$selected_ids = array_values( array_unique( array_map( 'sanitize_key', $selected_ids ) ) );
		$next         = [];

		foreach ( $current as $id ) {
			$id = sanitize_key( (string) $id );
			if ( '' !== $id && ! in_array( $id, $owned_ids, true ) ) {
				$next[] = $id;
			}
		}

		foreach ( $selected_ids as $id ) {
			if ( '' !== $id && ! in_array( $id, $next, true ) ) {
				$next[] = $id;
			}
		}

		Settings::update( $setting_key, wp_json_encode( $next ) );
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '權限不足。', 'ys-cart-ecpay' ), 403 );
		}

		$settings     = self::settings_for_render();
		$nonce_action = self::NONCE_ACTION;

		if ( class_exists( YSAdminApp::class ) ) {
			YSAdminApp::open( '綠界 ECPay 設定', '金物流 / 綠界' );
		}

		$template = YS_CART_ECPAY_DIR . 'templates/admin/ecpay-settings.php';
		if ( is_readable( $template ) ) {
			include $template;
		} else {
			echo '<div class="notice notice-error"><p>' . esc_html__( '找不到綠界設定樣板。', 'ys-cart-ecpay' ) . '</p></div>';
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
			'enabled'               => self::is_provider_enabled(),
			'tab'                   => $tab,
			'tabs'                  => self::TABS,
			'page_url'              => admin_url( 'admin.php?page=ys-provider-ecpay' ),
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
				'credit'  => '信用卡',
				'atm'     => 'ATM 虛擬帳號',
				'cvs'     => '超商代碼',
				'barcode' => '超商條碼',
			],
			// 物流方式清單、通路、溫層、是否需要退貨門市——全部由型錄導出。
			'shipping_methods'      => EcpayShippingCatalog::admin_rows(),
		];

		// 每個方式的專屬設定目前值（後台必須讀得回來，否則存了等於沒存）。
		foreach ( EcpayShippingCatalog::all() as $method_id => $descriptor ) {
			$alias = (string) $descriptor['alias'];

			if ( true === $descriptor['supports_return_store'] ) {
				$out['shipping_methods'][ $alias ]['return_store_id'] =
					(string) Settings::get( (string) $descriptor['return_store_option'], '' );
			}

			if ( true === $descriptor['requires_goods_weight'] ) {
				$out['shipping_methods'][ $alias ]['goods_weight'] =
					(string) Settings::get( 'shipping_' . $method_id . '_goods_weight', '' );
			}
		}

		foreach ( [ 'payment' => Settings::PAYMENT_KEYS, 'logistics' => Settings::LOGISTICS_KEYS ] as $prefix => $keys ) {
			$out[ $prefix . '_test_mode' ]       = '1' === (string) Settings::get( $keys['test_mode'], '1' );
			$out[ $prefix . '_merchant_id' ]     = (string) Settings::get( $keys['merchant_id'], '' );
			$out[ $prefix . '_hash_key_is_set' ] = '' !== (string) Settings::get( $keys['hash_key'], '' );
			$out[ $prefix . '_hash_iv_is_set' ]  = '' !== (string) Settings::get( $keys['hash_iv'], '' );
		}

		$gateway_enabled_list  = self::read_enabled_list( 'gateway_enabled_list' );
		$shipping_enabled_list = self::read_enabled_list( 'ys_ec_shipping_enabled_list' );
		$shipping_ids          = self::shipping_method_ids();
		foreach ( Settings::method_keys() as $alias => $setting_key ) {
			$enabled = '1' === (string) Settings::get( $setting_key, '0' );
			if ( isset( self::PAYMENT_GATEWAY_IDS[ $alias ] ) && null !== $gateway_enabled_list ) {
				$enabled = $enabled && in_array( self::PAYMENT_GATEWAY_IDS[ $alias ], $gateway_enabled_list, true );
			}
			if ( isset( $shipping_ids[ $alias ] ) && null !== $shipping_enabled_list ) {
				$enabled = $enabled && in_array( $shipping_ids[ $alias ], $shipping_enabled_list, true );
			}
			$out[ $alias . '_enabled' ] = $enabled;
		}

		foreach ( Settings::SENDER_KEYS as $alias => $setting_key ) {
			$out[ 'sender_' . $alias ] = (string) Settings::get( $setting_key, '' );
		}

		return $out;
	}

	private static function normalize_tab( string $tab ): string {
		return array_key_exists( $tab, self::TABS ) ? $tab : self::DEFAULT_TAB;
	}

	private static function is_provider_enabled(): bool {
		if ( class_exists( '\YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleState' ) ) {
			return \YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleState::is_provider_enabled( 'ys_ecpay', Plugin::manifest() );
		}

		return '1' === (string) Settings::get( Settings::ENABLED, '0' );
	}

	private static function sync_provider_lifecycle( bool $enabled ): void {
		if ( ! class_exists( '\YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleState' ) ) {
			return;
		}

		\YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleState::set_provider_enabled( 'ys_ecpay', $enabled, Plugin::manifest() );
	}

	/**
	 * @param array<string,string> $owned_ids_by_alias
	 * @param array<int,string>   $selected_ids
	 */
	private static function sync_lifecycle_methods( string $domain, array $owned_ids_by_alias, array $selected_ids ): void {
		if ( ! class_exists( '\YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleState' ) ) {
			return;
		}

		$state        = \YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleState::get_methods_state( $domain );
		$owned_ids    = array_values( array_unique( array_map( 'sanitize_key', array_values( $owned_ids_by_alias ) ) ) );
		$selected_ids = array_values( array_unique( array_map( 'sanitize_key', $selected_ids ) ) );
		$order        = 0;

		foreach ( $state as $row ) {
			if ( is_array( $row ) && isset( $row['order'] ) ) {
				$order = max( $order, (int) $row['order'] + 1 );
			}
		}

		foreach ( $owned_ids as $method_id ) {
			if ( '' === $method_id ) {
				continue;
			}

			if ( ! isset( $state[ $method_id ] ) || ! is_array( $state[ $method_id ] ) ) {
				$state[ $method_id ] = [ 'order' => $order++ ];
			}

			$state[ $method_id ]['enabled']     = in_array( $method_id, $selected_ids, true );
			$state[ $method_id ]['provider_id'] = 'ys_ecpay';
		}

		\YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleState::update_methods_state( $domain, $state );
	}

	/**
	 * @return array<int,string>|null
	 */
	private static function read_enabled_list( string $setting_key ): ?array {
		$raw = (string) Settings::get( $setting_key, '' );
		if ( '' === $raw ) {
			return null;
		}

		$list = json_decode( $raw, true );
		if ( ! is_array( $list ) ) {
			return null;
		}

		$normalized = array_values( array_unique( array_filter( array_map( static fn( $id ): string => sanitize_key( (string) $id ), $list ) ) ) );
		return [] === $normalized ? null : $normalized;
	}
}
