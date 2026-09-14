<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Ecpg;

defined( 'ABSPATH' ) || exit;

use YangSheep\Ecommerce\DTOs\YSPaymentDetailDTO;
use YangSheep\Ecommerce\Models\YSCreditCard;
use YangSheep\Ecommerce\Models\YSOrder;
use YangSheep\Ecommerce\Models\YSSubscription;
use YangSheep\Ecommerce\Services\Payment\YSPaymentDetailStore;
use YangSheep\Ecommerce\Services\Payment\YSPaymentEffects;
use YangSheep\Ecommerce\Services\Payment\YSPaymentLifecycleService;
use YangSheep\Ecommerce\Utils\YSLogger;
use YangSheep\YSCartEcpay\Payment\EcpayPaymentAttempt;
use YangSheep\YSCartEcpay\Support\OrderPaymentDetail;
use YangSheep\YSCartEcpay\Support\Settings;

/**
 * 站內付 2.0 授權結果 → YS CART 訂單狀態（v0.5.0）
 *
 * 同一筆交易的結果最多會從**三條路**進來：CreateBindCard／CreatePayment 的同步回應、
 * 3D 驗證後瀏覽器導回的 OrderResultURL、綠界幕後送的 ReturnURL（最多重送 4 次）。
 * 三條路都走這裡，順序不定、可能重複——因此每一步都必須是冪等的：
 *
	 *   - 付款成功 → `mark_paid()`：processing 重送由核心以同一 receipt 冪等續作；若訂單已進入
	 *     後續履約狀態，這裡只從該 attempt 已存在的 processing receipt 續作 provider effect。
 *   - 存卡 → `YSCreditCard::create_or_get()`：以 token_hash 去重，重跑拿到同一列。
 *
 * 🔴 驗證順序：MerchantID → MerchantTradeNo 歸屬 → RtnCode → TradeStatus → 金額 → TradeNo。
 * 金額比的是**建單時實際送出的** `ecpay_charged_amount`，不是 `$order->total`（可能已被
 * 其他流程改動）。任何一關沒過都不改狀態，回 `rejected` 讓呼叫端決定怎麼回應綠界。
 */
final class EcpgSettlement {
	public const STATUS_PAID           = 'paid';
	public const STATUS_ALREADY_PAID   = 'already_paid';
	public const STATUS_PENDING        = 'pending';
	public const STATUS_FAILED         = 'failed';
	public const STATUS_REJECTED       = 'rejected';
	public const STATUS_PERSIST_FAILED = 'persist_failed';

	public const VAULT_DONE    = 'done';
	public const VAULT_FAILED  = 'failed';
	public const VAULT_SKIPPED = 'skipped';

	/** States that already prove the same order is paid or in fulfillment. */
	private const PAID_FULFILLMENT_STATES = [
		'processing'    => true,
		'paid'          => true,
		'awaiting_ship' => true,
		'shipping'      => true,
		'shipped'       => true,
		'completed'     => true,
	];

