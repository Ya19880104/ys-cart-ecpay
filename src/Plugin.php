<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay;

defined( 'ABSPATH' ) || exit;

use YangSheep\Ecommerce\Api\Storefront\YSRequestParser;
use YangSheep\Ecommerce\Api\Storefront\YSRestAuth;
use YangSheep\Ecommerce\Api\Storefront\YSRestResponder;
use YangSheep\Ecommerce\Gateways\YSGatewayRegistry;
use YangSheep\Ecommerce\Models\YSSubscription;
use YangSheep\Ecommerce\Security\YSInboundPermission;
use YangSheep\Ecommerce\Security\YSRateLimiter;
use YangSheep\Ecommerce\Shipping\YSShippingRegistry;
use YangSheep\Ecommerce\Utils\YSCrypto;
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
use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpaySavedStoreReauthorizer;
use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayStoreSelector;
use YangSheep\YSCartEcpay\Support\CartScope;
use YangSheep\YSCartEcpay\Support\ProviderMaintenanceLock;
use YangSheep\YSCartEcpay\Support\Settings;
use YangSheep\YSCartEcpay\Support\ShippingMethodOperability;

final class Plugin {
	private static ?self $instance = null;

	/**
	 * 送了非 canonical `cart_scope` 時的固定訊息。
	 *
	 * 刻意在三條 public boundary 共用同一句，讓呼叫端拿到一致、可對照文件的錯誤，
	 * 同時不因「錯在哪一種形狀」而多洩漏一個判別位元。
	 */
	private const CART_SCOPE_ERROR = '購物階段（cart_scope）格式不正確；必須符合 [a-z0-9_]{1,32}，或整個省略。';

	/**
	 * 一次性提領碼的**鑄造格式**——與 `EcpayStoreSelector::generate_result_code()`
	 * 產出的形狀完全一致（32 個 `[A-Za-z0-9]`）。`D` 修飾詞讓 `$` 不吃尾端換行：
	 * 沒有它，`…AbCdEf01\n` 也會通過，而鑄造端從不產生帶換行的碼。
	 */
	private const RESULT_CODE_PATTERN = '/^[A-Za-z0-9]{32}$/D';

	private const REGISTERED_GATEWAY_IDS = [
		'ys_ec_ecpay_credit',
		'ys_ec_ecpay_atm',
		'ys_ec_ecpay_cvs',
		'ys_ec_ecpay_barcode',
	];

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

		$required = defined( 'YS_CART_ECPAY_REQUIRES_CORE' ) ? YS_CART_ECPAY_REQUIRES_CORE : '2.56.12';
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

		if ( ! class_exists( YSCrypto::class )
			|| ! method_exists( YSCrypto::class, 'encrypt_for_storage' )
			|| ! method_exists( YSCrypto::class, 'decrypt_from_storage' ) ) {
			return [
				'met'     => false,
				'reason'  => 'core_crypto_missing',
				'message' => '核心缺少安全的加密儲存能力；綠界外掛不會載入，也不會以明文保存金流或物流密鑰。',
			];
		}

		if ( ! class_exists( '\YangSheep\Ecommerce\Services\Shipping\YSShippingDispatchAuthority' )
			|| ! method_exists( '\YangSheep\Ecommerce\Services\Shipping\YSShippingDispatchAuthority', 'with_order_serialization' )
			|| ! class_exists( '\YangSheep\Ecommerce\Database\YSMigration' )
			|| ! method_exists( '\YangSheep\Ecommerce\Database\YSMigration', 'shipping_label_dispatch_schema_ready' )
			|| ! method_exists( '\YangSheep\Ecommerce\Database\YSMigration', 'address_shipping_provider_schema_ready' )
			|| ! method_exists( '\YangSheep\Ecommerce\Services\Shipping\YSShippingDispatchAuthority', 'active_attempt' )
			|| ! class_exists( '\YangSheep\Ecommerce\Handlers\YSShippingHandler' )
			|| ! method_exists( '\YangSheep\Ecommerce\Handlers\YSShippingHandler', 'query_shipping_status_for_order' )
			|| ! class_exists( '\YangSheep\Ecommerce\Services\Shipping\YSShippingPipelineService' )
			|| ! method_exists( '\YangSheep\Ecommerce\Services\Shipping\YSShippingPipelineService', 'advance_from_carrier_status' )
			|| ! method_exists( '\YangSheep\Ecommerce\Services\Shipping\YSShippingPipelineService', 'publish_advance_hook' )
			|| ( new \ReflectionMethod( '\YangSheep\Ecommerce\Services\Shipping\YSShippingPipelineService', 'advance_from_carrier_status' ) )->getNumberOfParameters() < 5
			|| ! class_exists( '\YangSheep\Ecommerce\Security\YSWebhookGuard' )
			|| ! method_exists( '\YangSheep\Ecommerce\Security\YSWebhookGuard', 'reserve' )
			|| ! method_exists( '\YangSheep\Ecommerce\Security\YSWebhookGuard', 'commit_replay' )
			|| ! method_exists( '\YangSheep\Ecommerce\Security\YSWebhookGuard', 'release_replay' )
			|| ! class_exists( '\YangSheep\Ecommerce\Security\YSReplayReservation' )
			|| ! method_exists( '\YangSheep\Ecommerce\Security\YSReplayReservation', 'get_token' )
			|| ! method_exists( '\YangSheep\Ecommerce\Security\YSReplayReservation', 'is_acquired' )
			|| ! method_exists( '\YangSheep\Ecommerce\Security\YSReplayReservation', 'can_acknowledge' )
			|| ! interface_exists( '\YangSheep\Ecommerce\Services\Payment\YSPaymentReconcilerInterface' )
			|| ! method_exists( '\YangSheep\Ecommerce\Shipping\YSShippingRegistry', 'is_method_allowed_for_cart' ) ) {
			return [
				'met'     => false,
				'reason'  => 'core_capability_missing',
				'message' => '核心缺少物流建單授權、typed replay 或 deferred hook API，綠界物流方式未註冊。',
			];
		}

		// 🔴 v0.3.0 配對能力：payment_detail 共用 CAS（YSPaymentDetailStore）與付款
		// dispatch 的穩定 operation key。缺任一＝退款憑據與建單識別無法安全落盤——
		// 「一個註冊了卻無法安全落盤的 provider，比一個明顯缺席的 provider 危險得多
		// （前者會收到錢）」，因此與物流能力同級：直接不放行。
		if ( ! class_exists( '\YangSheep\Ecommerce\Services\Payment\YSPaymentDetailStore' )
			|| ! method_exists( '\YangSheep\Ecommerce\Services\Payment\YSPaymentDetailStore', 'mutate' )
			|| ! method_exists( '\YangSheep\Ecommerce\Services\Payment\YSPaymentDetailStore', 'read' )
			|| ! class_exists( '\YangSheep\Ecommerce\Services\Payment\YSPaymentDetailResult' )
			|| ! class_exists( '\YangSheep\Ecommerce\Services\Payment\YSPaymentDispatch' )
			|| ! method_exists( '\YangSheep\Ecommerce\Services\Payment\YSPaymentDispatch', 'current_operation_key' ) ) {
			return [
				'met'     => false,
				'reason'  => 'core_capability_missing',
				'message' => '核心缺少 payment_detail 共用 CAS（YSPaymentDetailStore）或付款 operation key API（v0.3.0 配對），綠界金流未註冊。',
			];
		}

