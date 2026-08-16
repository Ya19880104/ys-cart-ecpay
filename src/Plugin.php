<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay;

defined( 'ABSPATH' ) || exit;

use YangSheep\Ecommerce\Api\Storefront\YSRequestParser;
use YangSheep\Ecommerce\Api\Storefront\YSRestAuth;
use YangSheep\Ecommerce\Api\Storefront\YSRestResponder;
use YangSheep\Ecommerce\Gateways\YSGatewayRegistry;
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
use YangSheep\YSCartEcpay\Support\ProviderMaintenanceLock;
use YangSheep\YSCartEcpay\Support\Settings;
use YangSheep\YSCartEcpay\Support\ShippingMethodOperability;

final class Plugin {
	private static ?self $instance = null;

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
			|| ! class_exists( '\YangSheep\Ecommerce\Database\YSMigration' )
			|| ! method_exists( '\YangSheep\Ecommerce\Database\YSMigration', 'shipping_label_dispatch_schema_ready' )
			|| ! method_exists( '\YangSheep\Ecommerce\Database\YSMigration', 'address_shipping_provider_schema_ready' )
			|| ! method_exists( '\YangSheep\Ecommerce\Services\Shipping\YSShippingDispatchAuthority', 'active_attempt' )
			|| ! class_exists( '\YangSheep\Ecommerce\Handlers\YSShippingHandler' )
			|| ! method_exists( '\YangSheep\Ecommerce\Handlers\YSShippingHandler', 'query_shipping_status_for_order' )
			|| ! class_exists( '\YangSheep\Ecommerce\Services\Shipping\YSShippingPipelineService' )
			|| ! interface_exists( '\YangSheep\Ecommerce\Services\Payment\YSPaymentReconcilerInterface' )
			|| ! method_exists( '\YangSheep\Ecommerce\Shipping\YSShippingRegistry', 'is_method_allowed_for_cart' ) ) {
			return [
				'met'     => false,
				'reason'  => 'core_capability_missing',
				'message' => '核心缺少物流建單授權或商品物流守門的 API，綠界物流方式未註冊。',
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
			(string) YS_ECOMMERCE_VERSION . '|' . ( defined( 'YS_CART_ECPAY_VERSION' ) ? (string) YS_CART_ECPAY_VERSION : 'dev' ) . '|v2'
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

		$params      = YSRequestParser::params( $request );
		$shipping_id = sanitize_text_field( $params['shipping_id'] ?? '' );
		$context     = sanitize_key( $params['context'] ?? 'checkout' );
		$order_id    = absint( $params['order_id'] ?? 0 );
		$cart_scope  = self::sanitize_cart_scope( (string) ( $params['cart_scope'] ?? 'default' ) );
		$return_url  = esc_url_raw( (string) ( $params['return_url'] ?? '' ) );
		$principal   = EcpayStoreSelector::current_principal( $cart_scope );
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
		if ( ! isset( $params['payment_method'] ) ) {
			return YSRestResponder::error(
				'missing_payment_method',
				'缺少付款方式，無法決定電子地圖的代收模式。'
			);
		}

		$payment_method = sanitize_text_field( (string) $params['payment_method'] );

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
		if ( ! $this->is_shipping_allowed_for_cart( $shipping_id, $cart_scope ) ) {
			return YSRestResponder::error( 'shipping_method_not_allowed', '購物車內商品不支援此物流方式。' );
		}

		$result = EcpayStoreSelector::build_map_form_data( $shipping_id, $context, $order_id, $cart_scope, $return_url, $payment_method );
		if ( $result ) {
			return YSRestResponder::success( 'map_url_ready', '', $result );
		}

		return YSRestResponder::error( 'map_url_failed', '綠界物流設定尚未完成或不支援此物流方式。' );
	}

	public function ecpay_store_result( \WP_REST_Request $request ): \WP_REST_Response {
		$params     = YSRequestParser::params( $request );
		$scope      = self::sanitize_cart_scope( (string) ( $params['cart_scope'] ?? 'default' ) );
		$principal  = EcpayStoreSelector::current_principal( $scope );
		$code       = sanitize_text_field( (string) ( $params['code'] ?? '' ) );
		$claimed    = EcpayStoreSelector::claim_result_code( $code, $principal );

		if ( null !== $claimed['error'] ) {
			$response = YSRestResponder::error( 'store_result_invalid', (string) $claimed['error'], 400 );
		} else {
			$response = YSRestResponder::success( 'store_result_ready', '', $claimed['store'] );
		}
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	public function ecpay_reauthorize_saved_store( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$result = EcpaySavedStoreReauthorizer::reauthorize( YSRequestParser::params( $request ) );
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