	/**
	 * 以 MerchantTradeNo 找回訂單（`mer_trade_no` 或 `ecpay_merchant_trade_no` 任一命中）。
	 *
	 * `JSON_UNQUOTE(JSON_EXTRACT(...)) = %s`——不能寫成 `JSON_EXTRACT(...) = '"x"'`，那在
	 * MySQL 與 MariaDB 上都永遠為假且不報錯（Core 2.62.2 的教訓）。
	 */
	public static function find_order_by_merchant_trade_no( string $merchant_trade_no ): ?object {
		$merchant_trade_no = trim( $merchant_trade_no );
		if ( '' === $merchant_trade_no || 1 !== preg_match( '/^[A-Za-z0-9]{1,20}$/', $merchant_trade_no ) ) {
			return null;
		}
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return null;
		}
		$table = $wpdb->prefix . YS_ECOMMERCE_TABLE_PREFIX . 'orders';
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE JSON_UNQUOTE(JSON_EXTRACT(payment_detail, '$.mer_trade_no')) = %s
				    OR JSON_UNQUOTE(JSON_EXTRACT(payment_detail, '$.ecpay_merchant_trade_no')) = %s
				    OR JSON_SEARCH(payment_detail, 'one', %s, NULL,
				       '$.ys_payment_attempt_history[*].fields.mer_trade_no',
				       '$.ys_payment_attempt_history[*].fields.ecpay_merchant_trade_no') IS NOT NULL
				 ORDER BY id DESC LIMIT 1",
				$merchant_trade_no,
				$merchant_trade_no,
				$merchant_trade_no
			)
		);
		if ( ! is_object( $row ) ) {
			return null;
		}
		// 走 YSOrder 的正式讀取（快取、型別），並再確認歸屬。
		YSOrder::forget( (int) ( $row->id ?? 0 ) );
		$order = YSOrder::find( (int) ( $row->id ?? 0 ) );
		return $order && self::order_has_merchant_trade_no( $order, $merchant_trade_no ) ? $order : null;
	}

	public static function order_has_merchant_trade_no( object $order, string $merchant_trade_no ): bool {
		$detail = self::detail_of( $order );
		return '' !== $merchant_trade_no
			&& EcpayPaymentAttempt::identity_is_discoverable( $detail, $merchant_trade_no );
	}

	/** @return array<string,mixed> */
	public static function detail_of( object $order ): array {
		$raw = $order->payment_detail ?? null;
		if ( is_array( $raw ) ) {
			return $raw;
		}
		$detail = json_decode( (string) ( $raw ?? '{}' ), true );
		return is_array( $detail ) ? $detail : [];
	}

	/**
	 * 套用一份解密後的授權結果（RtnCode 1 與否都可交進來）。
	 *
	 * @param array<string,mixed> $data   解密後的 Data
	 * @param string              $source 'ecpg_confirm'｜'ecpg_return'｜'ecpg_result'｜'ecpg_charge_saved'
	 * @return array{status:string,message:string,vault:string,retryable:bool}
	 */
	public static function apply( object $order, array $data, string $source, ?array $verified_identity = null ): array {
		$order_id    = (int) ( $order->id ?? 0 );
		$identity    = self::settlement_identity( $verified_identity );
		if ( null === $identity || (string) ( $data['MerchantID'] ?? '' ) !== $identity['merchant_id'] ) {
			return self::outcome( self::STATUS_REJECTED, 'MerchantID 與本站設定不符。' );
		}

		$order_info        = is_array( $data['OrderInfo'] ?? null ) ? $data['OrderInfo'] : [];
		$merchant_trade_no = (string) ( $order_info['MerchantTradeNo'] ?? '' );
		if ( ! self::order_has_merchant_trade_no( $order, $merchant_trade_no ) ) {
			return self::outcome( self::STATUS_REJECTED, 'MerchantTradeNo 不屬於這張訂單。' );
		}

		$detail_now = OrderPaymentDetail::read( $order_id );
		if ( null === $detail_now ) {
			return self::outcome( self::STATUS_PERSIST_FAILED, '付款資料讀取失敗。', self::VAULT_SKIPPED, true );
		}
		$card_info  = is_array( $data['CardInfo'] ?? null ) ? $data['CardInfo'] : [];
		$reported   = $order_info['TradeAmt'] ?? $card_info['Amount'] ?? null;
		$reported_amount = ( is_int( $reported ) || ( is_string( $reported ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $reported ) ) )
			? (int) $reported
			: 0;
		$environment = $identity['environment'];
		$rtn_code = EcpgClient::parse_result_code( $data['RtnCode'] ?? null );
		if ( null === $rtn_code ) {
			return self::outcome( self::STATUS_REJECTED, 'RtnCode 格式無法確認。' );
		}
		$action   = 1 === $rtn_code ? EcpayPaymentAttempt::ACTION_SUCCESS : EcpayPaymentAttempt::ACTION_FAILURE;
		if ( EcpayPaymentAttempt::historical_callback_matches(
			$order,
			$detail_now,
			$merchant_trade_no,
			$reported_amount,
			(string) ( $data['MerchantID'] ?? '' ),
			$environment
		) ) {
			return self::outcome( self::STATUS_REJECTED, '付款結果屬於已歸檔的付款嘗試。' );
		}
		$claim = EcpayPaymentAttempt::callback_claim(
			$order,
			$detail_now,
			$merchant_trade_no,
			$reported_amount,
			(string) ( $data['MerchantID'] ?? '' ),
			$environment,
			$action
		);
		if ( null === $claim ) {
			YSLogger::error( 'ecpay', 'ECPG 授權金額與建單金額不符，拒絕標記已付', [
				'order_id' => $order_id,
				'reported' => $reported,
				'source'   => $source,
			] );
			return self::outcome( self::STATUS_REJECTED, '付款結果不屬於目前付款嘗試，或授權金額不符。' );
		}

		if ( EcpgClient::RTN_PENDING_CONFIRMATION === $rtn_code ) {
			return self::outcome( self::STATUS_PENDING, '綠界回報付款結果仍待確認。' );
		}
		if ( 1 !== $rtn_code ) {
			return self::mark_failure( $order, $data, $source, $claim, $identity );
		}

		$trade_status = EcpgClient::trade_status( $data );
		if ( null !== $trade_status && '1' !== $trade_status ) {
			// 成立但未付款：不是失敗（3D 尚未完成或銀行未回覆），也不得標已付。
			return self::outcome( self::STATUS_PENDING, '綠界回報交易成立但尚未付款。' );
		}

		$trade_no = trim( sanitize_text_field( (string) ( $order_info['TradeNo'] ?? '' ) ) );
		if ( '' === $trade_no ) {
			return self::outcome( self::STATUS_REJECTED, '付款成功結果未帶綠界交易編號。' );
		}

		// 授權證據（gwsr、3D 結果、卡號片段）：退款與客服查詢依賴，走核心共用 CAS。
		$evidence = [
			'ecpay_payment_type' => sanitize_text_field( (string) ( $order_info['PaymentType'] ?? 'Credit' ) ),
			'ecpay_ecpg_source'  => $source,
		];
		foreach ( [ 'Gwsr' => 'gwsr', 'Eci' => 'ecpay_eci', 'Stage' => 'ecpay_stage', 'Stast' => 'ecpay_stast', 'Staed' => 'ecpay_staed', 'AuthCode' => 'ecpay_auth_code' ] as $from => $to ) {
			if ( array_key_exists( $from, $card_info ) && is_scalar( $card_info[ $from ] ) ) {
				$evidence[ $to ] = sanitize_text_field( (string) $card_info[ $from ] );
			}
		}
		$transition = YSPaymentLifecycleService::mark_paid(
			$order_id,
			self::detail_dto( $data, $merchant_trade_no, $trade_no ),
			'webhook_' . $source,
			EcpayPaymentAttempt::callback_guard( $claim ),
			EcpayPaymentAttempt::callback_lifecycle_options(
				$claim,
				EcpayPaymentAttempt::callback_detail_patch( $claim, $evidence ),
				[ 'gateway_trade_no' => $trade_no ]
			)
		);
		$status     = self::STATUS_PAID;
		if ( ! is_array( $transition ) || empty( $transition['success'] ) ) {
			if ( is_array( $transition ) && 'stale' === (string) ( $transition['outcome'] ?? '' ) ) {
				return self::outcome( self::STATUS_REJECTED, '付款結果屬於先前的付款嘗試。' );
			}
			if ( is_array( $transition ) && ! empty( $transition['retryable'] ) ) {
				YSLogger::error( 'ecpay', 'CRITICAL: ECPG 訂單狀態推進失敗', [
					'order_id' => $order_id,
					'source'   => $source,
					'message'  => (string) ( $transition['message'] ?? '' ),
				] );
				return self::outcome( self::STATUS_PERSIST_FAILED, '訂單狀態寫入失敗。', self::VAULT_SKIPPED, true );
			}
			// 只有已付款／履約中的狀態能證明這是同一筆成功結果的重送。
			// cancelled/refunded/failed/timeout 的非 retryable 拒絕不是付款證據；
			// 若綠界此刻回報成功，必須停在待人工收斂而不是 ACK 或建立卡片效果。
			$from = is_array( $transition ) && is_string( $transition['from'] ?? null )
				? (string) $transition['from']
				: (string) ( $order->status ?? '' );
			if ( ! isset( self::PAID_FULFILLMENT_STATES[ $from ] ) ) {
				YSLogger::error( 'ecpay', 'CRITICAL: ECPG 成功結果與訂單終局狀態衝突', [
					'order_id' => $order_id,
					'source'   => $source,
					'from'     => $from,
				] );
				return self::outcome( self::STATUS_PERSIST_FAILED, '付款結果與訂單狀態衝突。', self::VAULT_SKIPPED, true );
			}
			$status = self::STATUS_ALREADY_PAID;
			if ( '' === trim( (string) ( $transition['receipt_id'] ?? '' ) ) ) {
				$transition['receipt_id'] = self::existing_paid_receipt( $order_id );
			}
		}

		return self::outcome( $status, '', self::vault_card( $order, $data, $source, $transition ) );
	}

	/**
	 * RtnCode≠1：推進到 failed（與 AIO 通知同一處置），顧客從核心的重新付款入口再試一次
	 * （新的 attempt → 新的 MerchantTradeNo）。
	 *
	 * @return array{status:string,message:string,vault:string,retryable:bool}
	 */
	public static function mark_failure( object $order, array $data, string $source, ?array $claim = null, ?array $verified_identity = null ): array {
		$order_id   = (int) ( $order->id ?? 0 );
		$order_info = is_array( $data['OrderInfo'] ?? null ) ? $data['OrderInfo'] : [];
		$message    = sanitize_text_field( (string) ( $data['RtnMsg'] ?? '' ) );
		$rtn_code   = EcpgClient::parse_result_code( $data['RtnCode'] ?? null );
		if ( null === $rtn_code || 1 === $rtn_code || EcpgClient::RTN_PENDING_CONFIRMATION === $rtn_code ) {
			return self::outcome( self::STATUS_REJECTED, '失敗代碼格式或狀態無法確認。' );
		}
		if ( null === $claim ) {
			$identity = self::settlement_identity( $verified_identity );
			if ( null === $identity || ! hash_equals( $identity['merchant_id'], (string) ( $data['MerchantID'] ?? '' ) ) ) {
				return self::outcome( self::STATUS_REJECTED, 'MerchantID 與已驗證的付款憑證不符。' );
			}
			$card_info = is_array( $data['CardInfo'] ?? null ) ? $data['CardInfo'] : [];
			$reported  = $order_info['TradeAmt'] ?? $card_info['Amount'] ?? null;
			$amount    = ( is_int( $reported ) || ( is_string( $reported ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $reported ) ) )
				? (int) $reported
				: 0;
			$current = OrderPaymentDetail::read( $order_id );
			if ( null === $current ) {
				return self::outcome( self::STATUS_PERSIST_FAILED, '付款資料讀取失敗。', self::VAULT_SKIPPED, true );
			}
			$claim = EcpayPaymentAttempt::callback_claim(
				$order,
				$current,
				(string) ( $order_info['MerchantTradeNo'] ?? '' ),
				$amount,
				(string) ( $data['MerchantID'] ?? '' ),
				$identity['environment'],
				EcpayPaymentAttempt::ACTION_FAILURE
			);
		}
		if ( null === $claim ) {
			return self::outcome( self::STATUS_REJECTED, '失敗結果不屬於目前付款嘗試。' );
		}
		$transition = YSPaymentLifecycleService::mark_failed(
			$order_id,
			self::detail_dto( $data, (string) ( $order_info['MerchantTradeNo'] ?? '' ), (string) ( $order_info['TradeNo'] ?? '' ) ),
			'webhook_' . $source,
			'failed',
			EcpayPaymentAttempt::callback_guard( $claim ),
			EcpayPaymentAttempt::callback_lifecycle_options(
				$claim,
				EcpayPaymentAttempt::callback_detail_patch( $claim )
			)
		);
		if ( is_array( $transition ) && 'stale' === (string) ( $transition['outcome'] ?? '' ) ) {
			return self::outcome( self::STATUS_REJECTED, '失敗結果屬於先前的付款嘗試。' );
		}
		if ( is_array( $transition ) && empty( $transition['success'] ) && ! empty( $transition['retryable'] ) ) {
			return self::outcome( self::STATUS_PERSIST_FAILED, '失敗狀態寫入失敗。', self::VAULT_SKIPPED, true );
		}
		if ( is_array( $transition ) && empty( $transition['success'] ) ) {
			// A non-retryable lifecycle refusal means this callback did not own a
			// legal pending -> failed transition (for example the order already
			// settled). It is handled, but it must not be advertised to the browser
			// as a fresh provider failure with a new repay path.
			return self::outcome( self::STATUS_REJECTED, '失敗結果與目前訂單狀態不相容。' );
		}
		return self::outcome( self::STATUS_FAILED, '' !== $message ? $message : '綠界回報授權失敗。' );
	}

	/**
	 * A provider-level failure may have no decrypted Data envelope. Rebuild only
	 * the current attempt identity from locally persisted facts, then use the same
	 * guarded lifecycle path as a normal signed failure response.
	 */
	public static function mark_hosted_failure(
		object $order,
		string $merchant_trade_no,
		string $source,
		?int $rtn_code,
		string $message,
		?array $verified_identity = null
	): array {
		$order_id = (int) ( $order->id ?? 0 );
		$current  = OrderPaymentDetail::read( $order_id );
		$identity = self::settlement_identity( $verified_identity );
		$amount = is_array( $current ) ? ( $current['ecpay_charged_amount'] ?? null ) : null;
		if ( null === $identity
			|| ! is_array( $current )
			|| ! ( is_int( $amount ) || ( is_string( $amount ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $amount ) ) ) ) {
			return self::outcome( self::STATUS_PERSIST_FAILED, '付款資料讀取失敗。', self::VAULT_SKIPPED, true );
		}

		$data = [
			'MerchantID' => $identity['merchant_id'],
			'RtnCode'    => null === $rtn_code ? 0 : $rtn_code,
			'RtnMsg'     => sanitize_text_field( $message ),
			'OrderInfo'  => [
				'MerchantTradeNo' => $merchant_trade_no,
				'TradeAmt'         => (int) $amount,
				'TradeNo'          => '',
			],
		];
		return self::mark_failure( $order, $data, $source, null, $identity );
	}

	/** @return array{merchant_id:string,environment:string}|null */
	private static function settlement_identity( ?array $verified_identity ): ?array {
		if ( null === $verified_identity ) {
			$credentials = Settings::payment_credentials();
			$verified_identity = [
				'merchant_id' => (string) ( $credentials['merchant_id'] ?? '' ),
				'environment' => ! empty( $credentials['test_mode'] ) ? 'stage' : 'live',
			];
		}
		$merchant_id = $verified_identity['merchant_id'] ?? null;
		$environment = $verified_identity['environment'] ?? null;
		if ( ! is_string( $merchant_id )
			|| '' === $merchant_id
			|| ! is_string( $environment )
			|| ! in_array( $environment, [ 'stage', 'live' ], true ) ) {
			return null;
		}
		return [ 'merchant_id' => $merchant_id, 'environment' => $environment ];
	}

	/**
	 * 履約狀態的重送不會從 Core transition 取得 receipt；只接受原付款 CAS
	 * 已原子寫下、且仍對應目前 attempt 的 processing receipt，不另造一張。
	 */
	private static function existing_paid_receipt( int $order_id ): string {
		if ( $order_id <= 0
			|| ! class_exists( YSPaymentDetailStore::class )
			|| ! method_exists( YSPaymentDetailStore::class, 'read' )
			|| ! class_exists( YSPaymentEffects::class )
			|| ! method_exists( YSPaymentEffects::class, 'receipt_id' )
			|| ! method_exists( YSPaymentEffects::class, 'receipt' ) ) {
			return '';
		}

		$detail = YSPaymentDetailStore::read( $order_id );
		if ( ! is_array( $detail ) ) {
			return '';
		}
		$receipt = YSPaymentEffects::receipt_id( $order_id, 'processing', $detail );
		$row     = '' !== $receipt ? YSPaymentEffects::receipt( $detail, $receipt ) : [];

		return is_array( $row ) && 'processing' === (string) ( $row['target'] ?? '' )
			? $receipt
			: '';
	}

	/** A follow-up payment may vault a card for the customer without replacing its subscription mandate. */
	private static function is_subscription_follow_up_order( object $order ): bool {
		$detail = self::detail_of( $order );
		foreach ( [ 'source', 'type' ] as $key ) {
			$value = $detail[ $key ] ?? null;
			if ( is_string( $value )
				&& in_array( strtolower( trim( $value ) ), [ 'subscription_renewal', 'subscription_custom' ], true ) ) {
				return true;
			}
		}

		return 'token_charge' === strtolower( trim( (string) ( $detail['ecpay_ecpg_flow'] ?? '' ) ) );
	}

	/**
	 * 把 BindCardID 存進核心卡片庫（YSCrypto 加密、擁有者綁定、預設卡）。
	 *
	 * 🔴 BindCardID 絕不寫進 payment_detail 或 log；它是日後扣款的唯一憑證。
	 */
	private static function vault_card( object $order, array $data, string $source, array $transition ): string {
		$bind_card_id = trim( (string) ( $data['BindCardID'] ?? '' ) );
		$customer_id  = (int) ( $order->customer_id ?? 0 );
		if ( '' === $bind_card_id || $customer_id <= 0 ) {
			return self::VAULT_SKIPPED;
		}
		if ( ! class_exists( YSCreditCard::class )
			|| ! method_exists( YSCreditCard::class, 'create_or_get' )
			|| ! class_exists( YSSubscription::class )
			|| ! method_exists( YSSubscription::class, 'bind_initial_order_card' )
			|| ! class_exists( YSPaymentEffects::class )
			|| ! method_exists( YSPaymentEffects::class, 'enroll' )
			|| ! method_exists( YSPaymentEffects::class, 'run' )
			|| ! method_exists( YSPaymentEffects::class, 'receipt' ) ) {
			return self::VAULT_FAILED;
		}

		$order_id  = (int) ( $order->id ?? 0 );
		$receipt   = is_string( $transition['receipt_id'] ?? null ) ? trim( (string) $transition['receipt_id'] ) : '';
		$effect    = 'ecpg_card_vault';
		$follow_up = self::is_subscription_follow_up_order( $order );
		if ( '' === $receipt || ! YSPaymentEffects::enroll( $order_id, $receipt, $effect ) ) {
			YSLogger::error( 'ecpay', 'CRITICAL: ECPG 綁卡副作用無法補登付款收據，要求重送', [
				'order_id' => $order_id,
				'source'   => $source,
			] );
			return self::VAULT_FAILED;
		}

		YSPaymentEffects::run( $order_id, $receipt, [
			$effect => static function ( string $_key ) use ( $order, $data, $source, $bind_card_id, $customer_id, $order_id, $follow_up ): bool {
				$card_info = is_array( $data['CardInfo'] ?? null ) ? $data['CardInfo'] : [];
				$card_id   = YSCreditCard::create_or_get( [
					'customer_id' => $customer_id,
					'user_id'     => (int) ( $order->user_id ?? 0 ),
					'gateway_id'  => EcpgOrderContext::GATEWAY_ID,
					'token'       => $bind_card_id,
					'card_last4'  => preg_replace( '/\D/', '', (string) ( $card_info['Card4No'] ?? '' ) ) ?? '',
					'card_brand'  => EcpgOrderContext::card_brand( (string) ( $card_info['Card6No'] ?? '' ) ),
					'expire_date' => EcpgOrderContext::expire_date( $card_info ),
					'is_default'  => true,
				] );
				if ( false === $card_id ) {
					YSLogger::error( 'ecpay', 'CRITICAL: ECPG 綁卡 BindCardID 無法完整落盤', [
						'order_id'    => $order_id,
						'customer_id' => $customer_id,
						'source'      => $source,
					] );
					return false;
				}

				if ( ! $follow_up && ! YSSubscription::bind_initial_order_card(
					$order_id,
					$customer_id,
					(int) ( $order->user_id ?? 0 ),
					EcpgOrderContext::GATEWAY_ID,
					(int) $card_id
				) ) {
					YSLogger::error( 'ecpay', 'ECPG 初始訂閱卡片綁定尚未完成，要求重送', [
						'order_id'    => $order_id,
						'customer_id' => $customer_id,
						'card_id'     => (int) $card_id,
						'source'      => $source,
					] );
					return false;
				}

				YSLogger::info( 'ecpay', $follow_up ? 'ECPG 綁卡已存入卡片庫' : 'ECPG 綁卡已存入卡片庫並綁定初始訂閱', [
					'order_id'    => $order_id,
					'customer_id' => $customer_id,
					'card_id'     => (int) $card_id,
					'source'      => $source,
				] );
				return true;
			},
		] );

		$detail = YSPaymentDetailStore::read( $order_id );
		$receipt_row = is_array( $detail ) ? YSPaymentEffects::receipt( $detail, $receipt ) : [];
		$effects = is_array( $receipt_row['effects'] ?? null ) ? $receipt_row['effects'] : [];
		$row = is_array( $effects[ $effect ] ?? null ) ? $effects[ $effect ] : null;
		if ( null === $row || YSPaymentEffects::STATE_DONE !== (string) ( $row['state'] ?? '' ) ) {
			YSLogger::error( 'ecpay', 'CRITICAL: ECPG 綁卡付款收據尚未完成，要求重送', [
				'order_id' => $order_id,
				'source'   => $source,
			] );
			return self::VAULT_FAILED;
		}

		return self::VAULT_DONE;
	}

	/** 成功／失敗共用的 DTO；金額用綠界回報的 TradeAmt（核心金額守衛會再核一次）。 */
	private static function detail_dto( array $data, string $merchant_trade_no, string $trade_no ): YSPaymentDetailDTO {
		$order_info = is_array( $data['OrderInfo'] ?? null ) ? $data['OrderInfo'] : [];
		$card_info  = is_array( $data['CardInfo'] ?? null ) ? $data['CardInfo'] : [];
		$amount     = $order_info['TradeAmt'] ?? $card_info['Amount'] ?? null;
		return YSPaymentDetailDTO::from_legacy_array( [
			'payment_type'     => 'credit_card',
			'trade_status'     => sanitize_text_field( (string) ( $order_info['TradeStatus'] ?? ( $data['RtnCode'] ?? '' ) ) ),
			'trade_no'         => sanitize_text_field( $trade_no ),
			'gateway_trade_no' => sanitize_text_field( $trade_no ),
			'mer_trade_no'     => sanitize_text_field( $merchant_trade_no ),
			'response_code'    => sanitize_text_field( (string) ( $data['RtnCode'] ?? '' ) ),
			'response_message' => sanitize_text_field( (string) ( $data['RtnMsg'] ?? '' ) ),
			'card_4no'         => sanitize_text_field( (string) ( $card_info['Card4No'] ?? '' ) ),
			'card_6no'         => sanitize_text_field( (string) ( $card_info['Card6No'] ?? '' ) ),
			'auth_code'        => sanitize_text_field( (string) ( $card_info['AuthCode'] ?? '' ) ),
			'paid_amount'      => is_numeric( $amount ) ? (float) $amount : 0.0,
		], EcpgOrderContext::GATEWAY_ID );
	}

	/** @return array{status:string,message:string,vault:string,retryable:bool} */
	private static function outcome( string $status, string $message, string $vault = self::VAULT_SKIPPED, bool $retryable = false ): array {
		return [ 'status' => $status, 'message' => $message, 'vault' => $vault, 'retryable' => $retryable ];
	}
}