		$cache_key = 'ys_ec_ecpay_core_gate_' . md5(
			(string) YS_ECOMMERCE_VERSION . '|' . ( defined( 'YS_CART_ECPAY_VERSION' ) ? (string) YS_CART_ECPAY_VERSION : 'dev' ) . '|v3'
		);
		$cached    = function_exists( 'get_transient' ) ? get_transient( $cache_key ) : false;
		if ( 'ok' === $cached ) {
			return [ 'met' => true, 'reason' => 'ok', 'message' => '' ];
		}

		if ( ! \YangSheep\Ecommerce\Database\YSMigration::shipping_label_dispatch_schema_ready()
			|| ! \YangSheep\Ecommerce\Database\YSMigration::address_shipping_provider_schema_ready() ) {
			return [
				'met'     => false,
				'reason'  => 'core_schema_not_ready',
				'message' => 'YS CART 尚未完成 v2.56.12 配對升級（物流 authority、地址 provider identity 或 storage readiness 缺失），'
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

		// v0.3.0：退款 attempt CLI 與核心核定同步。bootstrap 已以 core_requirements()
		// 守門——版本／能力不符時整個 init() 不會被呼叫，CLI 也就不會註冊。
		\YangSheep\YSCartEcpay\Cli\EcpayRefundAttemptCommand::register();
		// R8-F3：核心 finalization 人工核定 → 同步核定本外掛退款 attempt（解除雙
		// ledger 死結）。與 WP_CLI 無關，一律註冊（核心核定未來也可能由後台觸發）。
		\YangSheep\YSCartEcpay\Cli\EcpayRefundAttemptCommand::register_core_sync();

		add_action( 'init', [ $this, 'sync_print_route' ], 20 );

		add_filter( 'ys_ec_provider_manifests', [ $this, 'register_manifest' ], 10, 1 );
		add_action( 'ys_ec_register_gateways', [ $this, 'register_gateways' ] );
		add_action( 'ys_ec_register_shipping_methods', [ $this, 'register_shipping_methods' ] );
		// 🔴 門市目錄的 production caller（v0.2.13）：沒有這一行，refresh() 只有
		// 測試會呼叫，安裝後快取永遠不會建立、store_verified 恆為 0。
		Shipping\Ecpay\EcpayStoreDirectory::register_cron();
		add_action( 'ys_ec_register_admin_rest_routes', [ $this, 'register_admin_routes' ] );
		add_action( 'ys_ec_register_storefront_routes', [ $this, 'register_storefront_routes' ] );
		add_action( 'ys_ec_register_payment_reconcilers', [ $this, 'register_payment_reconcilers' ] );
		add_action( 'rest_api_init', [ $this, 'register_public_routes' ] );
		add_filter( 'ys_ec_shipping_requester', [ $this, 'register_shipping_requester' ], 10, 2 );
		add_filter( 'ys_ec_shipping_carrier_adapter', [ $this, 'register_carrier_adapter' ], 10, 2 );
		add_filter( 'ys_ec_shipping_provider_labels', [ $this, 'register_shipping_provider_label' ] );
		add_filter( 'ys_ec_validate_store_selection', [ $this, 'validate_store_selection' ], 10, 4 );
		add_filter( 'ys_ec_claim_store_selection', [ $this, 'claim_store_selection' ], 10, 5 );
		add_filter( 'ys_ec_resolve_fulfillment_selection_v1', [ $this, 'resolve_fulfillment_selection' ], 10, 3 );
		add_filter( 'ys_ec_claim_fulfillment_selection_v1', [ $this, 'claim_fulfillment_selection' ], 10, 4 );
	}

	/**
	 * Resolve the ECPay destination before Core writes the order row.
	 *
	 * @param array<string,mixed> $result
	 * @param array<string,mixed> $data
	 * @param array<string,mixed> $context
	 * @return array<string,mixed>
	 */
	public function resolve_fulfillment_selection( $result, $data, $context = [] ): array {
		if ( is_array( $result ) && true === ( $result['handled'] ?? false ) ) {
			return $result;
		}

		$data       = is_array( $data ) ? $data : [];
		$context    = is_array( $context ) ? $context : [];
		$request    = $this->canonical_fulfillment_request( $data, $context );
		if ( null === $request ) {
			return $this->fulfillment_rejection( 'invalid_fulfillment_context', '物流結帳條件無法驗證，請重新整理後再試。' );
		}
		$data       = $request['data'];
		$method_id  = $request['method_id'];
		$descriptor = EcpayShippingCatalog::get( $method_id );
		if ( null === $descriptor ) {
			return is_array( $result ) ? $result : [ 'handled' => false ];
		}

		if ( ! ShippingMethodOperability::is_operable( $method_id ) ) {
			return $this->fulfillment_rejection( 'method_unavailable', '所選的綠界物流方式目前未啟用。' );
		}

		$payment_method = $request['payment_method'];
		$is_collection = 'ys_ec_cod' === $payment_method;
		if ( $is_collection && true !== ( $descriptor['cod_capable'] ?? false ) ) {
			return $this->fulfillment_rejection( 'collection_not_supported', 'The selected shipping method does not support collection.' );
		}
		if ( $request['zero_payment_order']
			&& $is_collection ) {
			return $this->fulfillment_rejection(
				'zero_total_collection_mismatch',
				'零元訂單不得沿用貨到付款的物流選擇，請改用非代收付款條件後重新選擇。'
			);
		}
		$recipient_name = trim( sanitize_text_field( (string) ( $data['billing_name'] ?? '' ) ) );
		$recipient_phone = trim( sanitize_text_field( (string) ( $data['billing_phone'] ?? '' ) ) );
		$country = strtoupper( trim( sanitize_text_field( (string) ( $data['billing_country'] ?? 'TW' ) ) ) );

		if ( 'CVS' === (string) $descriptor['logistics_type'] ) {
			$inspection = EcpayStoreSelector::inspect_selection_authoritative( $data, $method_id, $payment_method );
			if ( null !== $inspection['error'] ) {
				return $this->fulfillment_rejection( 'store_selection_invalid', (string) $inspection['error'] );
			}
			$store = $inspection['store'];
			$destination = [
				'type'            => 'cvs',
				'recipient_name'  => $recipient_name,
				'recipient_phone' => $recipient_phone,
				'country'         => $country,
				'store_id'        => $store['store_id'],
				'store_name'      => $store['store_name'],
				'store_address'   => $store['store_address'],
			];
			$claim_type = 'store_selection';
			$token      = trim( (string) ( $data['ecpay_store_token'] ?? '' ) );
		} else {
			$destination = [
				'type'            => 'home',
				'recipient_name'  => $recipient_name,
				'recipient_phone' => $recipient_phone,
				'country'         => $country,
				'postcode'        => trim( sanitize_text_field( (string) ( $data['billing_postcode'] ?? '' ) ) ),
				'state'           => trim( sanitize_text_field( (string) ( $data['billing_state'] ?? '' ) ) ),
				'city'            => trim( sanitize_text_field( (string) ( $data['billing_city'] ?? '' ) ) ),
				'district'        => trim( sanitize_text_field( (string) ( $data['billing_district'] ?? '' ) ) ),
				'address'         => trim( sanitize_text_field( (string) ( $data['billing_address'] ?? '' ) ) ),
				'address2'        => trim( sanitize_text_field( (string) ( $data['billing_address2'] ?? '' ) ) ),
			];
			$claim_type = 'home_selection';
			$token      = '';
		}

		$temperature = match ( (string) ( $descriptor['temperature'] ?? '' ) ) {
			EcpayShippingCatalog::TEMP_ROOM    => 'room',
			EcpayShippingCatalog::TEMP_CHILLED => 'chilled',
			EcpayShippingCatalog::TEMP_FROZEN  => 'frozen',
			default                            => '',
		};
		if ( '' === $temperature ) {
			return $this->fulfillment_rejection( 'invalid_method_contract', '物流方式缺少有效的溫層契約。' );
		}

		$selection = [
			'provider_id' => 'ecpay',
			'method_id'   => $method_id,
			'destination' => $destination,
			'service'     => [
				'shipping_type'    => 'CVS' === (string) $descriptor['logistics_type'] ? 'cvs' : 'home',
				'temperature_class' => $temperature,
				'payment_method_id' => $payment_method,
				'collection_mode'   => $is_collection ? 'collect' : 'prepaid',
			],
		];
		$digest = self::fulfillment_selection_digest( $selection );
		if ( '' === $digest ) {
			return $this->fulfillment_rejection( 'selection_digest_failed', '無法建立物流收件資料摘要。' );
		}

		$weight_required = true === ( $descriptor['requires_goods_weight'] ?? false );
		$default_weight = null;
		if ( $weight_required ) {
			$raw_default = trim( (string) Settings::shipping_method_option( $method_id, 'goods_weight', '' ) );
			if ( preg_match( '/^(?:0|[1-9]\d*)(?:\.\d{1,3})?$/D', $raw_default )
				&& (float) $raw_default > 0.0
				&& (float) $raw_default <= 20.0 ) {
				$default_weight = number_format( (float) $raw_default, 3, '.', '' );
			}
		}

		$claim = [
			'provider_id'      => 'ecpay',
			'method_id'        => $method_id,
			'type'             => $claim_type,
			'token'            => $token,
			'selection_digest' => $digest,
		];
		$claim['seal'] = self::fulfillment_claim_seal( $claim );
		if ( '' === $claim['seal'] ) {
			return $this->fulfillment_rejection( 'claim_seal_failed', 'Unable to seal the fulfillment claim.' );
		}

		return [
			'handled'   => true,
			'ok'        => true,
			'code'      => '',
			'message'   => '',
			'selection' => $selection,
			'weight_policy' => [
				'required'          => $weight_required,
				'default_weight_kg' => $default_weight,
				'max_weight_kg'     => $weight_required ? '20.000' : null,
			],
			'claim' => $claim,
		];
	}

	/** Atomically consume the typed claim and bind it to the exact resolved digest. */
	public function claim_fulfillment_selection( $result, $claim, $data, $context = [] ): array {
		if ( is_array( $result ) && true === ( $result['handled'] ?? false ) ) {
			return $result;
		}
		if ( ! is_array( $claim ) || 'ecpay' !== (string) ( $claim['provider_id'] ?? '' ) ) {
			return is_array( $result ) ? $result : [ 'handled' => false ];
		}
		$claim_keys = array_keys( $claim );
		sort( $claim_keys );
		$expected_claim_keys = [ 'method_id', 'provider_id', 'seal', 'selection_digest', 'token', 'type' ];
		sort( $expected_claim_keys );
		$received_seal = is_string( $claim['seal'] ?? null ) ? $claim['seal'] : '';
		$expected_seal = self::fulfillment_claim_seal( $claim );
		if ( $claim_keys !== $expected_claim_keys
			|| '' === $received_seal
			|| '' === $expected_seal
			|| ! hash_equals( $expected_seal, $received_seal ) ) {
			return [ 'handled' => true, 'ok' => false, 'code' => 'invalid_provider_claim', 'message' => 'The fulfillment claim envelope is invalid.', 'digest' => '' ];
		}

		$data    = is_array( $data ) ? $data : [];
		$context = is_array( $context ) ? $context : [];
		$request = $this->canonical_fulfillment_request( $data, $context );
		if ( null === $request ) {
			return [ 'handled' => true, 'ok' => false, 'code' => 'invalid_fulfillment_context', 'message' => '物流結帳條件無法驗證，請重新整理後再試。', 'digest' => '' ];
		}
		$data = $request['data'];
		$claim_type = (string) ( $claim['type'] ?? '' );
		if ( 'store_selection' === $claim_type ) {
			$claim_token = is_string( $claim['token'] ?? null ) ? trim( $claim['token'] ) : '';
			if ( '' === $claim_token ) {
				return [ 'handled' => true, 'ok' => false, 'code' => 'invalid_provider_claim', 'message' => '物流收件憑證無法認領。', 'digest' => '' ];
			}
			// The opaque token captured during resolution is authoritative. Never
			// consume a browser-swapped token from the later POST.
			$data['ecpay_store_token'] = $claim_token;
		}
		$digest  = (string) ( $context['selection_digest'] ?? '' );
		$resolved = $this->resolve_fulfillment_selection( [ 'handled' => false ], $data, $context );
		if ( true !== ( $resolved['ok'] ?? false ) ) {
			return [
				'handled' => true,
				'ok'      => false,
				'code'    => (string) ( $resolved['code'] ?? 'claim_rejected' ),
				'message' => (string) ( $resolved['message'] ?? '物流收件憑證無法認領。' ),
				'digest'  => '',
			];
		}

		$recomputed = is_array( $resolved['selection'] ?? null )
			? self::fulfillment_selection_digest( $resolved['selection'] )
			: '';
		$resolved_claim = is_array( $resolved['claim'] ?? null ) ? $resolved['claim'] : [];
		if ( '' === $digest
			|| ! hash_equals( $digest, (string) ( $claim['selection_digest'] ?? '' ) )
			|| ! hash_equals( $digest, $recomputed )
			|| $claim !== $resolved_claim ) {
			return [ 'handled' => true, 'ok' => false, 'code' => 'claim_digest_mismatch', 'message' => '物流收件憑證與訂單內容不一致。', 'digest' => '' ];
		}

		if ( 'store_selection' === $claim_type ) {
			$claimed = EcpayStoreSelector::claim_selection_authoritative(
				$data,
				$request['method_id'],
				$request['payment_method']
			);
			if ( null !== $claimed['error'] ) {
				return [ 'handled' => true, 'ok' => false, 'code' => 'claim_rejected', 'message' => (string) $claimed['error'], 'digest' => '' ];
			}
		}

		return [ 'handled' => true, 'ok' => true, 'code' => '', 'message' => '', 'digest' => $digest ];
	}

	/** Seal the opaque claim so token/type substitutions cannot preserve its selection digest. */
	private static function fulfillment_claim_seal( array $claim ): string {
		$provider_id = $claim['provider_id'] ?? null;
		$method_id = $claim['method_id'] ?? null;
		$type = $claim['type'] ?? null;
		$token = $claim['token'] ?? null;
		$selection_digest = $claim['selection_digest'] ?? null;
		if ( ! is_string( $provider_id ) || ! is_string( $method_id ) || ! is_string( $type ) || ! is_string( $token )
			|| ! is_string( $selection_digest ) || '' === $provider_id || '' === $method_id || '' === $type
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $selection_digest ) ) {
			return '';
		}
		$lease = ProviderMaintenanceLock::reader_lease();
		if ( null === $lease ) {
			return '';
		}

		$credentials = Settings::logistics_credentials_for_method( $method_id );
		$hash_key = (string) ( $credentials['hash_key'] ?? '' );
		$hash_iv  = (string) ( $credentials['hash_iv'] ?? '' );
		if ( '' === $hash_key || '' === $hash_iv ) {
			return '';
		}

		$payload = wp_json_encode( [
			'provider_id'      => $provider_id,
			'method_id'        => $method_id,
			'type'             => $type,
			'token'            => $token,
			'selection_digest' => $selection_digest,
		], JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $payload ) ) {
			return '';
		}

