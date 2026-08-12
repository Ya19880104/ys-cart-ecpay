<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay;

defined( 'ABSPATH' ) || exit;

use YangSheep\Ecommerce\Api\Storefront\YSRequestParser;
use YangSheep\Ecommerce\Api\Storefront\YSRestAuth;
use YangSheep\Ecommerce\Api\Storefront\YSRestResponder;
use YangSheep\Ecommerce\Gateways\YSGatewayRegistry;
use YangSheep\Ecommerce\Security\YSInboundPermission;
use YangSheep\Ecommerce\Shipping\YSShippingRegistry;
use YangSheep\YSCartEcpay\Admin\EcpaySettings;
use YangSheep\YSCartEcpay\Api\EcpayLogisticsController;
use YangSheep\YSCartEcpay\Api\EcpayPaymentController;
use YangSheep\YSCartEcpay\Api\EcpayPrintController;
use YangSheep\YSCartEcpay\Payment\EcpayAtmGateway;
use YangSheep\YSCartEcpay\Payment\EcpayBarcodeGateway;
use YangSheep\YSCartEcpay\Payment\EcpayCreditGateway;
use YangSheep\YSCartEcpay\Payment\EcpayCvsGateway;
use YangSheep\YSCartEcpay\Payment\EcpayPaymentReconciler;
use YangSheep\YSCartEcpay\Services\Shipping\Adapters\EcpayShippingAdapter;
use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShipping;
use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShippingCatalog;
use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShippingRequester;
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

	/**
	 * 本外掛註冊的物流方式 ID——由型錄導出，不維護第二份清單。
	 *
	 * @return array<int,string>
	 */
	private static function registered_shipping_ids(): array {
		return EcpayShippingCatalog::ids();
	}

	/**
	 * 核心是否具備本外掛需要的版本與能力
	 *
	 * 三件事都要成立才放行：
	 *   1. 核心版本 >= YS_CART_ECPAY_REQUIRES_CORE
	 *   2. 物流落盤契約與建單授權的類別存在（能力）
	 *   3. 物流 schema 真的就位（欄位、索引、建單嘗試表的唯一鍵）
	 *
	 * 🔴 第 3 點是實查資料庫，成本不低，因此以核心版本為 key 做快取——核心一升版
	 * key 就換，不會拿著舊答案放行。
	 *
	 * @return array{met:bool,reason:string,message:string}
	 */
	public static function core_requirements(): array {
		if ( ! defined( 'YS_ECOMMERCE_VERSION' ) ) {
			return [
				'met'     => false,
				'reason'  => 'core_missing',
				'message' => '找不到 YS CART 核心，綠界的金流與物流功能未載入。',
			];
		}

		$required = defined( 'YS_CART_ECPAY_REQUIRES_CORE' ) ? YS_CART_ECPAY_REQUIRES_CORE : '2.56.5';
		if ( version_compare( (string) YS_ECOMMERCE_VERSION, $required, '<' ) ) {
			return [
				'met'     => false,
				'reason'  => 'core_too_old',
				'message' => sprintf(
					'需要 YS CART %s 以上（目前 %s）。請先更新核心；在那之前綠界的物流方式不會註冊，'
					. '以免建立無法落盤的物流單。',
					$required,
					(string) YS_ECOMMERCE_VERSION
				),
			];
		}

		if ( ! class_exists( '\YangSheep\Ecommerce\Services\Shipping\YSShippingDispatchAuthority' )
			|| ! class_exists( '\YangSheep\Ecommerce\Database\YSMigration' )
			|| ! method_exists( '\YangSheep\Ecommerce\Database\YSMigration', 'shipping_label_dispatch_schema_ready' )
			|| ! method_exists( '\YangSheep\Ecommerce\Shipping\YSShippingRegistry', 'is_method_allowed_for_cart' ) ) {
			return [
				'met'     => false,
				'reason'  => 'core_capability_missing',
				'message' => '核心缺少物流建單授權或商品物流守門的 API，綠界物流方式未註冊。',
			];
		}

		$cache_key = 'ys_ec_ecpay_core_gate_' . md5( (string) YS_ECOMMERCE_VERSION );
		$cached    = function_exists( 'get_transient' ) ? get_transient( $cache_key ) : false;
		if ( 'ok' === $cached ) {
			return [ 'met' => true, 'reason' => 'ok', 'message' => '' ];
		}

		if ( ! \YangSheep\Ecommerce\Database\YSMigration::shipping_label_dispatch_schema_ready() ) {
			return [
				'met'     => false,
				'reason'  => 'core_schema_not_ready',
				'message' => 'YS CART 的物流資料表尚未完成 v2.56.5 升級（欄位或索引缺失），'
					. '綠界物流方式未註冊。請重新啟用 YS CART 以完成升級。',
			];
		}

		if ( function_exists( 'set_transient' ) ) {
			set_transient( $cache_key, 'ok', HOUR_IN_SECONDS );
		}

		return [ 'met' => true, 'reason' => 'ok', 'message' => '' ];
	}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function init(): void {
		EcpaySettings::register();
		add_action( 'init', [ $this, 'sync_print_route' ], 20 );

		add_filter( 'ys_ec_provider_manifests', [ $this, 'register_manifest' ], 10, 1 );
		add_action( 'ys_ec_register_gateways', [ $this, 'register_gateways' ] );
		add_action( 'ys_ec_register_shipping_methods', [ $this, 'register_shipping_methods' ] );
		add_action( 'ys_ec_register_admin_rest_routes', [ $this, 'register_admin_routes' ] );
		add_action( 'ys_ec_register_storefront_routes', [ $this, 'register_storefront_routes' ] );
		add_action( 'ys_ec_register_payment_reconcilers', [ $this, 'register_payment_reconcilers' ] );
		add_action( 'rest_api_init', [ $this, 'register_public_routes' ] );
		add_filter( 'ys_ec_shipping_requester', [ $this, 'register_shipping_requester' ], 10, 2 );
		add_filter( 'ys_ec_shipping_carrier_adapter', [ $this, 'register_carrier_adapter' ], 10, 2 );
		add_filter( 'ys_ec_shipping_provider_labels', [ $this, 'register_shipping_provider_label' ] );
		add_filter( 'ys_ec_validate_store_selection', [ $this, 'validate_store_selection' ], 10, 4 );
	}

	/**
	 * 結帳送出時驗證門市選擇（伺服器端）
	 *
	 * 🔴 只驗**我們自己的**物流方式。其他供應商的方式一個字都不碰。
	 *
	 * @param array<int,string>   $errors
	 * @param array<string,mixed> $data
	 * @return array<int,string>
	 */
	public function validate_store_selection( $errors, $data, string $shipping_method, string $payment_method ) {
		if ( ! is_array( $errors ) ) {
			$errors = [];
		}

		$descriptor = EcpayShippingCatalog::get( $shipping_method );
		if ( null === $descriptor || 'CVS' !== $descriptor['logistics_type'] ) {
			return $errors;
		}

		$rejection = EcpayStoreSelector::consume_selection(
			is_array( $data ) ? $data : [],
			$shipping_method,
			$payment_method
		);

		if ( null !== $rejection ) {
			$errors[] = $rejection;
		}

		return $errors;
	}

	public function sync_print_route(): void {
		if ( $this->has_enabled_shipping_methods() ) {
			EcpayPrintController::register();
			return;
		}

		EcpayPrintController::unregister();
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

		// 逐一由型錄註冊。加一個方式＝在型錄加一列，這裡不需要動——
		// 「型錄加了、註冊忘了」在語法上不可能發生。
		foreach ( EcpayShippingCatalog::all() as $method_id => $descriptor ) {
			if ( ! $this->is_method_enabled( 'shipping', $method_id ) ) {
				continue;
			}

			$class = (string) $descriptor['class'];
			if ( ! class_exists( $class ) ) {
				continue;
			}

			$method = new $class();
			if ( $method instanceof EcpayShipping ) {
				YSShippingRegistry::register( $method );
			}
		}
	}

	public function register_admin_routes( $registrar = null ): void {
		unset( $registrar );
	}

	public function register_storefront_routes( string $namespace ): void {
		if ( ! $this->has_enabled_shipping_methods() ) {
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
		if ( $this->has_enabled_payment_methods() ) {
			EcpayPaymentController::register_routes();
		}

		if ( ! $this->has_enabled_shipping_methods() ) {
			return;
		}

		EcpayLogisticsController::register_routes();

		register_rest_route(
			'ys-ecommerce/v1',
			'/ecpay/store-callback',
			[
				'methods'             => 'POST',
				'callback'            => [ EcpayStoreSelector::class, 'handle_store_callback' ],
				'permission_callback' => [ self::class, 'store_callback_permission' ],
			]
		);
	}

	public static function store_callback_permission( \WP_REST_Request $request ) {
		if ( ! class_exists( YSInboundPermission::class ) ) {
			return true;
		}

		$callback = YSInboundPermission::build( 'ecpay_store_callback', [
			'body_max_bytes' => 65536,
			'rate_limit'     => [ 300, 60 ],
			'allowed_types'  => [ 'application/x-www-form-urlencoded' ],
			'verify_ip'      => false,
		] );
		return $callback( $request );
	}

	public function ecpay_map_url( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->is_shipping_enabled() ) {
			return YSRestResponder::error( 'provider_disabled', '綠界物流尚未啟用。' );
		}

		$params      = YSRequestParser::params( $request );
		$shipping_id = sanitize_text_field( $params['shipping_id'] ?? '' );
		$context     = sanitize_key( $params['context'] ?? 'checkout' );
		$order_id    = absint( $params['order_id'] ?? 0 );
		$cart_scope  = self::sanitize_cart_scope( (string) ( $params['cart_scope'] ?? 'default' ) );
		$return_url  = esc_url_raw( (string) ( $params['return_url'] ?? '' ) );

		if ( '' === $shipping_id ) {
			return YSRestResponder::error( 'missing_shipping_id', '缺少物流方式 ID。' );
		}

		// 🔴 付款方式決定電子地圖要用「代收」還是「不代收」去篩門市，而綠界對兩者
		// 給的門市清單不同。缺這個欄位不是「預設不代收」，是**無法證明**——
		// 猜錯的代價是顧客選得到門市、結完帳、送單當下才被綠界拒絕。
		if ( ! isset( $params['payment_method'] ) ) {
			return YSRestResponder::error(
				'missing_payment_method',
				'缺少付款方式，無法決定電子地圖的代收模式。'
			);
		}

		$payment_method = sanitize_text_field( (string) $params['payment_method'] );

		if ( ! $this->is_method_enabled( 'shipping', $shipping_id ) ) {
			return YSRestResponder::error( 'shipping_method_disabled', '綠界物流方式尚未啟用。' );
		}

		// 本端點不接受訂單 ID。以 `0 === $order_id` 分流等於任何非零值都能整段跳過
		// 下方守門，而 order_id 直接取自請求、不驗訂單存在、擁有者或品項——呼叫端
		// 可自行偽造以繞過商品物流限制。兩個既有呼叫端都不送此參數，故直接拒收。
		if ( 0 !== $order_id ) {
			return YSRestResponder::error( 'order_id_not_supported', '本端點不接受訂單 ID。' );
		}

		// 與核心結帳共用同一份守門：只驗 provider／全域啟用狀態是不夠的，未驗購物車
		// 商品的「允許的物流方式」交集時，可對商品禁用的 sub-type 簽發**已簽章**的
		// 電子地圖表單——使用者選完門市、callback 也寫進 session 與 localStorage，
		// 直到送單才被擋。fail-closed：購物車讀取失敗亦視為不允許。
		if ( ! $this->is_shipping_allowed_for_cart( $shipping_id, $cart_scope ) ) {
			return YSRestResponder::error( 'shipping_method_not_allowed', '購物車內商品不支援此物流方式。' );
		}

		$result = EcpayStoreSelector::build_map_form_data( $shipping_id, $context, $order_id, $cart_scope, $return_url, $payment_method );
		if ( $result ) {
			return YSRestResponder::success( 'map_url_ready', '', $result );
		}

		return YSRestResponder::error( 'map_url_failed', '綠界物流設定尚未完成或不支援此物流方式。' );
	}

	private static function sanitize_cart_scope( string $scope ): string {
		$scope = sanitize_key( $scope );
		if ( '' === $scope || ! preg_match( '/^[a-z0-9_]{1,32}$/', $scope ) ) {
			return 'default';
		}

		return $scope;
	}

	/**
	 * 購物車商品是否允許此物流方式
	 *
	 * 與核心結帳共用 `YSShippingRegistry::is_method_allowed_for_cart()` 這一份守門，
	 * 避免 provider 端自建平行邏輯而與核心漂移。
	 *
	 * 🔴 核心述詞不存在時一律拒絕。回 true 以「相容舊核心」等於整道守門在舊核心上
	 * 不存在——而發版順序（先發核心再發本外掛）是流程約定，不能取代 runtime gate：
	 * 任何降版、部分部署或安裝順序錯誤都會讓守門靜默消失。
	 *
	 * @param string $shipping_id 物流方式 ID
	 * @param string $cart_scope  已消毒的購物車 scope
	 */
	private function is_shipping_allowed_for_cart( string $shipping_id, string $cart_scope ): bool {
		if ( ! class_exists( YSShippingRegistry::class )
			|| ! method_exists( YSShippingRegistry::class, 'is_method_allowed_for_cart' ) ) {
			return false;
		}

		$items = self::read_cart_items( $cart_scope );
		if ( null === $items ) {
			// 讀不到購物車 ≠ 空購物車。核心把空車視為「不限物流」，若把讀取失敗
			// 一併轉成空陣列，失敗反而會簽發地圖表單。此處分流並 fail-closed。
			return false;
		}

		return YSShippingRegistry::is_method_allowed_for_cart( $shipping_id, $items );
	}

	/**
	 * 純讀取指定 scope 的購物車品項
	 *
	 * 以 `ys_ec_cart_key_scope` filter 綁定 scope，取核心的 error-aware
	 * `try_get_items_raw()`（單一 SELECT，不計算總額、不觸發 cart 事件、不寫入）。
	 * 訪客若尚無 session cookie 代表購物車必為空，直接短路以避免
	 * `get_or_create_session()` 的 `setcookie()` 副作用。
	 *
	 * 回傳陣列＝讀取成功（空陣列＝確定為空購物車）；回傳 null＝讀取失敗，呼叫端
	 * 必須 fail-closed。兩者不可混為一談：核心把空購物車視為「不限物流」。
	 *
	 * @return array<int,array<string,mixed>>|null
	 */
	private static function read_cart_items( string $cart_scope ): ?array {
		$handler = '\\YangSheep\\Ecommerce\\Handlers\\YSCartHandler';

		// 必須有 error-aware 的 typed API。舊核心的 get_items_raw() 在 load_from_db()
		// 內就把「SQL 錯誤」「items 壞 JSON」「查無 row」全部抹平成 []，而空車在核心
		// 語意等於「無商品設限」——用它做守門，讀取失敗必然 fail-open。API 不存在即拒絕。
		if ( ! class_exists( $handler ) || ! method_exists( $handler, 'try_get_items_raw' ) ) {
			return null;
		}

		if ( ! is_user_logged_in() ) {
			// 只檢查**本 scope** 的 cookie。額外接受 default cookie 會讓非 default scope
			// 在該 scope 尚無購物車時仍進入讀取路徑，觸發 get_or_create_session() 產生
			// 新 session cookie（純讀請求不該有此副作用），且讀到的是另一個 scope 的車。
			$cookie = 'default' === $cart_scope ? 'ys_ec_session' : 'ys_ec_session_' . $cart_scope;
			if ( empty( $_COOKIE[ $cookie ] ) ) {
				return [];
			}
		}

		$scoper = static function ( $current ) use ( $cart_scope ) {
			return 'default' === $current ? $cart_scope : $current;
		};
		add_filter( 'ys_ec_cart_key_scope', $scoper, 1 );
		try {
			$items = $handler::get_instance()->try_get_items_raw();
			return is_array( $items ) ? $items : null;
		} catch ( \Throwable $e ) {
			return null;
		} finally {
			remove_filter( 'ys_ec_cart_key_scope', $scoper, 1 );
		}
	}

	public function register_shipping_requester( $requester, $method ) {
		if ( null !== $requester ) {
			return $requester;
		}

		if ( ! $this->has_enabled_shipping_methods() ) {
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

		if ( ! $this->has_enabled_shipping_methods() ) {
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
		if ( ! $this->has_enabled_shipping_methods() ) {
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

	public function register_payment_reconcilers( $registry ): void {
		if ( ! $this->has_enabled_payment_methods()
			|| ! Settings::has_payment_credentials()
			|| ! is_object( $registry )
			|| ! method_exists( $registry, 'register' )
			|| ! interface_exists( '\YangSheep\Ecommerce\Services\Payment\YSPaymentReconcilerInterface' ) ) {
			return;
		}

		$registry->register( new EcpayPaymentReconciler() );
	}

	private function has_enabled_payment_methods(): bool {
		if ( ! $this->is_payment_enabled() ) {
			return false;
		}

		foreach ( self::REGISTERED_GATEWAY_IDS as $method_id ) {
			if ( $this->is_method_enabled( 'payment', $method_id ) ) {
				return true;
			}
		}

		return false;
	}

	private function has_enabled_shipping_methods(): bool {
		if ( ! $this->is_shipping_enabled() ) {
			return false;
		}

		foreach ( self::registered_shipping_ids() as $method_id ) {
			if ( $this->is_method_enabled( 'shipping', $method_id ) ) {
				return true;
			}
		}

		return false;
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
			$alias = EcpayShippingCatalog::id_to_alias()[ $method_id ] ?? '';
			return '' !== $alias && Settings::shipping_enabled( $alias );
		}

		return false;
	}
}
