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
use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShippingFamily;
use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShippingFamilyC2C;
use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShippingHilifeC2C;
use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShippingHilife;
use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShippingPost;
use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShippingRequester;
use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShippingTcat;
use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShippingTcatChilled;
use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShippingTcatFrozen;
use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShippingUnimart;
use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShippingUnimartC2C;
use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShippingUnimartFreeze;
use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShippingUnimartFreezeC2C;
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
		// v0.3.0：C2C（店到店）與低溫——各自獨立啟用
		'ys_ec_ecpay_ship_family_c2c',
		'ys_ec_ecpay_ship_unimart_c2c',
		'ys_ec_ecpay_ship_hilife_c2c',
		'ys_ec_ecpay_ship_unimart_freeze',
		'ys_ec_ecpay_ship_unimart_freeze_c2c',
		'ys_ec_ecpay_ship_tcat_chilled',
		'ys_ec_ecpay_ship_tcat_frozen',
		'ys_ec_ecpay_ship_tcat',
		'ys_ec_ecpay_ship_post',
	];

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * 這個外掛要求的最低核心版本（v0.3.0，#2G）
	 *
	 * 🔴 2.57.0 不是「建議」，是**結構性依賴**：
	 *
	 *   - `YSPaymentDetailStore` 是 payment_detail 的唯一併發安全寫入器。舊核心
	 *     沒有它，`OrderPaymentDetail` 會一律回 core_unavailable，退款與建單全部
	 *     失敗——但那是在使用者已經按下付款之後才失敗。
	 *   - `YSPaymentDispatch` 的 ambient guard 讓 provider 的每一次寫入自動受
	 *     dispatch 保護。舊核心不會套用它，於是逾時被接管的舊呼叫仍能把自己的
	 *     取號資訊寫進新的一次嘗試。
	 *
	 * 版本不符時**不註冊任何 gateway 或物流方式**：一個註冊了卻無法安全落盤的
	 * provider，比一個明顯缺席的 provider 危險得多——前者會收到錢。
	 */
	public const REQUIRES_CORE = '2.57.0';

	/**
	 * 核心版本是否滿足需求。
	 *
	 * 讀不到版本常數＝核心根本沒載入，或舊到還沒有這個常數——兩者都不滿足。
	 */
	public static function core_version_ok(): bool {
		if ( ! defined( 'YS_ECOMMERCE_VERSION' ) ) {
			return false;
		}

		return version_compare( (string) constant( 'YS_ECOMMERCE_VERSION' ), self::REQUIRES_CORE, '>=' );
	}

	/** 目前偵測到的核心版本（缺席時回空字串）。 */
	public static function core_version(): string {
		return defined( 'YS_ECOMMERCE_VERSION' ) ? (string) constant( 'YS_ECOMMERCE_VERSION' ) : '';
	}

	/** 版本不符時的後台通知。 */
	public function render_core_version_notice(): void {
		$found = self::core_version();

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'YS CART - ECPay 未啟用：', 'ys-cart-ecpay' ),
			esc_html( sprintf(
				/* translators: 1: required core version, 2: detected core version */
				__( '需要 YS CART %1$s 或更新版本（目前偵測到 %2$s）。在核心升級之前，綠界金流與物流方式不會被註冊。', 'ys-cart-ecpay' ),
				self::REQUIRES_CORE,
				'' !== $found ? $found : __( '未安裝／未啟用', 'ys-cart-ecpay' )
			) )
		);
	}

	public function init(): void {
		// 🔴 版本不符 → 只掛後台通知，其餘一律不註冊（含 gateway、物流、REST 路由、
		// CLI 與核心同步 hook）。一個註冊了卻無法安全落盤的 provider 會收到錢。
		if ( ! self::core_version_ok() ) {
			add_action( 'admin_notices', [ $this, 'render_core_version_notice' ] );

			return;
		}

		EcpaySettings::register();
		\YangSheep\YSCartEcpay\Cli\EcpayRefundAttemptCommand::register();
		// R8-F3：核心 finalization 人工核定 → 同步核定本外掛退款 attempt（解除雙 ledger
		// 死結）。與 WP_CLI 無關，一律註冊（核心核定未來也可能由後台觸發）。
		\YangSheep\YSCartEcpay\Cli\EcpayRefundAttemptCommand::register_core_sync();
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

		// v0.3.0：C2C（店到店）——與 B2C 是兩份不同的綠界合約，
		// subtype 與服務金鑰都不同。照核心 PayUni provider 已驗證的
		// 雙軌模板：各自註冊成獨立的物流方式，由業主依自己的合約開。
		if ( $this->is_method_enabled( 'shipping', 'ys_ec_ecpay_ship_family_c2c' ) ) {
			YSShippingRegistry::register( new EcpayShippingFamilyC2C() );
		}

		if ( $this->is_method_enabled( 'shipping', 'ys_ec_ecpay_ship_unimart_c2c' ) ) {
			YSShippingRegistry::register( new EcpayShippingUnimartC2C() );
		}

		if ( $this->is_method_enabled( 'shipping', 'ys_ec_ecpay_ship_hilife_c2c' ) ) {
			YSShippingRegistry::register( new EcpayShippingHilifeC2C() );
		}

		// v0.3.0：低溫。超商冷凍是獨立 subtype；宅配溫層是同一個
		// subtype 搭不同的 `Temperature`。兩者都由物流方式自己回答。
		if ( $this->is_method_enabled( 'shipping', 'ys_ec_ecpay_ship_unimart_freeze' ) ) {
			YSShippingRegistry::register( new EcpayShippingUnimartFreeze() );
		}

		if ( $this->is_method_enabled( 'shipping', 'ys_ec_ecpay_ship_unimart_freeze_c2c' ) ) {
			YSShippingRegistry::register( new EcpayShippingUnimartFreezeC2C() );
		}

		if ( $this->is_method_enabled( 'shipping', 'ys_ec_ecpay_ship_tcat_chilled' ) ) {
			YSShippingRegistry::register( new EcpayShippingTcatChilled() );
		}

		if ( $this->is_method_enabled( 'shipping', 'ys_ec_ecpay_ship_tcat_frozen' ) ) {
			YSShippingRegistry::register( new EcpayShippingTcatFrozen() );
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

		if ( ! $this->is_method_enabled( 'shipping', $shipping_id ) ) {
			return YSRestResponder::error( 'shipping_method_disabled', '綠界物流方式尚未啟用。' );
		}

		// v0.2.11：本端點不接受訂單 ID。初版以 `0 === $order_id` 分流，等於任何非零
		// 值都能整段跳過下方守門，而 order_id 直接取自請求、不驗訂單存在、擁有者或
		// 品項——呼叫端可自行偽造以繞過商品物流限制。現行兩個呼叫端（核心
		// assets/js/ys-ec-store-selector.js 與 sdk/ys-cart-ecpay-headless.js）都不送
		// 此參數，故直接拒收，不另開「載入並授權訂單」的攻擊表面。
		if ( 0 !== $order_id ) {
			return YSRestResponder::error( 'order_id_not_supported', '本端點不接受訂單 ID。' );
		}

		// 補上與核心結帳同一份守門：先前只驗 provider／全域啟用狀態，未驗購物車商品
		// 的「允許的物流方式」交集，因此可對商品禁用的 sub-type 簽發已簽章的電子地圖
		// 表單（使用者選完門市後才在送單被擋，門市 session 與 localStorage 仍已產生）。
		// fail-closed：購物車讀取失敗亦視為不允許。
		if ( ! $this->is_shipping_allowed_for_cart( $shipping_id, $cart_scope ) ) {
			return YSRestResponder::error( 'shipping_method_not_allowed', '購物車內商品不支援此物流方式。' );
		}

		$result = EcpayStoreSelector::build_map_form_data( $shipping_id, $context, $order_id, $cart_scope, $return_url );
		if ( $result ) {
			return YSRestResponder::success( 'map_url_ready', '', $result );
		}

		return YSRestResponder::error( 'map_url_failed', '綠界物流設定尚未完成或不支援此物流方式。' );
	}

	/**
	 * 購物車商品是否允許此物流方式（v0.2.11）
	 *
	 * 與核心結帳共用 YSShippingRegistry::is_method_allowed_for_cart() 這一份守門，
	 * 避免 provider 端自建平行邏輯而與核心漂移。核心未提供此述詞、或購物車讀取
	 * 失敗時一律回 false（fail-closed），理由見下方實作註解。
	 *
	 * @param string $shipping_id 物流方式 ID
	 * @param string $cart_scope  已消毒的購物車 scope
	 */
	private function is_shipping_allowed_for_cart( string $shipping_id, string $cart_scope ): bool {
		// 核心述詞不存在時一律拒絕。初版回 true 以「相容舊核心」，但那等於整道守門在
		// 舊核心上不存在——而發版順序（先發核心再發本外掛）是流程約定，不能取代 runtime
		// gate：任何降版、部分部署或安裝順序錯誤都會讓守門靜默消失。
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
	 * 純讀取指定 scope 的購物車品項（v0.2.11）
	 *
	 * 沿用核心 storefront 既有做法：以 ys_ec_cart_key_scope filter 綁定 scope，
	 * 取 get_items_raw()（單一 SELECT，不計算總額、不觸發 cart 事件、不寫入）。
	 * 訪客若尚無 session cookie 代表購物車必為空，直接短路以避免 get_or_create_session()
	 * 的 setcookie() 副作用。
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
		// 語意等於「無商品設限」——用它做守門，讀取失敗必然 fail-open，且 provider
		// 在外層無論怎麼包都救不回來。API 不存在即拒絕。
		if ( ! class_exists( $handler ) || ! method_exists( $handler, 'try_get_items_raw' ) ) {
			return null;
		}

		if ( ! is_user_logged_in() ) {
			// 只檢查**本 scope** 的 cookie。先前額外接受 default cookie，於是非 default
			// scope 在該 scope 尚無購物車時仍會進入讀取路徑，觸發 cart_identity() →
			// get_or_create_session() 產生新 session cookie（純讀請求不該有此副作用），
			// 且讀到的會是另一個 scope 的車。
			$cookie = 'default' === $cart_scope ? 'ys_ec_session' : 'ys_ec_session_' . $cart_scope;
			if ( empty( $_COOKIE[ $cookie ] ) ) {
				// 該 scope 尚無 session＝購物車必然不存在，屬「確定為空」而非讀取失敗。
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

	private static function sanitize_cart_scope( string $scope ): string {
		$scope = sanitize_key( $scope );
		if ( '' === $scope || ! preg_match( '/^[a-z0-9_]{1,32}$/', $scope ) ) {
			return 'default';
		}

		return $scope;
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

		foreach ( self::REGISTERED_SHIPPING_IDS as $method_id ) {
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