		$key = hash( 'sha256', $hash_key . "\0" . $hash_iv, true );
		if ( ! ProviderMaintenanceLock::reader_fence( $lease->token ) ) {
			return '';
		}
		return hash_hmac( 'sha256', $payload, $key );
	}

	/**
	 * 結帳送出時驗證門市選擇（伺服器端，**只驗不消耗**）
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

		if ( ! $this->is_cvs_method( $shipping_method ) ) {
			return $errors;
		}

		$rejection = EcpayStoreSelector::verify_selection(
			is_array( $data ) ? $data : [],
			$shipping_method,
			$payment_method
		);

		if ( null !== $rejection ) {
			$errors[] = $rejection;
		}

		return $errors;
	}

	/**
	 * 訂單成立那一刻認領（消耗）門市選擇
	 *
	 * 🔴 與驗證分開的理由：驗證會因為**其他欄位**失敗，那時候把憑證用掉，
	 * 顧客補好欄位再送出就會被告知「請重新選擇門市」——而他沒動過門市。
	 * 一次性與原子性由 `claim_selection()` 保證，兩個併發的結帳仍只有一個過得去。
	 *
	 * @param string|null         $error
	 * @param array<string,mixed> $data
	 * @return string|null
	 */
	public function claim_store_selection( $error, $data, string $shipping_method, string $payment_method, $order_id = 0 ) {
		// 前面已經有人擋下來了就不要覆蓋。
		if ( is_string( $error ) && '' !== $error ) {
			return $error;
		}

		if ( ! $this->is_cvs_method( $shipping_method ) ) {
			return $error;
		}

		$claim = EcpayStoreSelector::claim_selection_authoritative(
			is_array( $data ) ? $data : [],
			$shipping_method,
			$payment_method
		);

		if ( null !== $claim['error'] ) {
			return $claim['error'];
		}

		return null;
	}

	private static function fulfillment_selection_digest( array $selection ): string {
		$encoded = wp_json_encode( $selection, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return is_string( $encoded ) ? hash( 'sha256', $encoded ) : '';
	}

	/**
	 * Replace browser-carried identity fields with the context Core read and
	 * validated for this exact cart. Empty context remains a legacy-compatible
	 * fallback for pre-contract callers.
	 *
	 * @return array{data:array<string,mixed>,method_id:string,payment_method:string,cart_scope:string,zero_payment_order:bool}|null
	 */
	private function canonical_fulfillment_request( array $data, array $context ): ?array {
		$has_typed_context = array_key_exists( 'method_id', $context )
			|| array_key_exists( 'payment_method', $context )
			|| array_key_exists( 'cart_scope', $context )
			|| array_key_exists( 'zero_payment_order', $context );

		if ( $has_typed_context
			&& ( ! is_string( $context['method_id'] ?? null )
				|| ! is_string( $context['payment_method'] ?? null )
				|| ! is_string( $context['cart_scope'] ?? null )
				|| ! is_bool( $context['zero_payment_order'] ?? null ) ) ) {
			return null;
		}

		$method_id = trim( $has_typed_context ? $context['method_id'] : (string) ( $data['shipping_method'] ?? '' ) );
		$payment_method = trim( $has_typed_context ? $context['payment_method'] : (string) ( $data['payment_method'] ?? '' ) );
		$cart_scope = sanitize_key( $has_typed_context ? $context['cart_scope'] : (string) ( $data['cart_scope'] ?? 'default' ) );
		if ( '' === $cart_scope || ! preg_match( '/^[a-z0-9_]{1,32}$/D', $cart_scope ) ) {
			$cart_scope = 'default';
		}
		if ( '' === $method_id ) {
			return null;
		}

		$data['shipping_method'] = $method_id;
		$data['payment_method']  = $payment_method;
		$data['cart_scope']      = $cart_scope;

		// Core keeps provider tokens generic. Adapt that field only after Core's
		// typed subscription id and reserved scope agree exactly; the selector
		// still verifies the server-minted marker/id/context record below.
		$scope_subscription_id = EcpayStoreSelector::subscription_id_from_scope( $cart_scope );
		$context_subscription_id = is_int( $context['subscription_id'] ?? null )
			? $context['subscription_id'] : 0;
		if ( $has_typed_context
			&& $scope_subscription_id > 0
			&& $context_subscription_id === $scope_subscription_id
			&& $cart_scope === 'sub_' . $context_subscription_id
			&& is_string( $data['selection_token'] ?? null ) ) {
			$data['ecpay_store_token'] = trim( $data['selection_token'] );
		}

		return [
			'data'               => $data,
			'method_id'          => $method_id,
			'payment_method'     => $payment_method,
			'cart_scope'         => $cart_scope,
			'zero_payment_order' => $has_typed_context ? $context['zero_payment_order'] : false,
		];
	}

	private function fulfillment_rejection( string $code, string $message ): array {
		return [ 'handled' => true, 'ok' => false, 'code' => $code, 'message' => $message ];
	}

	private function is_cvs_method( string $shipping_method ): bool {
		$descriptor = EcpayShippingCatalog::get( $shipping_method );

		return null !== $descriptor && 'CVS' === $descriptor['logistics_type'];
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
		if ( ! class_exists( YSShippingRegistry::class ) ) {
			return;
		}

		// 逐一由型錄註冊。加一個方式＝在型錄加一列，這裡不需要動——
		// 「型錄加了、註冊忘了」在語法上不可能發生。
		foreach ( EcpayShippingCatalog::all() as $method_id => $descriptor ) {
			if ( ! ShippingMethodOperability::is_operable( $method_id ) ) {
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

		register_rest_route(
			$namespace,
			'/stores/ecpay/reauthorize',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'ecpay_reauthorize_saved_store' ],
				'permission_callback' => [ YSRestAuth::class, 'permission_logged_in_write' ],
			]
		);

		// 🔴 這條路由**不得**替 `code` / `cart_scope` 宣告 `args` 的 `sanitize_callback`。
		//
		// WP 在 dispatch 前會把 sanitize 後的值寫回 `$request->params[...]`，而
		// `sanitize_text_field()` 對陣列輸入回空字串——於是 `?cart_scope[]=x` 會以
		// 純量 `''` 抵達 handler，`ecpay_store_result()` 的形狀閘門永遠不會觸發，
		// 請求靜默降成 default 並提領。看起來是「加強驗證」的改動會直接把
		// v029 釘住的那個缺陷放回來，而測試的 request stub 沒有 args pipeline，
		// 不會變紅。
		register_rest_route(
			$namespace,
			'/ecpay/store-result',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'ecpay_store_result' ],
				'permission_callback' => [ YSRestAuth::class, 'permission_customer_or_guest' ],
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
		if ( ! $this->has_enabled_shipping_methods() ) {
			return YSRestResponder::error( 'provider_disabled', '綠界物流尚未啟用。' );
		}

		$params = YSRequestParser::params( $request );

		// 🔴 形狀要在任何 string cast 與身分解析之前驗完。
		//
		// `YSRequestParser::params()` 完全不過濾型別，而下面每一行都直接 `(string)`／
		// `sanitize_*`：送 `{"cart_scope":["headless_1"]}` 會發 array-to-string warning、
		// 變成字串 `Array`，再被正規化成一個**合法但錯誤**的 scope，然後據此解析
		// principal 並簽發 map session 與 signed form。
		foreach ( [ 'shipping_id', 'context', 'subscription_id', 'order_id', 'return_url', 'payment_method' ] as $field ) {
			if ( array_key_exists( $field, $params ) && ! is_scalar( $params[ $field ] ) ) {
				return YSRestResponder::error( 'invalid_map_request', '選店請求的參數格式不正確。', 400 );
			}
		}

		$shipping_id      = sanitize_text_field( $params['shipping_id'] ?? '' );
		$context          = sanitize_key( $params['context'] ?? 'checkout' );
		$order_id         = absint( $params['order_id'] ?? 0 );
		$return_url  = esc_url_raw( (string) ( $params['return_url'] ?? '' ) );
		$is_subscription_context = false;
		$subscription_id = 0;

		if ( 'subscription' === $context ) {
			$subscription_context = $this->subscription_fulfillment_context( $params, $shipping_id );
			if ( true !== ( $subscription_context['ok'] ?? false ) ) {
				return $subscription_context['response'];
			}
			$shipping_id            = (string) $subscription_context['shipping_id'];
			$cart_scope             = (string) $subscription_context['cart_scope'];
			$payment_method         = (string) $subscription_context['payment_method'];
			$subscription_id        = (int) $subscription_context['subscription_id'];
			$is_subscription_context = true;
		} else {
			// `cart_scope` 走 canonical ABI：有提供就必須**已經是** canonical，
			// 只有完全未提供才用 default。詳見 CartScope。
			$cart_scope = CartScope::resolve( $params );
			if ( null === $cart_scope ) {
				return YSRestResponder::error( 'invalid_cart_scope', self::CART_SCOPE_ERROR, 400 );
			}
			if ( EcpayStoreSelector::subscription_id_from_scope( $cart_scope ) > 0 ) {
				return YSRestResponder::error( 'reserved_subscription_scope', '訂閱購物階段必須由訂閱流程授權。', 400 );
			}
			$payment_method = '';
		}

		$principal = EcpayStoreSelector::current_principal( $cart_scope );
		if ( '' === $principal ) {
			return YSRestResponder::error( 'identity_unavailable', '無法辨識目前購物階段，請重新整理後再試。', 401 );
		}
		if ( class_exists( YSRateLimiter::class ) ) {
			$actor_allowed = YSRateLimiter::check( 'ecpay_map_actor_' . substr( hash( 'sha256', $principal ), 0, 24 ), 12, 60 );
			$ip_allowed    = YSRateLimiter::check( 'ecpay_map_ip', 60, 60 );
			if ( ! $actor_allowed || ! $ip_allowed ) {
				return YSRestResponder::error( 'rate_limited', '選店請求過於頻繁，請稍後再試。', 429 );
			}
		}

		if ( '' === $shipping_id ) {
			return YSRestResponder::error( 'missing_shipping_id', '缺少物流方式 ID。' );
		}

		// 🔴 付款方式決定電子地圖要用「代收」還是「不代收」去篩門市，而綠界對兩者
		// 給的門市清單不同。缺這個欄位不是「預設不代收」，是**無法證明**——
		// 猜錯的代價是顧客選得到門市、結完帳、送單當下才被綠界拒絕。
		if ( 'subscription' !== $context && ! isset( $params['payment_method'] ) ) {
			return YSRestResponder::error(
				'missing_payment_method',
				'缺少付款方式，無法決定電子地圖的代收模式。'
			);
		}

		if ( 'subscription' !== $context ) {
			$payment_method = sanitize_text_field( (string) $params['payment_method'] );
		}

		// 🔴 「有帶這個欄位」不等於「帶了一個有效的付款方式」。
		//
		// 只檢查 key 存在的話，這三種都會過，而且全部被靜默當成不代收：
		//   payment_method=""            → IsCollection=N
		//   payment_method="不存在的金流" → IsCollection=N
		//   payment_method=ys_ec_cod + 不支援代收的方式 → IsCollection=N
		//
		// 最後那一種最傷：顧客選了貨到付款，地圖卻用「不代收」去篩門市，他選得到
		// 一個不支援代收的門市，結完帳，然後送單那一天綠界才拒絕。缺值與錯值都是
		// **無法證明**，不是「預設不代收」。
		$payment_rejection = $this->reject_invalid_payment_method( $payment_method, $shipping_id );
		if ( null !== $payment_rejection ) {
			return $payment_rejection;
		}

		if ( ! ShippingMethodOperability::is_operable( $shipping_id ) ) {
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
		$shipping_allowed = $is_subscription_context
			? true
			: $this->is_shipping_allowed_for_cart( $shipping_id, $cart_scope );
		if ( ! $shipping_allowed ) {
			return YSRestResponder::error( 'shipping_method_not_allowed', '購物車內商品不支援此物流方式。' );
		}

		$result = EcpayStoreSelector::build_map_form_data( $shipping_id, $context, $order_id, $cart_scope, $return_url, $payment_method, $subscription_id );
		if ( $result ) {
			return YSRestResponder::success( 'map_url_ready', '', $result );
		}

		return YSRestResponder::error( 'map_url_failed', '綠界物流設定尚未完成或不支援此物流方式。' );
	}

	/**
	 * Resolve one subscription-owned fulfillment tuple from Core authority.
	 *
	 * The browser may identify a subscription, but it never chooses the scope,
	 * payment gateway or product identity used by the provider.  Those values are
	 * read from the current Core row and caller-supplied copies are accepted only
	 * when they match exactly.
	 *
	 * @param array<string,mixed> $params
	 * @return array{ok:true,subscription_id:int,owner_user_id:int,shipping_id:string,cart_scope:string,payment_method:string,item:array{product_id:int,variant_id:int}}|array{ok:false,response:\WP_REST_Response}
	 */
	private function subscription_fulfillment_context( array $params, string $shipping_id ): array {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 || ! is_user_logged_in() ) {
			return [
				'ok'       => false,
				'response' => YSRestResponder::error( 'authentication_required', '請先登入再變更訂閱取貨門市。', 401 ),
			];
		}

		foreach ( [ 'subscription_id', 'cart_scope', 'payment_method' ] as $field ) {
			if ( array_key_exists( $field, $params ) && ! is_scalar( $params[ $field ] ) ) {
				return [
					'ok'       => false,
					'response' => YSRestResponder::error( 'invalid_subscription_context', '訂閱取貨資料格式不正確。', 400 ),
				];
			}
		}

		if ( ! array_key_exists( 'subscription_id', $params ) || absint( $params['subscription_id'] ) <= 0 ) {
			return [
				'ok'       => false,
				'response' => YSRestResponder::error( 'missing_subscription_id', '缺少有效的訂閱 ID。', 400 ),
			];
		}
		$subscription_id = absint( $params['subscription_id'] );

		if ( ! class_exists( YSSubscription::class ) || ! method_exists( YSSubscription::class, 'find' ) ) {
			return [
				'ok'       => false,
				'response' => YSRestResponder::error( 'subscription_context_unavailable', '目前無法驗證訂閱資料，請稍後再試。', 503 ),
			];
		}

		try {
			$subscription = YSSubscription::find( $subscription_id );
		} catch ( \Throwable $error ) {
			return [
				'ok'       => false,
				'response' => YSRestResponder::error( 'subscription_context_unavailable', '目前無法驗證訂閱資料，請稍後再試。', 503 ),
			];
		}
		if ( ! is_object( $subscription ) || $subscription_id !== (int) ( $subscription->id ?? 0 ) ) {
			return self::hidden_subscription_context();
		}

		$owner_id = (int) ( $subscription->user_id ?? 0 );
		$is_admin = function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
		if ( $owner_id <= 0 || ( ! $is_admin && $user_id !== $owner_id ) ) {
			return self::hidden_subscription_context();
		}

		$status = trim( (string) ( $subscription->status ?? '' ) );
		if ( ! in_array( $status, [ 'pending', 'active', 'on-hold', 'suspended' ], true ) ) {
			return [
				'ok'       => false,
				'response' => YSRestResponder::error( 'subscription_stale', '這筆訂閱已無法變更取貨門市。', 409 ),
			];
		}

		$product_id     = (int) ( $subscription->product_id ?? 0 );
		$variant_id     = max( 0, (int) ( $subscription->variant_id ?? 0 ) );
		$payment_method = trim( (string) ( $subscription->gateway_id ?? '' ) );
		if ( $product_id <= 0 || '' === $payment_method ) {
			return [
				'ok'       => false,
				'response' => YSRestResponder::error( 'subscription_stale', '這筆訂閱缺少可用的商品或付款資料。', 409 ),
			];
		}

		$cart_scope = 'sub_' . $subscription_id;
		if ( array_key_exists( 'cart_scope', $params ) ) {
			$provided_scope = CartScope::resolve( $params );
			if ( null === $provided_scope ) {
				return [
					'ok'       => false,
					'response' => YSRestResponder::error( 'invalid_cart_scope', self::CART_SCOPE_ERROR, 400 ),
				];
			}
			if ( $cart_scope !== $provided_scope ) {
				return [
					'ok'       => false,
					'response' => YSRestResponder::error( 'subscription_scope_mismatch', '購物階段與訂閱不相符。', 409 ),
				];
			}
		}

		if ( array_key_exists( 'payment_method', $params )
			&& $payment_method !== sanitize_text_field( wp_unslash( (string) $params['payment_method'] ) ) ) {
			return [
				'ok'       => false,
				'response' => YSRestResponder::error( 'subscription_payment_mismatch', '付款方式與訂閱不相符。', 409 ),
			];
		}

		if ( '' === $shipping_id ) {
			return [
				'ok'       => false,
				'response' => YSRestResponder::error( 'missing_shipping_id', '缺少物流方式 ID。', 400 ),
			];
		}

		$item = [
			'product_id' => $product_id,
			'variant_id' => $variant_id,
		];
		if ( ! $this->is_shipping_allowed_for_subscription( $shipping_id, $item ) ) {
			return [
				'ok'       => false,
				'response' => YSRestResponder::error( 'shipping_method_not_allowed', '購物車內商品不支援此物流方式。', 400 ),
			];
		}

		return [
			'ok'             => true,
			'subscription_id' => $subscription_id,
			'owner_user_id'  => $owner_id,
			'shipping_id'    => $shipping_id,
			'cart_scope'     => $cart_scope,
			'payment_method' => $payment_method,
			'item'           => $item,
		];
	}

	/**
	 * Missing and unauthorized subscriptions deliberately share one response.
	 *
	 * Status and row data are evaluated only after this ownership gate, so a
	 * logged-in caller cannot enumerate another customer's active/terminal rows.
	 *
	 * @return array{ok:false,response:\WP_REST_Response}
	 */
	private static function hidden_subscription_context(): array {
		return [
			'ok'       => false,
			'response' => YSRestResponder::error( 'subscription_not_found', '找不到這筆訂閱。', 404 ),
		];
	}

	public function ecpay_store_result( \WP_REST_Request $request ): \WP_REST_Response {
		// This is a GET endpoint. Core's shared storefront parser intentionally reads
		// JSON/form bodies only, so using it here silently discarded both `code` and
		// `cart_scope` from the documented query string and made every claim fail.
		$query = $request->get_query_params();

		// 🔴 形狀要在**身分之前**驗完，而且是直接驗 raw query bag。
		//
		// 「把非純量丟掉、然後照常解析 principal 並提領」不是 fail-closed：
		//
		//     code=<有效的一次性提領碼>&cart_scope[]=…
		//
		// 丟掉畸形的 scope 之後，它會靜默降成 `default`，於是我們拿
		// `principal:default` 去碰一張**有效**的提領碼——只要那張碼本來就是在
		// default scope 下發的，這個畸形請求就會把它消耗掉，而顧客那一邊得重選門市。
		//
		// 非純量不是「奇怪的值」，是**另一種型別**：呼叫端送錯形狀，就不該由我們
		// 猜一個 scope 替它把提領碼用掉。
		//
		// 🔴 code 驗的是**鑄造格式**（exact `^[A-Za-z0-9]{32}$`，精確全字串），不是
		// 「非空」。鑄造端是 `generate_result_code()`：恆為 32 個英數字。任何不符的
		// 值——缺席、null、陣列、空白、`'0'`、`'zzz'`、31/33 字元、帶標點——**永遠不
		// 可能**是一張真的提領碼；只驗非空的話，這類垃圾請求仍會解析身分、花掉共享
		// 限流配額、再進提領層做一次必然失敗的 transient 讀取。直接對 raw string 驗，
		// 不先 sanitize（sanitize 會把 `…%0A` trim 成合法長度，而鑄造端從不產生那種值）。
		// `?? null` 同時涵蓋缺席與明給 null——兩者都不是字串，一律拒絕。
		$code = $query['code'] ?? null;
		if ( ! is_string( $code ) || 1 !== preg_match( self::RESULT_CODE_PATTERN, $code ) ) {
			return self::store_result_rejected();
		}

		// `cart_scope` 走 canonical ABI（見 CartScope）：非純量、非 canonical、`null`
		// 一律拒絕；只有完全未提供才用 default。**不得**正規化後改綁到另一個 scope。
		$scope = CartScope::resolve( $query );
		if ( null === $scope ) {
			return self::store_result_rejected();
		}

		// 🔴 順序是契約：形狀 → 身分 → 計量 → 提領（v029／v031 釘住）。
		//
		// 身分解析在計量**之前**：辨識不出 principal 的請求（匿名且無 guest token）
		// 沒有 actor bucket 可言，而 Core 的 `get_client_ip()` 在 CDN／反向代理後全站
		// 共用一個 IP bucket——替這種請求計量，等於讓匿名垃圾流量把正常顧客的共享
		// 配額燒光、阻塞合法 claim。拒絕沿用同一句 generic 400，不多洩漏任何判別位元。
		$principal = EcpayStoreSelector::current_principal( $scope );
		if ( '' === $principal ) {
			return self::store_result_rejected();
		}

		// 🔴 計量在身分之後、提領之前：actor（由 principal 導出）＋共享 IP 兩個 bucket
		// 都要過。429 之後**不得**提領——限流是 claim 的前置條件，不是事後記帳。
		//
		// 限流器缺席時 fail-safe 為**放行**：這是顧客結帳路徑上的一次性提領，
		// 沒有限流器就整條擋掉會讓 headless 站完全選不了門市；而本端點本身已由
		// 32 字元不可猜的提領碼與 principal 綁定守著。ECPay 宣告的最低核心是
		// 2.58.0，該版本一定有 YSRateLimiter；缺席只可能出現在不受支援的組合。
		// 契約由 v031 的子程序案例釘住（在完全沒有 YSRateLimiter 的執行環境重跑）。
		if ( ! self::store_result_within_rate_limit( $principal ) ) {
			$limited = YSRestResponder::error( 'rate_limited', '提領請求過於頻繁，請稍後再試。', 429 );
			$limited->header( 'Cache-Control', 'no-store, private' );

			return $limited;
		}

		$claimed = EcpayStoreSelector::claim_result_code( $code, $principal );

		if ( null !== $claimed['error'] ) {
			$response = YSRestResponder::error( 'store_result_invalid', (string) $claimed['error'], 400 );
		} else {
			$response = YSRestResponder::success( 'store_result_ready', '', $claimed['store'] );
		}
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	/**
	 * 形狀不合格的提領請求：400，且**沒有**解析過身分、沒有碰過提領碼。
	 *
	 * 🔴 訊息刻意重用 `claim_result_code()` 既有的那一句，不另外寫新字串。
	 * 這個端點在 permission 之前就會回應，新增一種只有「形狀錯」才看得到的訊息
	 * 等於免費送給呼叫端一個新的判別位元；沿用同一句話，前置拒絕與提領層拒絕
	 * 在外部看起來完全一樣。
	 *
	 * 仍然帶 `no-store, private`——被拒絕的回應同樣不該被任何中介快取。
	 */
	private static function store_result_rejected(): \WP_REST_Response {
		$response = YSRestResponder::error( 'store_result_invalid', '缺少提領碼或無法辨識身分。', 400 );
		$response->header( 'Cache-Control', 'no-store, private' );

		return $response;
	}

	/**
	 * 公開提領端點的節流閘門——只在 principal 已解析成功後呼叫。
	 *
	 * 複用 Core 既有的 `YSRateLimiter`（與 `ecpay_map_url()` 同一個 API 與同一套
	 * bucket 派生法），**不另建**任何平行的共用儲存。限流器不可用時 fail-safe 為
	 * 放行——理由見呼叫端註解。
	 */
	private static function store_result_within_rate_limit( string $principal ): bool {
		if ( ! class_exists( YSRateLimiter::class ) || ! method_exists( YSRateLimiter::class, 'check' ) ) {
			return true;
		}

		// 🔴 actor ＋ IP 兩個 bucket，與姊妹端點 `ecpay_map_url()` 同構：
		//
		//   - actor bucket 的 key 由 principal 的 SHA-256 導出（同 map-url 的
		//     `ecpay_map_actor_*` 派生法，12/60）——它**只可能在 principal 之後**存在，
		//     bucket key 因此不受攻擊者控制。
		//   - 共享 IP bucket（60/60，同 `ecpay_map_ip`）必須保留：Core 的
		//     `get_client_ip()` 預設只回 `REMOTE_ADDR`（除非站方註冊
		//     `ys_ec_trusted_proxies`），CDN／反向代理後全站共用一個 bucket——只靠
		//     guest actor bucket 的話，攻擊者換一個 token 就換一個 bucket，IP 層就沒有
		//     任何上限了。
		//
		// 兩個 check 都先執行再合併判定（不 short-circuit），讓兩個 bucket 的計量
		// 一致——與 map-url 現行行為相同。
		$actor_allowed = (bool) YSRateLimiter::check( 'ecpay_store_result_actor_' . substr( hash( 'sha256', $principal ), 0, 24 ), 12, 60 );
		$ip_allowed    = (bool) YSRateLimiter::check( 'ecpay_store_result_ip', 60, 60 );

		return $actor_allowed && $ip_allowed;
	}

	public function ecpay_reauthorize_saved_store( \WP_REST_Request $request ): \WP_REST_Response {
		$params        = YSRequestParser::params( $request );
		$owner_user_id = null;
		$subscription_id = 0;

		if ( array_key_exists( 'context', $params ) && ! is_scalar( $params['context'] ) ) {
			$response = YSRestResponder::error( 'invalid_saved_store_request', '已儲存門市的重新授權資料格式錯誤。', 400 );
			$response->header( 'Cache-Control', 'no-store, private' );
			return $response;
		}

		$context = sanitize_key( $params['context'] ?? 'checkout' );
		if ( 'subscription' === $context ) {
			foreach ( [ 'subscription_id', 'address_id', 'shipping_id', 'cart_scope', 'payment_method' ] as $field ) {
				if ( array_key_exists( $field, $params ) && ! is_scalar( $params[ $field ] ) ) {
					$response = YSRestResponder::error( 'invalid_saved_store_request', '已儲存門市的重新授權資料格式錯誤。', 400 );
					$response->header( 'Cache-Control', 'no-store, private' );
					return $response;
				}
			}

			$shipping_id = sanitize_text_field( wp_unslash( (string) ( $params['shipping_id'] ?? '' ) ) );
			$authority   = $this->subscription_fulfillment_context( $params, $shipping_id );
			if ( true !== ( $authority['ok'] ?? false ) ) {
				$response = $authority['response'];
				$response->header( 'Cache-Control', 'no-store, private' );
				return $response;
			}

			$owner_user_id           = (int) $authority['owner_user_id'];
			$subscription_id         = (int) $authority['subscription_id'];
			$params['shipping_id']    = (string) $authority['shipping_id'];
			$params['cart_scope']     = (string) $authority['cart_scope'];
			$params['payment_method'] = (string) $authority['payment_method'];
		} else {
			$legacy_scope = CartScope::resolve( $params );
			if ( is_string( $legacy_scope ) && EcpayStoreSelector::subscription_id_from_scope( $legacy_scope ) > 0 ) {
				$response = YSRestResponder::error( 'reserved_subscription_scope', '訂閱購物階段必須由訂閱流程授權。', 400 );
				$response->header( 'Cache-Control', 'no-store, private' );
				return $response;
			}
		}

		try {
			$result = EcpaySavedStoreReauthorizer::reauthorize( $params, $owner_user_id, $subscription_id );
		} catch ( \Throwable $e ) {
			$result = [
				'success' => false,
				'code'    => 'saved_store_reauthorization_failed',
				'message' => '目前無法重新授權已儲存門市，請稍後再試。',
				'status'  => 503,
				'data'    => [],
			];
		}

		if ( true === ( $result['success'] ?? false ) ) {
			$response = YSRestResponder::success(
				(string) ( $result['code'] ?? 'saved_store_reauthorized' ),
				(string) ( $result['message'] ?? '' ),
				is_array( $result['data'] ?? null ) ? $result['data'] : []
			);
		} else {
			$response = YSRestResponder::error(
				(string) ( $result['code'] ?? 'saved_store_reauthorization_failed' ),
				(string) ( $result['message'] ?? '' ),
				(int) ( $result['status'] ?? 400 ),
				is_array( $result['data'] ?? null ) ? $result['data'] : []
			);
		}

		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	/**
	 * 電子地圖的付款方式守門
	 *
	 * 回 `null` 代表通過；回 response 代表拒絕。
	 *
	 * 三道：
	 *   1. 不得為空字串（空值不是「不代收」，是無法證明）。
	 *   2. 必須是**已註冊**的金流；認不出來的字串不得被靜默當成非代收。
	 *   3. 貨到付款 × 不支援代收的物流方式 → 直接拒絕，不要開一張以「不代收」
	 *      篩出來的地圖給顧客選。
	 */
	private function reject_invalid_payment_method( string $payment_method, string $shipping_id ): ?\WP_REST_Response {
		if ( '' === $payment_method ) {
			return YSRestResponder::error(
				'missing_payment_method',
				'缺少付款方式，無法決定電子地圖的代收模式。'
			);
		}

		// 核心不在（或版本太舊）時 fail-closed。守門靜默消失比擋錯一次糟得多。
		if ( ! class_exists( YSGatewayRegistry::class )
			|| ! method_exists( YSGatewayRegistry::class, 'get' ) ) {
			return YSRestResponder::error(
				'gateway_registry_unavailable',
				'無法驗證付款方式，請稍後再試。'
			);
		}

		if ( null === YSGatewayRegistry::get( $payment_method ) ) {
			return YSRestResponder::error(
				'unknown_payment_method',
				'付款方式無效，無法決定電子地圖的代收模式。'
			);
		}

		if ( EcpayStoreSelector::COD_GATEWAY_ID !== $payment_method ) {
			return null;
		}

		$descriptor = EcpayShippingCatalog::get( $shipping_id );
		if ( null === $descriptor || empty( $descriptor['cod_capable'] ) ) {
			return YSRestResponder::error(
				'cod_not_supported_by_method',
				'此物流方式不支援貨到付款，請更換付款方式或運送方式。'
			);
		}

		return null;
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
	 * Apply Core's existing product shipping restriction to the subscription line.
	 *
	 * @param array{product_id:int,variant_id:int} $item
	 */
	private function is_shipping_allowed_for_subscription( string $shipping_id, array $item ): bool {
		if ( ! class_exists( YSShippingRegistry::class )
			|| ! method_exists( YSShippingRegistry::class, 'is_method_allowed_for_cart' ) ) {
			return false;
		}

		return YSShippingRegistry::is_method_allowed_for_cart( $shipping_id, [ $item ] );
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

			// headless 前端在另一個 origin 時沒有我方 cookie，訪客身分來自
			// `X-YS-Guest-Token`（核心的購物車自 2.56.6 起也認它）。少了這一條，
			// header-only 的訪客會被當成空車，於是所有物流方式都「不受商品限制」。
			$has_guest_token = method_exists( $handler, 'guest_token_for_cart' )
				&& '' !== $handler::guest_token_for_cart();

			if ( empty( $_COOKIE[ $cookie ] ) && ! $has_guest_token ) {
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

		if ( $method instanceof EcpayShipping && ShippingMethodOperability::is_operable( $method->get_id() ) ) {
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
		return ShippingMethodOperability::has_operable_method();
	}

	private function is_method_enabled( string $domain, string $method_id ): bool {
		if ( 'shipping' === $domain ) {
			return ShippingMethodOperability::is_operable( $method_id );
		}

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

		return false;
	}
}
