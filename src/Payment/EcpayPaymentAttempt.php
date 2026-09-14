<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Payment;

defined( 'ABSPATH' ) || exit;

use YangSheep\Ecommerce\Services\Payment\YSPaymentAttempt;
use YangSheep\Ecommerce\Services\Payment\YSPaymentDispatch;
use YangSheep\Ecommerce\Services\Payment\YSPaymentDetailStore;
use YangSheep\Ecommerce\Models\YSOrder;
use YangSheep\YSCartEcpay\Support\DetailWriteOutcome;
use YangSheep\YSCartEcpay\Support\OrderPaymentDetail;
use YangSheep\YSCartEcpay\Support\Settings;

/**
 * ECPay's current-attempt ownership and provider-specific attempt facts.
 *
 * Order lookup is discovery only. Every writer must carry one of the claims
 * built here into the Core lifecycle CAS, where it is checked again against
 * the fresh payment_detail row.
 */
final class EcpayPaymentAttempt {
	public const ACTION_SUCCESS      = 'success';
	public const ACTION_FAILURE      = 'failure';
	public const ACTION_PAYMENT_INFO = 'payment_info';

	public const LATE_SETTLEMENT_KEY = '_ys_ecpay_late_settlements';
	public const LATE_STATE_OPEN = 'open';
	public const LATE_STATE_PROVIDER_TERMINAL = 'provider_terminal';
	public const LATE_STATE_PAID_MANUAL = 'paid_manual';
	public const LATE_CALLBACK_NOT_LATE = 'not_late';
	public const LATE_CALLBACK_REJECTED = 'rejected';
	public const LATE_CALLBACK_PERSISTED = 'persisted';
	public const LATE_CALLBACK_ANOMALY_PERSISTED = 'anomaly_persisted';
	public const LATE_CALLBACK_RETRY = 'retry';

	private const LATE_SCHEMA = 1;
	private const LATE_ENTRY_SCHEMA = 1;
	private const LATE_MAX_ENTRIES = 4;
	private const LATE_MAX_ANOMALIES = 4;
	private const OFFLINE_GATEWAYS = [
		'ys_ec_ecpay_atm' => 'atm',
		'ys_ec_ecpay_cvs' => 'cvs',
	];
	private const EXTERNAL_DELAYED_GATEWAYS = [
		'ys_ec_payuni_atm'   => true,
		'ys_ec_payuni_cvs'   => true,
		'ys_ec_shopline_atm' => true,
	];
	private const KNOWN_NON_DELAYED_GATEWAYS = [
		'ys_ec_bank_transfer'            => true,
		'ys_ec_cod'                      => true,
		'ys_ec_pickup_onsite'            => true,
		'ys_ec_test_pay'                 => true,
		'ys_ec_payuni_credit'            => true,
		'ys_ec_payuni_installment'       => true,
		'ys_ec_payuni_installment_embed' => true,
		'ys_ec_payuni_applepay'          => true,
		'ys_ec_payuni_linepay'           => true,
		'ys_ec_payuni_jkopay'            => true,
		'ys_ec_payuni_aftee'             => true,
		'ys_ec_shopline_credit'          => true,
		'ys_ec_shopline_installment'     => true,
		'ys_ec_shopline_applepay'        => true,
		'ys_ec_shopline_linepay'         => true,
		'ys_ec_shopline_jkopay'          => true,
		'ys_ec_shopline_bnpl'            => true,
	];

	/** Safe reconciliation facts which move to Core's bounded attempt history. */
	private const HISTORY_KEYS = [
		'mer_trade_no',
		'ecpay_merchant_trade_no',
		'ecpay_operation_key',
		'ecpay_charged_amount',
		'ecpay_environment',
		'ecpay_merchant_id',
		'payment_provider',
		'payment_method',
		'gwsr',
		'ecpay_payment_type',
		'ecpay_stage',
		'ecpay_stast',
		'ecpay_staed',
		'ecpay_red_dan',
		'ecpay_red_de_amt',
		'ecpay_red_ok_amt',
		'ecpay_red_yet',
		'ecpay_eci',
		'ecpay_installment_fallback',
		'ecpay_ecpg_flow',
		'ecpay_ecpg_member_id',
		'ecpay_ecpg_source',
		'ecpay_auth_code',
	];

	/** Detail fields a gateway may add while binding a new provider identity. */
	private const BIND_EXTRA_KEYS = [
		'ecpay_ecpg_flow'      => true,
		'ecpay_ecpg_member_id' => true,
	];

	/** @param mixed $keys @param mixed $previous_gateway_id */
	public static function contribute_history_keys( $keys, $previous_gateway_id = '' ): array {
		$base = is_array( $keys ) && array_is_list( $keys ) ? $keys : [];
		if ( ! is_string( $previous_gateway_id ) || ! self::canonical_gateway( $previous_gateway_id ) ) {
			return $base;
		}
		$out  = [];
		foreach ( array_merge( $base, self::HISTORY_KEYS ) as $key ) {
			if ( is_string( $key ) && 1 === preg_match( '/^[a-z][a-z0-9_]{0,63}$/D', $key ) ) {
				$out[ $key ] = true;
			}
		}
		return array_keys( $out );
	}

	/**
	 * Project the one safe successor exception for an exact issued ATM/CVS code.
	 *
	 * @param array<string,mixed> $detail
	 * @return array{recognized:bool,actionable:bool,reason:string,message:string,warning:string,candidate:?array}
	 */
	public static function repay_gate( object $order, array $detail, string $successor_gateway_id ): array {
		$base = [
			'recognized' => false,
			'actionable' => false,
			'reason'     => '',
			'message'    => '',
			'warning'    => '',
			'candidate'  => null,
		];
		$ledger = self::late_ledger( $detail );
		if ( null === $ledger ) {
			return array_replace( $base, [
				'recognized' => array_key_exists( self::LATE_SETTLEMENT_KEY, $detail ),
				'reason'     => 'ecpay_predecessor_unreadable',
				'message'    => '先前綠界付款紀錄無法安全確認，請聯繫客服。',
			] );
		}

		$order_id = (int) ( $order->id ?? 0 );
		$has_open = false;
		foreach ( $ledger['entries'] as $entry_key => $entry ) {
			if ( ! is_string( $entry_key ) || ! is_array( $entry )
				|| ! self::valid_late_entry_for_order( $entry_key, $entry, $order_id ) ) {
				return array_replace( $base, [
					'recognized' => true,
					'reason'     => 'ecpay_predecessor_unreadable',
					'message'    => '先前綠界付款紀錄無法安全確認，請聯繫客服。',
				] );
			}
			if ( self::LATE_STATE_PAID_MANUAL === $entry['state']
				|| self::late_entry_has_success_anomaly( $entry ) ) {
				return array_replace( $base, [
					'recognized' => true,
					'reason'     => 'ecpay_predecessor_unresolved',
					'message'    => '先前綠界付款有成功結果需要確認，請先由客服完成人工對帳。',
				] );
			}
			$has_open = $has_open || self::LATE_STATE_OPEN === $entry['state'];
		}
		if ( $has_open ) {
			$base['warning'] = '先前取得的繳費帳號或代碼可能仍可使用，請擇一付款，避免重複繳款。';
		}
		$gateway = is_string( $order->gateway_id ?? null ) ? (string) $order->gateway_id : '';
		$method  = is_string( $order->payment_method ?? null ) ? (string) $order->payment_method : '';
		$successor_delayed = '' === $successor_gateway_id
			? false
			: self::successor_may_issue_delayed_instrument( $successor_gateway_id );
		if ( $has_open && false !== $successor_delayed ) {
			return array_replace( $base, [
				'recognized' => true,
				'reason'     => 'ecpay_predecessor_unresolved',
				'message'    => '先前取得的繳費帳號或代碼可能仍可使用，請擇一付款，避免重複繳款。',
			] );
		}
		if ( ! isset( self::OFFLINE_GATEWAYS[ $gateway ] )
			|| ! hash_equals( $gateway, $method )
			|| 'offline_payment' !== (string) ( $order->status ?? '' ) ) {
			return $base;
		}
		$base['recognized'] = true;
		// An OPEN receipt consumes the one predecessor slot only when the current
		// order is itself another issued ECPay offline instrument. A non-offline
		// successor remains governed by Core's ordinary dispatch/status gates, so
		// a terminal successor cannot leave the order permanently unpayable.
		// PAID_MANUAL and malformed ledgers remain global blockers above.
		if ( $has_open ) {
			return array_replace( $base, [
				'reason'  => 'ecpay_predecessor_unresolved',
				'message' => '先前取得的繳費帳號或代碼可能仍可使用，請擇一付款，避免重複繳款。',
			] );
		}
		if ( count( $ledger['entries'] ) >= self::LATE_MAX_ENTRIES ) {
			return array_replace( $base, [
				'reason'  => 'ecpay_predecessor_capacity_reached',
				'message' => '此訂單已有多筆綠界歷史付款紀錄，請由客服人工處理。',
			] );
		}

		$total = self::legacy_order_amount( $order );
		$currency = is_string( $order->currency ?? null ) ? (string) $order->currency : '';
		$mtn = (string) ( $detail['mer_trade_no'] ?? '' );
		$attempt = YSPaymentAttempt::current( $detail );
		$record  = YSPaymentDispatch::current( $detail );
		$operation = (string) ( $detail['ecpay_operation_key'] ?? '' );
		$handoff = is_array( $record['payable_handoff'] ?? null ) ? $record['payable_handoff'] : [];
		$pay_no = self::bounded_text( $detail['pay_no'] ?? null, 100 );
		$expires = self::bounded_text( $detail['expire_date'] ?? null, 100 );
		$bank = self::bounded_text( $detail['bank_type'] ?? null, 30 );
		$family = self::offline_payment_family( $detail['payment_type'] ?? null );
		$credentials = Settings::payment_credentials();
		$merchant_id = (string) ( $credentials['merchant_id'] ?? '' );
		$environment = ! empty( $credentials['test_mode'] ) ? 'stage' : 'live';

		if ( $order_id <= 0 || 'TWD' !== $currency || $total <= 0 || '' === $merchant_id
			|| ! self::required_positive_amount( $detail, 'ecpay_charged_amount', $total )
			|| ! self::canonical_merchant_trade_no( $mtn )
			|| ! self::root_aliases_match( $detail, $mtn, true )
			|| ! self::required_exact( $detail, 'payment_provider', 'ecpay' )
			|| ! self::required_exact( $detail, 'payment_method', $gateway )
			|| ! self::required_exact( $detail, 'ecpay_merchant_id', $merchant_id )
			|| ! self::required_exact( $detail, 'ecpay_environment', $environment )
			|| null === $pay_no || null === $expires
			|| ( 'atm' === self::OFFLINE_GATEWAYS[ $gateway ] && null === $bank )
			|| self::OFFLINE_GATEWAYS[ $gateway ] !== $family
			|| ! in_array( (string) ( $detail['trade_status'] ?? '' ), [ '2', '10100073' ], true )
			|| ! is_string( $attempt['id'] ?? null ) || ! hash_equals( $mtn, $attempt['id'] )
			|| ! self::modern_dispatch_matches(
				$detail,
				$order_id,
				$gateway,
				$operation,
				(string) ( $record['token'] ?? '' ),
				[ YSPaymentDispatch::STATE_SUBMITTED ]
			)
			|| ! is_string( $handoff['operation_key'] ?? null )
			|| ! hash_equals( $operation, $handoff['operation_key'] )
			|| 'provider' !== ( $handoff['kind'] ?? null )
			|| ! is_string( $handoff['nonce'] ?? null )
			|| 1 !== preg_match( '/^[a-f0-9]{32}$/D', $handoff['nonce'] )
			|| ! is_int( $handoff['claimed_at'] ?? null ) || $handoff['claimed_at'] <= 0 ) {
			return array_replace( $base, [
				'reason'  => 'ecpay_predecessor_custody_unavailable',
				'message' => '綠界付款嘗試保管資料無法安全確認，請聯繫客服。',
			] );
		}

		$entry_key = hash( 'sha256', 'ecpay|' . $order_id . '|' . $mtn );
		if ( isset( $ledger['entries'][ $entry_key ] ) ) {
			return array_replace( $base, [
				'reason'  => 'ecpay_predecessor_unresolved',
				'message' => '此綠界付款嘗試已被保存，請由客服確認後續狀態。',
			] );
		}

		return [
			'recognized' => true,
			'actionable' => true,
			'reason'     => '',
			'message'    => '',
			'warning'    => '先前取得的繳費帳號或代碼可能仍可使用，請擇一付款，避免重複繳款。',
			'candidate'  => [
				'entry_key'                  => $entry_key,
				'order_id'                   => $order_id,
				'gateway_id'                 => $gateway,
				'payment_family'             => $family,
				'mer_trade_no'               => $mtn,
				'pay_no_digest'              => hash( 'sha256', $pay_no ),
				'expire_digest'              => hash( 'sha256', $expires ),
				'bank_digest'                => null === $bank ? '' : hash( 'sha256', $bank ),
				'amount'                     => $total,
				'currency'                   => $currency,
				'merchant_digest'            => hash( 'sha256', $merchant_id ),
				'environment'                => $environment,
				'predecessor_generation'     => $attempt['generation'],
				'predecessor_nonce_digest'   => hash( 'sha256', (string) $attempt['nonce'] ),
				'predecessor_dispatch_digest'=> hash( 'sha256', $operation ),
				'successor_gateway_id'        => $successor_gateway_id,
			],
		];
	}

	/**
	 * Add the predecessor receipt to the same Core detail that rotates attempts.
	 *
	 * @param array<string,mixed> $detail
	 * @param array<string,mixed> $candidate
	 * @param array<string,mixed> $successor_attempt
	 * @param array<string,mixed> $successor_dispatch
	 * @return array<string,mixed>|null
	 */
	public static function append_for_rotation(
		array $detail,
		array $candidate,
		array $successor_attempt,
		array $successor_dispatch,
		string $successor_gateway
	): ?array {
		$ledger = self::late_ledger( $detail );
		$entry_key = $candidate['entry_key'] ?? null;
		$generation = $successor_attempt['generation'] ?? null;
		$nonce = $successor_attempt['nonce'] ?? null;
		$operation = $successor_dispatch['operation_key'] ?? null;
		$history = self::history_entries( $detail );
		$archived = [] === $history ? null : end( $history );
		$archived_attempt = is_array( $archived['attempt'] ?? null ) ? $archived['attempt'] : [];
		$archived_fields = is_array( $archived['fields'] ?? null ) ? $archived['fields'] : [];
		if ( null === $ledger || count( $ledger['entries'] ) >= self::LATE_MAX_ENTRIES
			|| ! is_string( $entry_key ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $entry_key )
			|| isset( $ledger['entries'][ $entry_key ] )
			|| ! is_int( $generation ) || $generation <= 0
			|| ! is_string( $nonce ) || 1 !== preg_match( '/^[a-f0-9]{32}$/D', $nonce )
			|| ! is_string( $operation ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $operation )
			|| 1 !== preg_match( '/^ys_ec_[a-z0-9_]{1,90}$/D', $successor_gateway )
			|| ! is_string( $candidate['successor_gateway_id'] ?? null )
			|| ! hash_equals( $successor_gateway, $candidate['successor_gateway_id'] )
			|| ! hash_equals(
				YSPaymentDispatch::operation_key( (int) ( $candidate['order_id'] ?? 0 ), $successor_attempt ),
				$operation
			)
			|| $generation !== ( $successor_dispatch['attempt_generation'] ?? null )
			|| $nonce !== ( $successor_dispatch['attempt_nonce'] ?? null )
			|| $successor_gateway !== ( $successor_dispatch['gateway_id'] ?? null )
			|| 'ecpay' !== ( $archived_attempt['provider'] ?? null )
			|| ( $candidate['gateway_id'] ?? null ) !== ( $archived_attempt['gateway_id'] ?? null )
			|| ( $candidate['predecessor_generation'] ?? null ) !== ( $archived_attempt['generation'] ?? null )
			|| ! is_string( $archived_attempt['nonce'] ?? null )
			|| ! hash_equals( (string) ( $candidate['predecessor_nonce_digest'] ?? '' ), hash( 'sha256', $archived_attempt['nonce'] ) )
			|| ! self::root_aliases_match( $archived_fields, (string) ( $candidate['mer_trade_no'] ?? '' ), true )
			|| ! self::required_positive_amount( $archived_fields, 'ecpay_charged_amount', (int) ( $candidate['amount'] ?? 0 ) )
			|| ! self::required_exact( $archived_fields, 'payment_provider', 'ecpay' )
			|| ! self::required_exact( $archived_fields, 'payment_method', (string) ( $candidate['gateway_id'] ?? '' ) )
			|| ! self::required_exact( $archived_fields, 'ecpay_environment', (string) ( $candidate['environment'] ?? '' ) )
			|| ! is_string( $archived_fields['ecpay_merchant_id'] ?? null )
			|| ! hash_equals(
				(string) ( $candidate['merchant_digest'] ?? '' ),
				hash( 'sha256', $archived_fields['ecpay_merchant_id'] )
			)
			|| ! is_string( $archived_fields['ecpay_operation_key'] ?? null )
			|| ! hash_equals(
				(string) ( $candidate['predecessor_dispatch_digest'] ?? '' ),
				hash( 'sha256', $archived_fields['ecpay_operation_key'] )
			) ) {
			return null;
		}

		$entry = [
			'schema'                       => self::LATE_ENTRY_SCHEMA,
			'order_id'                     => (int) ( $candidate['order_id'] ?? 0 ),
			'provider'                     => 'ecpay',
			'gateway_id'                   => $candidate['gateway_id'] ?? '',
			'payment_family'               => $candidate['payment_family'] ?? '',
			'mer_trade_no'                 => $candidate['mer_trade_no'] ?? '',
			'pay_no_digest'                => $candidate['pay_no_digest'] ?? '',
			'expire_digest'                => $candidate['expire_digest'] ?? '',
			'bank_digest'                  => $candidate['bank_digest'] ?? '',
			'amount'                       => $candidate['amount'] ?? 0,
			'currency'                     => $candidate['currency'] ?? '',
			'merchant_digest'              => $candidate['merchant_digest'] ?? '',
			'environment'                  => $candidate['environment'] ?? '',
			'predecessor_generation'       => $candidate['predecessor_generation'] ?? null,
			'predecessor_nonce_digest'     => $candidate['predecessor_nonce_digest'] ?? '',
			'predecessor_dispatch_digest'  => $candidate['predecessor_dispatch_digest'] ?? '',
			'successor_generation'         => $generation,
			'successor_nonce_digest'       => hash( 'sha256', $nonce ),
			'successor_dispatch_digest'    => hash( 'sha256', $operation ),
			'successor_gateway_id'         => $successor_gateway,
			'archived_at'                  => current_time( 'mysql' ),
			'state'                        => self::LATE_STATE_OPEN,
			'last_action'                  => '',
			'last_rtn_code'                => '',
			'last_trade_no'                => '',
			'last_trade_no_digest'         => '',
			'last_callback_evidence_digest'=> '',
			'last_callback_at'             => null,
			'anomalies'                    => [],
		];
		$entry['claim_digest'] = self::late_claim_digest( $entry );
		if ( ! self::valid_late_entry_for_order( $entry_key, $entry, $entry['order_id'] ) ) {
			return null;
		}
		$ledger['entries'][ $entry_key ] = $entry;
		$detail[ self::LATE_SETTLEMENT_KEY ] = $ledger;
		return $detail;
	}

	/**
	 * Persist a signed callback against a retired receipt only.
	 *
	 * @param array<string,string> $params
	 */
	public static function record_historical_callback(
		object $order,
		array $params,
		string $merchant_id,
		string $environment,
		string $action
	): string {
		$order_id = (int) ( $order->id ?? 0 );
		$mtn = (string) ( $params['MerchantTradeNo'] ?? '' );
		$amount_raw = (string) ( $params['TradeAmt'] ?? '' );
		$amount = 1 === preg_match( '/^[1-9][0-9]*$/D', $amount_raw ) ? (int) $amount_raw : 0;
		// Do not let the late interceptor swallow a malformed *current* callback.
		// Until a canonical MTN can name an exact durable receipt, the ordinary
		// current-attempt controller remains the authority for validation/response.
		if ( $order_id <= 0 || ! self::canonical_merchant_trade_no( $mtn )
			|| '' === $merchant_id || ! in_array( $environment, [ 'stage', 'live' ], true )
			|| ! in_array( $action, [ self::ACTION_SUCCESS, self::ACTION_FAILURE, self::ACTION_PAYMENT_INFO ], true ) ) {
			return self::LATE_CALLBACK_NOT_LATE;
		}

		$outcome = OrderPaymentDetail::mutate(
			$order_id,
			static function ( array $detail, int $round, &$decision ) use ( $order_id, $params, $merchant_id, $environment, $action, $mtn, $amount ): ?array {
				unset( $round );
				if ( ! array_key_exists( self::LATE_SETTLEMENT_KEY, $detail ) ) {
					$decision = self::LATE_CALLBACK_NOT_LATE;
					return null;
				}
				$ledger = self::late_ledger( $detail );
				$key = hash( 'sha256', 'ecpay|' . $order_id . '|' . $mtn );
				$entry = null === $ledger ? null : ( $ledger['entries'][ $key ] ?? null );
				if ( null === $ledger ) {
					$decision = self::LATE_CALLBACK_RETRY;
					return null;
				}
				if ( ! is_array( $entry ) ) {
					$decision = self::LATE_CALLBACK_NOT_LATE;
					return null;
				}
				if ( ! self::valid_late_entry_for_order( $key, $entry, $order_id ) ) {
					$decision = self::LATE_CALLBACK_RETRY;
					return null;
				}
				$evidence = self::late_callback_digest( $params, $action );
				$trade_no = self::provider_trade_no( $params['TradeNo'] ?? null );
				$trade_digest = null === $trade_no ? '' : hash( 'sha256', $trade_no );
				if ( $amount <= 0
					|| ! self::late_callback_matches( $entry, $params, $merchant_id, $environment, $action, $amount ) ) {
					$reason = self::ACTION_SUCCESS === $action && null === $trade_no
						? 'trade_no_missing'
						: 'receipt_mismatch';
					$entry = self::append_late_anomaly(
						$entry,
						$params,
						$merchant_id,
						$environment,
						$action,
						$amount,
						$reason,
						$evidence
					);
					if ( null === $entry ) {
						$decision = self::LATE_CALLBACK_RETRY;
						return null;
					}
					$ledger['entries'][ $key ] = $entry;
					$detail[ self::LATE_SETTLEMENT_KEY ] = $ledger;
					$decision = self::LATE_CALLBACK_ANOMALY_PERSISTED;
					return $detail;
				}

				if ( self::LATE_STATE_PAID_MANUAL === $entry['state'] ) {
					$same_paid_callback = self::ACTION_SUCCESS === $action
						&& is_string( $trade_no )
						&& hash_equals( $trade_no, (string) $entry['last_trade_no'] )
						&& hash_equals( $trade_digest, (string) $entry['last_trade_no_digest'] )
						&& hash_equals( $evidence, (string) $entry['last_callback_evidence_digest'] );
					if ( $same_paid_callback ) {
						$decision = self::LATE_CALLBACK_PERSISTED;
						return $detail;
					}
					$entry = self::append_late_anomaly(
						$entry,
						$params,
						$merchant_id,
						$environment,
						$action,
						$amount,
						'divergent_after_paid',
						$evidence
					);
					if ( null === $entry ) {
						$decision = self::LATE_CALLBACK_RETRY;
						return null;
					}
					$ledger['entries'][ $key ] = $entry;
					$detail[ self::LATE_SETTLEMENT_KEY ] = $ledger;
					$decision = self::LATE_CALLBACK_ANOMALY_PERSISTED;
					return $detail;
				}
				if ( self::LATE_STATE_PROVIDER_TERMINAL === $entry['state'] && self::ACTION_SUCCESS !== $action ) {
					if ( $action === $entry['last_action']
						&& hash_equals( $evidence, (string) $entry['last_callback_evidence_digest'] ) ) {
						$decision = self::LATE_CALLBACK_PERSISTED;
						return $detail;
					}
					$entry = self::append_late_anomaly(
						$entry,
						$params,
						$merchant_id,
						$environment,
						$action,
						$amount,
						'divergent_after_terminal',
						$evidence
					);
					if ( null === $entry ) {
						$decision = self::LATE_CALLBACK_RETRY;
						return null;
					}
					$ledger['entries'][ $key ] = $entry;
					$detail[ self::LATE_SETTLEMENT_KEY ] = $ledger;
					$decision = self::LATE_CALLBACK_ANOMALY_PERSISTED;
					return $detail;
				}

				$next_state = $entry['state'];
				if ( self::ACTION_SUCCESS === $action ) {
					$next_state = self::LATE_STATE_PAID_MANUAL;
				} elseif ( self::ACTION_FAILURE === $action ) {
					$next_state = self::LATE_STATE_PROVIDER_TERMINAL;
				}
				if ( $next_state === $entry['state']
					&& $action === $entry['last_action']
					&& hash_equals( $evidence, (string) $entry['last_callback_evidence_digest'] ) ) {
					$decision = self::LATE_CALLBACK_PERSISTED;
					return $detail;
				}

				$entry['state'] = $next_state;
				$entry['last_action'] = $action;
				$entry['last_rtn_code'] = (string) ( $params['RtnCode'] ?? '' );
				$entry['last_trade_no'] = null === $trade_no ? '' : $trade_no;
				$entry['last_trade_no_digest'] = $trade_digest;
				$entry['last_callback_evidence_digest'] = $evidence;
				$entry['last_callback_at'] = current_time( 'mysql' );
				if ( ! self::valid_late_entry_for_order( $key, $entry, $order_id ) ) {
					$decision = self::LATE_CALLBACK_RETRY;
					return null;
				}
				$ledger['entries'][ $key ] = $entry;
				$detail[ self::LATE_SETTLEMENT_KEY ] = $ledger;
				$decision = self::LATE_CALLBACK_PERSISTED;
				return $detail;
			},
			null,
			false
		);

		if ( $outcome->is_persisted() ) {
			$decision = (string) $outcome->get_decision();
			if ( in_array( $decision, [ self::LATE_CALLBACK_PERSISTED, self::LATE_CALLBACK_ANOMALY_PERSISTED ], true ) ) {
				return $decision;
			}
		}
		if ( $outcome->is_aborted() ) {
			$decision = (string) $outcome->get_decision();
			if ( in_array( $decision, [ self::LATE_CALLBACK_NOT_LATE, self::LATE_CALLBACK_REJECTED ], true ) ) {
				return $decision;
			}
		}
		return self::LATE_CALLBACK_RETRY;
	}

	/**
	 * Bind merchant identity, attempt id, captured facts, and order gateway in
	 * the same Core CAS before a payable form or provider call can be exposed.
	 *
	 * @param array<string,mixed> $extra_detail
	 */
	public static function bind_payment_identity(
		int $order_id,
		string $gateway_id,
		string $merchant_trade_no,
		int $charged_amount,
		string $environment,
		string $merchant_id,
		array $extra_detail = []
	): DetailWriteOutcome {
		if ( ! self::core_available() ) {
			return DetailWriteOutcome::core_unavailable();
		}

		$operation_key = (string) YSPaymentDispatch::current_operation_key();
		$dispatch_token = (string) ( YSPaymentDispatch::current_token() ?? '' );
		$extra = [];
		foreach ( $extra_detail as $key => $value ) {
			if ( is_string( $key ) && isset( self::BIND_EXTRA_KEYS[ $key ] ) && is_scalar( $value ) ) {
				$extra[ $key ] = $value;
			}
		}

		return OrderPaymentDetail::mutate(
			$order_id,
			static function ( array $detail, int $cas_attempt, &$decision, ?object $fresh_row = null ) use (
				$order_id,
				$gateway_id,
				$merchant_trade_no,
				$charged_amount,
				$environment,
				$merchant_id,
				$operation_key,
				$dispatch_token,
				$extra
			): ?array {
				unset( $cas_attempt );
				if ( $order_id <= 0
					|| ! self::fresh_gateway_row_can_bind( $fresh_row, $gateway_id )
					|| ! self::fresh_order_amount_matches( $fresh_row, $charged_amount )
					|| ! self::canonical_gateway( $gateway_id )
					|| ! self::canonical_merchant_trade_no( $merchant_trade_no )
					|| $charged_amount <= 0
					|| ! in_array( $environment, [ 'stage', 'live' ], true )
					|| '' === $merchant_id
					|| '' === $operation_key
					|| '' === $dispatch_token
					|| ! self::modern_dispatch_matches(
						$detail,
						$order_id,
						$gateway_id,
						$operation_key,
						$dispatch_token,
						[ YSPaymentDispatch::STATE_RESERVED, YSPaymentDispatch::STATE_SUBMITTED, YSPaymentDispatch::STATE_INDETERMINATE ]
					) ) {
					$decision = YSPaymentDetailStore::STALE;
					return null;
				}

				$attempt = YSPaymentAttempt::current( $detail );
				$attempt_id = $attempt['id'] ?? null;
				if ( ! is_string( $attempt_id )
					|| ( '' !== $attempt_id && ! hash_equals( $attempt_id, $merchant_trade_no ) )
					|| ! self::compatible_root_value( $detail, 'mer_trade_no', $merchant_trade_no )
					|| ! self::compatible_root_value( $detail, 'ecpay_merchant_trade_no', $merchant_trade_no )
					|| ! self::compatible_root_value( $detail, 'ecpay_operation_key', $operation_key )
					|| ! self::compatible_root_value( $detail, 'payment_provider', 'ecpay' )
					|| ! self::compatible_root_value( $detail, 'payment_method', $gateway_id )
					|| ! self::compatible_positive_amount( $detail, 'ecpay_charged_amount', $charged_amount )
					|| ! self::compatible_root_value( $detail, 'ecpay_environment', $environment )
					|| ! self::compatible_root_value( $detail, 'ecpay_merchant_id', $merchant_id ) ) {
					$decision = YSPaymentDetailStore::STALE;
					return null;
				}

				$attempt['id']                         = $merchant_trade_no;
				$detail[ YSPaymentAttempt::KEY ]       = $attempt;
				$detail['mer_trade_no']                = $merchant_trade_no;
				$detail['ecpay_merchant_trade_no']     = $merchant_trade_no;
				$detail['ecpay_operation_key']         = $operation_key;
				$detail['payment_provider']            = 'ecpay';
				$detail['payment_method']              = $gateway_id;
				$detail['ecpay_charged_amount']        = $charged_amount;
				$detail['ecpay_environment']           = $environment;
				$detail['ecpay_merchant_id']           = $merchant_id;
				foreach ( $extra as $key => $value ) {
					$detail[ $key ] = $value;
				}
				$decision = 'ecpay_identity_bound';
				return $detail;
			},
			null,
			true,
			[ 'gateway_id' => $gateway_id, 'payment_method' => $gateway_id ],
			'pending',
			[ 'gateway_id', 'payment_method', 'currency', 'total' ]
		);
	}

	/**
	 * Build a callback claim from a discovery row. The returned guard must still
	 * be supplied to Core lifecycle so every actual write rechecks the fresh row.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function callback_claim(
		object $order,
		array $detail,
		string $merchant_trade_no,
		int $received_amount,
		string $merchant_id,
		string $environment = '',
		string $action = self::ACTION_SUCCESS
	): ?array {
		$order_id       = (int) ( $order->id ?? 0 );
		$scalar_gateway = is_string( $order->gateway_id ?? null ) ? (string) $order->gateway_id : '';
		$payment_method = (string) ( $order->payment_method ?? '' );
		if ( '' !== $scalar_gateway
			&& ( ! self::canonical_gateway( $scalar_gateway ) || ! hash_equals( $scalar_gateway, $payment_method ) ) ) {
			return null;
		}
		$gateway_id = '' !== $scalar_gateway ? $scalar_gateway : $payment_method;
		if ( $order_id <= 0
			|| ! self::canonical_gateway( $gateway_id )
			|| ! self::canonical_merchant_trade_no( $merchant_trade_no )
			|| $received_amount <= 0
			|| '' === $merchant_id
			|| ! in_array( $action, [ self::ACTION_SUCCESS, self::ACTION_FAILURE, self::ACTION_PAYMENT_INFO ], true )
			|| ( '' !== $environment && ! in_array( $environment, [ 'stage', 'live' ], true ) )
			|| ! self::root_aliases_match( $detail, $merchant_trade_no ) ) {
			return null;
		}

		$has_attempt = array_key_exists( YSPaymentAttempt::KEY, $detail );
		$has_dispatch = array_key_exists( YSPaymentDispatch::KEY, $detail );
		if ( ! $has_attempt && ! $has_dispatch ) {
			if ( array_key_exists( YSPaymentAttempt::HISTORY_KEY, $detail )
				|| '' !== (string) ( $detail['ecpay_operation_key'] ?? '' ) ) {
				return null;
			}
			$legacy_amount_matches = array_key_exists( 'ecpay_charged_amount', $detail )
				? self::required_positive_amount( $detail, 'ecpay_charged_amount', $received_amount )
				: self::legacy_order_amount( $order ) === $received_amount;
			if ( ! $legacy_amount_matches
				|| ! self::optional_exact( $detail, 'payment_provider', 'ecpay' )
				|| ! self::optional_exact( $detail, 'payment_method', $gateway_id )
				|| ! self::optional_exact( $detail, 'ecpay_merchant_id', $merchant_id )
				|| ( '' !== $environment && ! self::optional_exact( $detail, 'ecpay_environment', $environment ) ) ) {
				return null;
			}
			return [
				'mode'              => 'legacy',
				'action'            => $action,
				'order_id'          => $order_id,
				'gateway_id'        => $gateway_id,
				'merchant_trade_no' => $merchant_trade_no,
				'charged_amount'    => $received_amount,
				'merchant_id'       => $merchant_id,
				'environment'       => $environment,
				'scalar_gateway_bound' => self::canonical_gateway( $scalar_gateway ) && hash_equals( $scalar_gateway, $gateway_id ),
			];
		}
		if ( ! $has_attempt || ! $has_dispatch ) {
			return null;
		}
		if ( ! self::canonical_gateway( $scalar_gateway ) || ! hash_equals( $scalar_gateway, $gateway_id ) ) {
			return null;
		}

		$attempt = YSPaymentAttempt::current( $detail );
		$record  = YSPaymentDispatch::current( $detail );
		$operation_key = (string) ( $detail['ecpay_operation_key'] ?? '' );
		if ( ! self::root_aliases_match( $detail, $merchant_trade_no, true )
			|| ! self::required_positive_amount( $detail, 'ecpay_charged_amount', $received_amount )
			|| ! self::required_exact( $detail, 'payment_provider', 'ecpay' )
			|| ! self::required_exact( $detail, 'payment_method', $gateway_id )
			|| ! self::required_exact( $detail, 'ecpay_merchant_id', $merchant_id )
			|| ( '' !== $environment && ! self::required_exact( $detail, 'ecpay_environment', $environment ) )
			|| '' === $operation_key
			|| ! self::modern_dispatch_matches(
				$detail,
				$order_id,
				$gateway_id,
				$operation_key,
				(string) ( $record['token'] ?? '' ),
				[ YSPaymentDispatch::STATE_SUBMITTED, YSPaymentDispatch::STATE_INDETERMINATE, YSPaymentDispatch::STATE_TERMINAL ]
			) ) {
			return null;
		}
		if ( ! self::callback_action_allows_record( $detail, $action ) ) {
			return null;
		}

		$attempt_id = $attempt['id'] ?? null;
		if ( ! is_string( $attempt_id )
			|| ( '' !== $attempt_id && ! hash_equals( $attempt_id, $merchant_trade_no ) ) ) {
			return null;
		}

		return [
			'mode'               => '' === $attempt_id ? 'migrated' : 'modern',
			'action'             => $action,
			'order_id'           => $order_id,
			'gateway_id'         => $gateway_id,
			'merchant_trade_no'  => $merchant_trade_no,
			'charged_amount'     => $received_amount,
			'merchant_id'        => $merchant_id,
			'environment'        => $environment,
			'attempt_generation' => $attempt['generation'],
			'attempt_nonce'      => $attempt['nonce'],
			'dispatch_token'     => $record['token'],
			'operation_key'      => $operation_key,
			'scalar_gateway_bound' => true,
		];
	}

	/** Discovery only: current root, retired history, or an exact durable receipt. */
	public static function identity_is_discoverable(
		array $detail,
		string $merchant_trade_no,
		int $order_id = 0
	): bool {
		if ( ! self::canonical_merchant_trade_no( $merchant_trade_no ) ) {
			return false;
		}
		if ( self::root_aliases_match( $detail, $merchant_trade_no ) ) {
			return true;
		}
		foreach ( self::history_entries( $detail ) as $entry ) {
			$fields = is_array( $entry['fields'] ?? null ) ? $entry['fields'] : [];
			if ( self::root_aliases_match( $fields, $merchant_trade_no ) ) {
				return true;
			}
		}
		if ( $order_id > 0 ) {
			$ledger = self::late_ledger( $detail );
			$key = hash( 'sha256', 'ecpay|' . $order_id . '|' . $merchant_trade_no );
			$receipt = null === $ledger ? null : ( $ledger['entries'][ $key ] ?? null );
			if ( is_array( $receipt )
				&& self::valid_late_entry_for_order( $key, $receipt, $order_id )
				&& hash_equals( (string) $receipt['mer_trade_no'], $merchant_trade_no ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Fresh dispatch guard shared by requester pre-I/O and final form delivery.
	 *
	 * A predecessor may become paid after Core created the successor but before
	 * that successor sends bytes or exposes a form. The successor stays a
	 * separate attempt for manual reconciliation, yet it must not remain payable.
	 * OPEN/provider-terminal receipts are observations, not a reason to disturb
	 * the current dispatch; malformed ledgers fail closed.
	 *
	 * @param array<string,mixed> $detail
	 */
	public static function successor_dispatch_allows( array $detail, int $order_id, ?object $fresh_row = null ): bool {
		if ( $order_id <= 0 ) {
			return false;
		}
		if ( null !== $fresh_row ) {
			$attempt = YSPaymentAttempt::current( $detail );
			$record  = YSPaymentDispatch::current( $detail );
			if ( 'ecpay' === ( $attempt['provider'] ?? null ) ) {
				$gateway_id = is_string( $attempt['gateway_id'] ?? null ) ? $attempt['gateway_id'] : '';
				$charged_amount = $detail['ecpay_charged_amount'] ?? null;
				if ( ! self::canonical_gateway( $gateway_id )
					|| $gateway_id !== ( $record['gateway_id'] ?? null )
					|| ! self::required_positive_amount_value( $charged_amount )
					|| ! self::fresh_gateway_row_matches( $fresh_row, $gateway_id )
					|| ! self::fresh_order_amount_matches( $fresh_row, (int) $charged_amount ) ) {
					return false;
				}
			}
		}

		$ledger = self::late_ledger( $detail );
		if ( null === $ledger ) {
			return false;
		}
		foreach ( $ledger['entries'] as $entry_key => $entry ) {
			if ( ! is_string( $entry_key ) || ! is_array( $entry )
				|| ! self::valid_late_entry_for_order( $entry_key, $entry, $order_id )
				|| self::LATE_STATE_PAID_MANUAL === $entry['state']
				|| self::late_entry_has_success_anomaly( $entry ) ) {
				return false;
			}
		}
		return true;
	}

	/** A signed retired success anomaly is unresolved money, not permission. */
	private static function late_entry_has_success_anomaly( array $entry ): bool {
		$anomalies = $entry['anomalies'] ?? null;
		if ( ! is_array( $anomalies ) ) {
			return true;
		}
		foreach ( $anomalies as $anomaly ) {
			if ( is_array( $anomaly ) && self::ACTION_SUCCESS === ( $anomaly['action'] ?? null ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Prove that a signed callback belongs to a retired attempt. This never grants
	 * write authority; callers use it only to ACK a harmless delayed callback.
	 */
	public static function historical_callback_matches(
		object $order,
		array $detail,
		string $merchant_trade_no,
		int $received_amount,
		string $merchant_id,
		string $environment
	): bool {
		if ( ! self::canonical_merchant_trade_no( $merchant_trade_no )
			|| $received_amount <= 0
			|| '' === $merchant_id
			|| ! in_array( $environment, [ 'stage', 'live' ], true ) ) {
			return false;
		}
		foreach ( self::history_entries( $detail ) as $entry ) {
			$fields  = is_array( $entry['fields'] ?? null ) ? $entry['fields'] : [];
			$attempt = is_array( $entry['attempt'] ?? null ) ? $entry['attempt'] : [];
			if ( ! self::root_aliases_match( $fields, $merchant_trade_no ) ) {
				continue;
			}
			if ( [] !== $attempt && ( 'ecpay' !== ( $attempt['provider'] ?? null )
				|| ! self::canonical_gateway( (string) ( $attempt['gateway_id'] ?? '' ) ) ) ) {
				continue;
			}
			$stored_method = (string) ( $fields['payment_method'] ?? $attempt['gateway_id'] ?? '' );
			if ( '' !== $stored_method && ! self::canonical_gateway( $stored_method ) ) {
				continue;
			}
			$amount_matches = array_key_exists( 'ecpay_charged_amount', $fields )
				? self::required_positive_amount( $fields, 'ecpay_charged_amount', $received_amount )
				: self::legacy_order_amount( $order ) === $received_amount;
			if ( $amount_matches
				&& self::optional_exact( $fields, 'payment_provider', 'ecpay' )
				&& self::optional_exact( $fields, 'ecpay_merchant_id', $merchant_id )
				&& self::optional_exact( $fields, 'ecpay_environment', $environment ) ) {
				return true;
			}
		}
		return false;
	}

	/** @param array<string,mixed> $claim */
	public static function callback_guard( array $claim ): callable {
		return static function ( array $detail, ?object $fresh_row = null ) use ( $claim ): bool {
			if ( ! self::callback_claim_matches( $detail, $claim ) ) {
				return false;
			}

			$gateway_id = (string) ( $claim['gateway_id'] ?? '' );
			if ( ! empty( $claim['scalar_gateway_bound'] ) ) {
				return self::fresh_gateway_row_matches( $fresh_row, $gateway_id );
			}

			// Only a genuine pre-attempt legacy row may retain the historical
			// detail-only path. Its scalar gateway is NULL, while payment_method
			// still has to own the same ECPay method byte-for-byte.
			return 'legacy' === (string) ( $claim['mode'] ?? '' )
				&& self::fresh_legacy_gateway_row_matches( $fresh_row, $gateway_id );
		};
	}

	/** @param array<string,mixed> $claim @param array<string,scalar> $fields */
	public static function callback_detail_patch( array $claim, array $fields = [] ): callable {
		return static function ( array $detail ) use ( $claim, $fields ): array {
			if ( ! self::callback_claim_matches( $detail, $claim ) ) {
				return $detail;
			}
			if ( 'legacy' !== (string) ( $claim['mode'] ?? '' ) ) {
				$attempt = YSPaymentAttempt::current( $detail );
				if ( '' === (string) ( $attempt['id'] ?? '' ) ) {
					$attempt['id']                   = (string) $claim['merchant_trade_no'];
					$detail[ YSPaymentAttempt::KEY ] = $attempt;
				}
			}
			foreach ( $fields as $key => $value ) {
				if ( is_string( $key ) && is_scalar( $value ) && 1 === preg_match( '/^[a-z][a-z0-9_]{0,63}$/D', $key ) ) {
					$detail[ $key ] = $value;
				}
			}
			return $detail;
		};
	}

	/**
	 * Build lifecycle options which pin a modern attempt's scalar gateway in the
	 * same status/detail CAS. Truly old rows which never had gateway_id retain
	 * the legacy detail-only compatibility path.
	 *
	 * @param array<string,mixed>  $claim
	 * @param array<string,scalar> $columns
	 * @return array<string,mixed>
	 */
	public static function callback_lifecycle_options( array $claim, callable $detail_patch, array $columns = [] ): array {
		$options = [ 'detail_patch' => $detail_patch ];
		if ( ! empty( $claim['scalar_gateway_bound'] ) ) {
			$gateway_id = (string) ( $claim['gateway_id'] ?? '' );
			$columns['gateway_id'] = $gateway_id;
			$options['expected_column_values'] = [ 'gateway_id' => $gateway_id ];
		}
		if ( [] !== $columns ) {
			$options['columns'] = $columns;
		}
		return $options;
	}

	/**
	 * Fingerprint carried by the hosted ECPG page. It is not authority by
	 * itself; it only names the exact current attempt for a fresh server check.
	 */
	public static function browser_fingerprint( array $detail ): string {
		$operation_key = (string) ( $detail['ecpay_operation_key'] ?? '' );
		$merchant_trade_no = (string) ( $detail['ecpay_merchant_trade_no'] ?? $detail['mer_trade_no'] ?? '' );
		if ( '' === $operation_key || ! self::canonical_merchant_trade_no( $merchant_trade_no ) ) {
			return '';
		}
		return substr( hash( 'sha256', $operation_key . '|' . $merchant_trade_no ), 0, 32 );
	}

	/**
	 * One-time compatibility bridge for a 0.5.9 hosted-pay URL which predates
	 * attempt parameters. It binds only an otherwise-complete submitted ECPG
	 * attempt, after which the controller redirects to the canonical URL.
	 *
	 * @return array{merchant_trade_no:string,fingerprint:string}|null
	 */
	public static function migrate_hosted_identity( int $order_id, string $gateway_id ): ?array {
		if ( $order_id <= 0 || 'ys_ec_ecpay_ecpg_credit' !== $gateway_id ) {
			return null;
		}
		$mtn = '';
		$outcome = OrderPaymentDetail::mutate(
			$order_id,
			static function ( array $detail, int $cas_attempt, &$decision, ?object $fresh_row = null ) use ( $order_id, $gateway_id, &$mtn ): ?array {
				unset( $cas_attempt );
				$attempt = YSPaymentAttempt::current( $detail );
				$record  = YSPaymentDispatch::current( $detail );
				$root_a  = $detail['mer_trade_no'] ?? null;
				$root_b  = $detail['ecpay_merchant_trade_no'] ?? null;
				$operation = $detail['ecpay_operation_key'] ?? null;
				$charged_amount = $detail['ecpay_charged_amount'] ?? null;
				if ( ! is_string( $root_a )
					|| ! is_string( $root_b )
					|| ! hash_equals( $root_a, $root_b )
					|| ! self::canonical_merchant_trade_no( $root_a )
					|| ! is_string( $operation )
					|| '' !== (string) ( $attempt['id'] ?? '' )
					|| ! self::fresh_gateway_row_matches( $fresh_row, $gateway_id )
					|| ! self::required_positive_amount_value( $charged_amount )
					|| ! self::fresh_order_amount_matches( $fresh_row, (int) $charged_amount )
					|| ! self::successor_dispatch_allows( $detail, $order_id, $fresh_row )
					|| 'ecpay' !== ( $detail['payment_provider'] ?? null )
					|| $gateway_id !== ( $detail['payment_method'] ?? null )
					|| ! ( is_int( $charged_amount ) || ( is_string( $charged_amount ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $charged_amount ) ) )
					|| (int) $charged_amount <= 0
					|| ! is_string( $detail['ecpay_environment'] ?? null )
					|| ! is_string( $detail['ecpay_merchant_id'] ?? null )
					|| ! self::modern_dispatch_matches(
						$detail,
						$order_id,
						$gateway_id,
						$operation,
						(string) ( $record['token'] ?? '' ),
						[ YSPaymentDispatch::STATE_SUBMITTED ]
					) ) {
					$decision = YSPaymentDetailStore::STALE;
					return null;
				}
				$attempt['id']                   = $root_a;
				$detail[ YSPaymentAttempt::KEY ] = $attempt;
				$mtn = $root_a;
				$decision = 'hosted_identity_migrated';
				return $detail;
			},
			null,
			true,
			[],
			'pending',
			[ 'gateway_id', 'payment_method', 'currency', 'total' ]
		);
		$detail = $outcome->get_detail();
		if ( ! $outcome->is_persisted() || '' === $mtn || ! is_array( $detail ) ) {
			return null;
		}
		$fingerprint = self::browser_fingerprint( $detail );
		return '' === $fingerprint ? null : [ 'merchant_trade_no' => $mtn, 'fingerprint' => $fingerprint ];
	}

	public static function browser_owner_matches(
		array $detail,
		int $order_id,
		string $gateway_id,
		string $merchant_trade_no,
		string $fingerprint,
		?object $fresh_row = null
	): bool {
		$credentials = Settings::payment_credentials();
		$environment = ! empty( $credentials['test_mode'] ) ? 'stage' : 'live';
		$merchant_id = (string) ( $credentials['merchant_id'] ?? '' );
		return self::browser_owner_matches_identity(
			$detail,
			$order_id,
			$gateway_id,
			$merchant_trade_no,
			$fingerprint,
			$merchant_id,
			$environment
		) && self::successor_dispatch_allows( $detail, $order_id, $fresh_row );
	}

	private static function browser_owner_matches_identity(
		array $detail,
		int $order_id,
		string $gateway_id,
		string $merchant_trade_no,
		string $fingerprint,
		string $merchant_id,
		string $environment
	): bool {
		$flow = $detail['ecpay_ecpg_flow'] ?? null;
		if ( ! self::canonical_merchant_trade_no( $merchant_trade_no )
			|| $order_id <= 0
			|| ! self::canonical_gateway( $gateway_id )
			|| 1 !== preg_match( '/^[a-f0-9]{32}$/D', $fingerprint )
			|| ! self::root_aliases_match( $detail, $merchant_trade_no, true )
			|| ! self::required_positive_amount_value( $detail['ecpay_charged_amount'] ?? null )
			|| ! self::required_exact( $detail, 'payment_provider', 'ecpay' )
			|| ! self::required_exact( $detail, 'payment_method', $gateway_id )
			|| ! self::required_exact( $detail, 'ecpay_environment', $environment )
			|| '' === $merchant_id
			|| ! self::required_exact( $detail, 'ecpay_merchant_id', $merchant_id )
			|| ! in_array( $flow, [ 'pay', 'bind' ], true )
			|| ! hash_equals( self::browser_fingerprint( $detail ), $fingerprint ) ) {
			return false;
		}
		$attempt = YSPaymentAttempt::current( $detail );
		$record  = YSPaymentDispatch::current( $detail );
		return self::modern_dispatch_matches(
				$detail,
				$order_id,
				$gateway_id,
				(string) ( $detail['ecpay_operation_key'] ?? '' ),
				(string) ( $record['token'] ?? '' ),
				[ YSPaymentDispatch::STATE_SUBMITTED ]
			)
			&& hash_equals( (string) ( $attempt['id'] ?? '' ), $merchant_trade_no );
	}

	public static function browser_owner_is_current(
		int $order_id,
		string $gateway_id,
		string $merchant_trade_no,
		string $fingerprint,
		?array $verified_identity = null
	): bool {
		YSOrder::forget( $order_id );
		$order = YSOrder::find( $order_id );
		if ( ! is_object( $order )
			|| ! hash_equals( (string) ( $order->gateway_id ?? '' ), $gateway_id )
			|| ! hash_equals( (string) ( $order->payment_method ?? '' ), $gateway_id ) ) {
			return false;
		}
		$detail = OrderPaymentDetail::read( $order_id );
		if ( ! is_array( $detail ) ) {
			return false;
		}
		if ( null === $verified_identity ) {
			return self::browser_owner_matches( $detail, $order_id, $gateway_id, $merchant_trade_no, $fingerprint, $order );
		}
		$merchant_id = $verified_identity['merchant_id'] ?? null;
		$environment = $verified_identity['environment'] ?? null;
		return is_string( $merchant_id )
			&& '' !== $merchant_id
			&& is_string( $environment )
			&& in_array( $environment, [ 'stage', 'live' ], true )
			&& self::browser_owner_matches_identity(
				$detail,
				$order_id,
				$gateway_id,
				$merchant_trade_no,
				$fingerprint,
				$merchant_id,
				$environment
			)
			&& self::successor_dispatch_allows( $detail, $order_id, $order );
	}

	/** Reserve the one hosted browser action allowed to authorize this attempt. */
	public static function claim_browser_authorization(
		int $order_id,
		string $gateway_id,
		string $merchant_trade_no,
		string $fingerprint,
		string $kind
	): string {
		if ( ! in_array( $kind, [ 'confirm', 'saved' ], true ) ) {
			return '';
		}
		try {
			$nonce = bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $error ) {
			unset( $error );
			return '';
		}
		$outcome = OrderPaymentDetail::mutate(
			$order_id,
			static function ( array $detail, int $cas_attempt, &$decision, ?object $fresh_row = null ) use (
				$order_id,
				$gateway_id,
				$merchant_trade_no,
				$fingerprint,
				$kind,
				$nonce
			): ?array {
				unset( $cas_attempt );
				if ( ! self::fresh_gateway_row_matches( $fresh_row, $gateway_id )
					|| ! self::browser_owner_matches( $detail, $order_id, $gateway_id, $merchant_trade_no, $fingerprint, $fresh_row ) ) {
					$decision = YSPaymentDetailStore::STALE;
					return null;
				}
				$record = YSPaymentDispatch::current( $detail );
				if ( array_key_exists( 'ecpay_browser_authorization', $record ) ) {
					$decision = 'already_claimed';
					return null;
				}
				$record['ecpay_browser_authorization'] = [
					'nonce'         => $nonce,
					'kind'          => $kind,
					'fingerprint'   => $fingerprint,
					'operation_key' => (string) ( $record['operation_key'] ?? '' ),
					'state'         => 'reserved',
					'claimed_at'    => time(),
				];
				$detail[ YSPaymentDispatch::KEY ] = $record;
				$decision = $nonce;
				return $detail;
			},
			null,
			true,
			[],
			'pending',
			[ 'gateway_id', 'payment_method', 'currency', 'total' ]
		);
		return $outcome->is_persisted() && hash_equals( $nonce, (string) $outcome->get_decision() ) ? $nonce : '';
	}

	/** Turn the local reservation into durable sent intent immediately pre-I/O. */
	public static function authorize_browser_send(
		int $order_id,
		string $gateway_id,
		string $merchant_trade_no,
		string $fingerprint,
		string $claim_nonce
	): bool {
		$outcome = OrderPaymentDetail::mutate(
			$order_id,
			static function ( array $detail, int $cas_attempt, &$decision, ?object $fresh_row = null ) use (
				$order_id,
				$gateway_id,
				$merchant_trade_no,
				$fingerprint,
				$claim_nonce
			): ?array {
				unset( $cas_attempt );
				if ( ! self::fresh_gateway_row_matches( $fresh_row, $gateway_id )
					|| ! self::browser_owner_matches( $detail, $order_id, $gateway_id, $merchant_trade_no, $fingerprint, $fresh_row ) ) {
					$decision = YSPaymentDetailStore::STALE;
					return null;
				}
				$record = YSPaymentDispatch::current( $detail );
				$claim  = $record['ecpay_browser_authorization'] ?? null;
				if ( ! is_array( $claim )
					|| 'reserved' !== ( $claim['state'] ?? null )
					|| ! is_string( $claim['nonce'] ?? null )
					|| ! hash_equals( $claim_nonce, $claim['nonce'] )
					|| ! hash_equals( $fingerprint, (string) ( $claim['fingerprint'] ?? '' ) )
					|| ! hash_equals( (string) ( $record['operation_key'] ?? '' ), (string) ( $claim['operation_key'] ?? '' ) ) ) {
					$decision = 'not_authorized';
					return null;
				}
				$claim['state']   = 'sent';
				$claim['sent_at'] = time();
				$record['ecpay_browser_authorization'] = $claim;
				$detail[ YSPaymentDispatch::KEY ] = $record;
				$decision = 'send_authorized';
				return $detail;
			},
			null,
			true,
			[],
			'pending',
			[ 'gateway_id', 'payment_method', 'currency', 'total' ]
		);
		return $outcome->is_persisted() && 'send_authorized' === $outcome->get_decision();
	}

	/**
	 * Atomically prove that a hosted provider response still belongs to the exact
	 * sent browser authorization before exposing its 3-D Secure URL.
	 *
	 * Provider data is deliberately not persisted here. The handoff marker is
	 * only a one-time browser capability; the later callback still owns payment
	 * settlement through the normal lifecycle CAS.
	 */
	public static function claim_browser_result_handoff(
		int $order_id,
		string $gateway_id,
		string $browser_merchant_trade_no,
		string $fingerprint,
		string $claim_nonce,
		string $credential_merchant_id,
		string $environment,
		string $reported_merchant_id,
		string $reported_merchant_trade_no,
		int $reported_amount
	): bool {
		if ( $order_id <= 0
			|| ! self::canonical_gateway( $gateway_id )
			|| ! self::canonical_merchant_trade_no( $browser_merchant_trade_no )
			|| ! self::canonical_merchant_trade_no( $reported_merchant_trade_no )
			|| '' === $reported_merchant_id
			|| $reported_amount <= 0
			|| 1 !== preg_match( '/^[a-f0-9]{32}$/D', $fingerprint )
			|| 1 !== preg_match( '/^[a-f0-9]{32}$/D', $claim_nonce )
			|| '' === $credential_merchant_id
			|| ! hash_equals( $credential_merchant_id, $reported_merchant_id )
			|| ! in_array( $environment, [ 'stage', 'live' ], true ) ) {
			return false;
		}
		try {
			$handoff_nonce = bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $error ) {
			unset( $error );
			return false;
		}
		$outcome = OrderPaymentDetail::mutate(
			$order_id,
			static function ( array $detail, int $cas_attempt, &$decision, ?object $fresh_row = null ) use (
				$order_id,
				$gateway_id,
				$browser_merchant_trade_no,
				$fingerprint,
				$claim_nonce,
				$credential_merchant_id,
				$reported_merchant_id,
				$reported_merchant_trade_no,
				$reported_amount,
				$environment,
				$handoff_nonce
			): ?array {
				unset( $cas_attempt );
				if ( ! self::fresh_gateway_row_matches( $fresh_row, $gateway_id )
					|| ! self::browser_owner_matches_identity(
						$detail,
						$order_id,
						$gateway_id,
						$browser_merchant_trade_no,
						$fingerprint,
						$credential_merchant_id,
						$environment
					)
					|| ! self::successor_dispatch_allows( $detail, $order_id, $fresh_row ) ) {
					$decision = YSPaymentDetailStore::STALE;
					return null;
				}
				$record        = YSPaymentDispatch::current( $detail );
				$authorization = $record['ecpay_browser_authorization'] ?? null;
				if ( ! is_array( $authorization )
					|| 'sent' !== ( $authorization['state'] ?? null )
					|| ! is_string( $authorization['nonce'] ?? null )
					|| ! hash_equals( $claim_nonce, $authorization['nonce'] )
					|| ! hash_equals( $fingerprint, (string) ( $authorization['fingerprint'] ?? '' ) )
					|| ! hash_equals( (string) ( $record['operation_key'] ?? '' ), (string) ( $authorization['operation_key'] ?? '' ) ) ) {
					$decision = 'browser_result_not_owned';
					return null;
				}

				$fresh_order = (object) [
					'id'             => $order_id,
					'status'         => 'pending',
					'gateway_id'     => (string) ( $fresh_row->gateway_id ?? '' ),
					'payment_method' => (string) ( $fresh_row->payment_method ?? '' ),
				];
				$provider_claim = self::callback_claim(
					$fresh_order,
					$detail,
					$reported_merchant_trade_no,
					$reported_amount,
					$reported_merchant_id,
					$environment,
					self::ACTION_SUCCESS
				);
				if ( ! is_array( $provider_claim )
					|| 'modern' !== ( $provider_claim['mode'] ?? null )
					|| ! hash_equals( $browser_merchant_trade_no, $reported_merchant_trade_no ) ) {
					$decision = YSPaymentDetailStore::STALE;
					return null;
				}

				$authorization['state']         = 'handed_off';
				$authorization['handoff_nonce'] = $handoff_nonce;
				$authorization['handed_off_at'] = time();
				$record['ecpay_browser_authorization'] = $authorization;
				$detail[ YSPaymentDispatch::KEY ] = $record;
				$decision = $handoff_nonce;
				return $detail;
			},
			null,
			true,
			[],
			'pending',
			[ 'gateway_id', 'payment_method', 'currency', 'total' ]
		);
		return $outcome->is_persisted() && hash_equals( $handoff_nonce, (string) $outcome->get_decision() );
	}

	/** Release only a pre-send reservation; a sent claim is never rewound. */
	public static function release_browser_authorization(
		int $order_id,
		string $gateway_id,
		string $merchant_trade_no,
		string $fingerprint,
		string $claim_nonce
	): bool {
		$outcome = OrderPaymentDetail::mutate(
			$order_id,
			static function ( array $detail, int $cas_attempt, &$decision, ?object $fresh_row = null ) use (
				$order_id,
				$gateway_id,
				$merchant_trade_no,
				$fingerprint,
				$claim_nonce
			): ?array {
				unset( $cas_attempt );
				if ( ! self::fresh_gateway_row_matches( $fresh_row, $gateway_id )
					|| ! self::browser_owner_matches( $detail, $order_id, $gateway_id, $merchant_trade_no, $fingerprint ) ) {
					$decision = YSPaymentDetailStore::STALE;
					return null;
				}
				$record = YSPaymentDispatch::current( $detail );
				$claim  = $record['ecpay_browser_authorization'] ?? null;
				if ( ! is_array( $claim )
					|| 'reserved' !== ( $claim['state'] ?? null )
					|| ! is_string( $claim['nonce'] ?? null )
					|| ! hash_equals( $claim_nonce, $claim['nonce'] ) ) {
					$decision = 'not_releasable';
					return null;
				}
				unset( $record['ecpay_browser_authorization'] );
				$detail[ YSPaymentDispatch::KEY ] = $record;
				$decision = 'released';
				return $detail;
			},
			null,
			true,
			[],
			'pending',
			[ 'gateway_id', 'payment_method' ]
		);
		return $outcome->is_persisted() && 'released' === $outcome->get_decision();
	}

	/** @return array{schema:int,entries:array<string,array<string,mixed>>}|null */
	private static function late_ledger( array $detail ): ?array {
		if ( ! array_key_exists( self::LATE_SETTLEMENT_KEY, $detail ) ) {
			return [ 'schema' => self::LATE_SCHEMA, 'entries' => [] ];
		}

		$ledger = $detail[ self::LATE_SETTLEMENT_KEY ];
		$ledger_keys = is_array( $ledger ) ? array_keys( $ledger ) : [];
		sort( $ledger_keys );
		if ( ! is_array( $ledger )
			|| [ 'entries', 'schema' ] !== $ledger_keys
			|| self::LATE_SCHEMA !== ( $ledger['schema'] ?? null )
			|| ! is_array( $ledger['entries'] ?? null )
			|| ( [] !== $ledger['entries'] && array_is_list( $ledger['entries'] ) )
			|| count( $ledger['entries'] ) > self::LATE_MAX_ENTRIES ) {
			return null;
		}

		return [
			'schema'  => self::LATE_SCHEMA,
			'entries' => $ledger['entries'],
		];
	}

	/** @param array<string,mixed> $entry */
	private static function valid_late_entry_for_order( string $entry_key, array $entry, int $order_id ): bool {
		$expected_keys = [
			'anomalies',
			'archived_at',
			'amount',
			'bank_digest',
			'claim_digest',
			'currency',
			'environment',
			'expire_digest',
			'gateway_id',
			'last_action',
			'last_callback_at',
			'last_callback_evidence_digest',
			'last_rtn_code',
			'last_trade_no',
			'last_trade_no_digest',
			'mer_trade_no',
			'merchant_digest',
			'order_id',
			'pay_no_digest',
			'payment_family',
			'predecessor_dispatch_digest',
			'predecessor_generation',
			'predecessor_nonce_digest',
			'provider',
			'schema',
			'state',
			'successor_dispatch_digest',
			'successor_gateway_id',
			'successor_generation',
			'successor_nonce_digest',
		];
		$actual_keys = array_keys( $entry );
		sort( $expected_keys );
		sort( $actual_keys );
		if ( $expected_keys !== $actual_keys
			|| self::LATE_ENTRY_SCHEMA !== ( $entry['schema'] ?? null )
			|| $order_id <= 0 || $order_id !== ( $entry['order_id'] ?? null )
			|| 'ecpay' !== ( $entry['provider'] ?? null )
			|| ! isset( self::OFFLINE_GATEWAYS[ (string) ( $entry['gateway_id'] ?? '' ) ] )
			|| self::OFFLINE_GATEWAYS[ (string) $entry['gateway_id'] ] !== ( $entry['payment_family'] ?? null )
			|| ! self::canonical_merchant_trade_no( (string) ( $entry['mer_trade_no'] ?? '' ) )
			|| ! hash_equals( hash( 'sha256', 'ecpay|' . $order_id . '|' . $entry['mer_trade_no'] ), $entry_key )
			|| ! is_int( $entry['amount'] ?? null ) || $entry['amount'] <= 0
			|| 'TWD' !== ( $entry['currency'] ?? null )
			|| ! in_array( $entry['environment'] ?? null, [ 'stage', 'live' ], true )
			|| ! self::digest_or_empty( $entry['bank_digest'] ?? null )
			|| ( 'atm' === ( $entry['payment_family'] ?? null ) && ! self::digest( $entry['bank_digest'] ?? null ) )
			|| ! self::digest( $entry['pay_no_digest'] ?? null )
			|| ! self::digest( $entry['expire_digest'] ?? null )
			|| ! self::digest( $entry['merchant_digest'] ?? null )
			|| ! is_int( $entry['predecessor_generation'] ?? null ) || $entry['predecessor_generation'] <= 0
			|| ! self::digest( $entry['predecessor_nonce_digest'] ?? null )
			|| ! self::digest( $entry['predecessor_dispatch_digest'] ?? null )
			|| ! is_int( $entry['successor_generation'] ?? null )
			|| $entry['successor_generation'] !== $entry['predecessor_generation'] + 1
			|| ! self::digest( $entry['successor_nonce_digest'] ?? null )
			|| ! self::digest( $entry['successor_dispatch_digest'] ?? null )
			|| 1 !== preg_match( '/^ys_ec_[a-z0-9_]{1,90}$/D', (string) ( $entry['successor_gateway_id'] ?? '' ) )
			|| ! self::mysql_time( $entry['archived_at'] ?? null )
			|| ! self::digest( $entry['claim_digest'] ?? null )
			|| ! hash_equals( self::late_claim_digest( $entry ), (string) $entry['claim_digest'] )
			|| ! in_array( $entry['state'] ?? null, [ self::LATE_STATE_OPEN, self::LATE_STATE_PROVIDER_TERMINAL, self::LATE_STATE_PAID_MANUAL ], true )
			|| ! self::provider_trade_no_or_empty( $entry['last_trade_no'] ?? null )
			|| ! self::digest_or_empty( $entry['last_trade_no_digest'] ?? null )
			|| ( '' === $entry['last_trade_no'] ) !== ( '' === $entry['last_trade_no_digest'] )
			|| ( '' !== $entry['last_trade_no']
				&& ! hash_equals( hash( 'sha256', $entry['last_trade_no'] ), (string) $entry['last_trade_no_digest'] ) )
			|| ! self::digest_or_empty( $entry['last_callback_evidence_digest'] ?? null )
			|| ! self::valid_late_anomalies( $entry['anomalies'] ?? null, $order_id, (string) ( $entry['mer_trade_no'] ?? '' ) ) ) {
			return false;
		}

		$action = $entry['last_action'] ?? null;
		$rtn_code = $entry['last_rtn_code'] ?? null;
		$at = $entry['last_callback_at'] ?? null;
		if ( self::LATE_STATE_OPEN === $entry['state'] && '' === $action ) {
			return '' === $rtn_code
				&& '' === $entry['last_trade_no']
				&& '' === $entry['last_trade_no_digest']
				&& '' === $entry['last_callback_evidence_digest']
				&& null === $at;
		}
		if ( self::LATE_STATE_OPEN === $entry['state'] ) {
			return self::ACTION_PAYMENT_INFO === $action
				&& in_array( $rtn_code, [ '2', '10100073' ], true )
				&& self::digest( $entry['last_callback_evidence_digest'] )
				&& self::mysql_time( $at );
		}
		if ( self::LATE_STATE_PROVIDER_TERMINAL === $entry['state'] ) {
			return self::ACTION_FAILURE === $action
				&& is_string( $rtn_code )
				&& 1 === preg_match( '/^[0-9]{1,10}$/D', $rtn_code )
				&& ! in_array( $rtn_code, [ '1', '2', '10100073' ], true )
				&& self::digest( $entry['last_callback_evidence_digest'] )
				&& self::mysql_time( $at );
		}

		return self::ACTION_SUCCESS === $action
			&& '1' === $rtn_code
			&& null !== self::provider_trade_no( $entry['last_trade_no'] )
			&& hash_equals( hash( 'sha256', $entry['last_trade_no'] ), (string) $entry['last_trade_no_digest'] )
			&& self::digest( $entry['last_trade_no_digest'] )
			&& self::digest( $entry['last_callback_evidence_digest'] )
			&& self::mysql_time( $at );
	}

	/** @param array<string,mixed> $entry */
	private static function late_claim_digest( array $entry ): string {
		$claim = [];
		foreach ( [
			'schema', 'order_id', 'provider', 'gateway_id', 'payment_family', 'mer_trade_no',
			'pay_no_digest', 'expire_digest', 'bank_digest', 'amount', 'currency', 'merchant_digest',
			'environment', 'predecessor_generation', 'predecessor_nonce_digest',
			'predecessor_dispatch_digest', 'successor_generation', 'successor_nonce_digest',
			'successor_dispatch_digest', 'successor_gateway_id', 'archived_at',
		] as $key ) {
			$claim[ $key ] = $entry[ $key ] ?? null;
		}
		return hash( 'sha256', (string) json_encode( $claim, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}

	/** @param mixed $anomalies */
	private static function valid_late_anomalies( mixed $anomalies, int $order_id, string $merchant_trade_no ): bool {
		if ( ! is_array( $anomalies )
			|| ( [] !== $anomalies && array_is_list( $anomalies ) )
			|| count( $anomalies ) > self::LATE_MAX_ANOMALIES ) {
			return false;
		}
		foreach ( $anomalies as $key => $anomaly ) {
			if ( ! is_string( $key ) || ! is_array( $anomaly ) || array_is_list( $anomaly ) ) {
				return false;
			}
			$expected_keys = [
				'action', 'amount', 'environment', 'evidence_digest', 'merchant_digest',
				'reason', 'recorded_at', 'rtn_code', 'schema', 'trade_no',
			];
			$actual_keys = array_keys( $anomaly );
			sort( $expected_keys );
			sort( $actual_keys );
			if ( $expected_keys !== $actual_keys
				|| 1 !== ( $anomaly['schema'] ?? null )
				|| ! in_array( $anomaly['reason'] ?? null, [
					'receipt_mismatch', 'trade_no_missing', 'divergent_after_paid', 'divergent_after_terminal',
				], true )
				|| ! in_array( $anomaly['action'] ?? null, [ self::ACTION_SUCCESS, self::ACTION_FAILURE, self::ACTION_PAYMENT_INFO ], true )
				|| ! is_int( $anomaly['amount'] ?? null ) || $anomaly['amount'] < 0
				|| ! self::provider_trade_no_or_empty( $anomaly['trade_no'] ?? null )
				|| ! is_string( $anomaly['rtn_code'] ?? null )
				|| 1 !== preg_match( '/^(?:[0-9]{1,10})?$/D', $anomaly['rtn_code'] )
				|| ! self::digest( $anomaly['merchant_digest'] ?? null )
				|| ! in_array( $anomaly['environment'] ?? null, [ 'stage', 'live' ], true )
				|| ! self::digest( $anomaly['evidence_digest'] ?? null )
				|| ! self::mysql_time( $anomaly['recorded_at'] ?? null )
				|| ! hash_equals(
					hash( 'sha256', 'ecpay-anomaly|' . $order_id . '|' . $merchant_trade_no . '|' . $anomaly['reason'] . '|' . $anomaly['evidence_digest'] ),
					$key
				) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Add one immutable, bounded receipt for a verified callback which names a
	 * known predecessor but cannot alter its authoritative state.
	 *
	 * @param array<string,mixed> $entry
	 * @param array<string,string> $params
	 * @return array<string,mixed>|null
	 */
	private static function append_late_anomaly(
		array $entry,
		array $params,
		string $merchant_id,
		string $environment,
		string $action,
		int $amount,
		string $reason,
		string $evidence
	): ?array {
		$anomalies = $entry['anomalies'] ?? null;
		if ( ! self::valid_late_anomalies( $anomalies, (int) $entry['order_id'], (string) $entry['mer_trade_no'] ) ) {
			return null;
		}
		$trade_no = self::provider_trade_no( $params['TradeNo'] ?? null ) ?? '';
		$rtn_code = self::bounded_text( $params['RtnCode'] ?? null, 10 );
		$rtn_code = is_string( $rtn_code ) && 1 === preg_match( '/^[0-9]{1,10}$/D', $rtn_code ) ? $rtn_code : '';
		$key = hash(
			'sha256',
			'ecpay-anomaly|' . $entry['order_id'] . '|' . $entry['mer_trade_no'] . '|' . $reason . '|' . $evidence
		);
		$anomaly = [
			'schema'          => 1,
			'reason'          => $reason,
			'action'          => $action,
			'rtn_code'        => $rtn_code,
			'amount'          => max( 0, $amount ),
			'trade_no'        => $trade_no,
			'merchant_digest' => hash( 'sha256', $merchant_id ),
			'environment'     => $environment,
			'evidence_digest' => $evidence,
			'recorded_at'     => current_time( 'mysql' ),
		];
		if ( array_key_exists( $key, $anomalies ) ) {
			$existing = $anomalies[ $key ];
			unset( $anomaly['recorded_at'] );
			if ( ! is_array( $existing ) ) {
				return null;
			}
			$existing_without_time = $existing;
			unset( $existing_without_time['recorded_at'] );
			return $anomaly === $existing_without_time ? $entry : null;
		}
		if ( count( $anomalies ) >= self::LATE_MAX_ANOMALIES ) {
			return null;
		}
		$anomalies[ $key ] = $anomaly;
		$entry['anomalies'] = $anomalies;
		return self::valid_late_anomalies( $anomalies, (int) $entry['order_id'], (string) $entry['mer_trade_no'] )
			? $entry
			: null;
	}

	/** @param array<string,mixed> $entry @param array<string,string> $params */
	private static function late_callback_matches(
		array $entry,
		array $params,
		string $merchant_id,
		string $environment,
		string $action,
		int $amount
	): bool {
		$mtn = self::bounded_text( $params['MerchantTradeNo'] ?? null, 20 );
		$payment_type = self::bounded_text( $params['PaymentType'] ?? null, 100 );
		$rtn_code = self::bounded_text( $params['RtnCode'] ?? null, 10 );
		if ( null === $mtn || null === $payment_type || null === $rtn_code
			|| ! hash_equals( (string) $entry['mer_trade_no'], $mtn )
			|| $amount !== (int) $entry['amount']
			|| ! hash_equals( (string) $entry['merchant_digest'], hash( 'sha256', $merchant_id ) )
			|| ! hash_equals( (string) $entry['environment'], $environment )
			|| ! hash_equals( $merchant_id, (string) ( $params['MerchantID'] ?? '' ) )
			|| ! hash_equals( (string) $entry['payment_family'], self::offline_payment_family( $payment_type ) ) ) {
			return false;
		}

		if ( self::ACTION_SUCCESS === $action ) {
			return '1' === $rtn_code && null !== self::provider_trade_no( $params['TradeNo'] ?? null );
		}
		if ( self::ACTION_FAILURE === $action ) {
			return 1 === preg_match( '/^[0-9]{1,10}$/D', $rtn_code )
				&& ! in_array( $rtn_code, [ '1', '2', '10100073' ], true );
		}
		if ( ! in_array( $rtn_code, [ '2', '10100073' ], true ) ) {
			return false;
		}

		$pay_no = 'atm' === $entry['payment_family']
			? self::bounded_text( $params['vAccount'] ?? null, 100 )
			: self::bounded_text( $params['PaymentNo'] ?? null, 100 );
		$expires = self::bounded_text( $params['ExpireDate'] ?? null, 100 );
		if ( null === $pay_no || null === $expires
			|| ! hash_equals( (string) $entry['pay_no_digest'], hash( 'sha256', $pay_no ) )
			|| ! hash_equals( (string) $entry['expire_digest'], hash( 'sha256', $expires ) ) ) {
			return false;
		}
		if ( 'atm' !== $entry['payment_family'] ) {
			return true;
		}
		$bank = self::bounded_text( $params['BankCode'] ?? null, 30 );
		return null !== $bank
			&& self::digest( $entry['bank_digest'] )
			&& hash_equals( (string) $entry['bank_digest'], hash( 'sha256', $bank ) );
	}

	/** @param array<string,string> $params */
	private static function late_callback_digest( array $params, string $action ): string {
		$trade_no = self::bounded_text( $params['TradeNo'] ?? null, 100 );
		$family = self::offline_payment_family( $params['PaymentType'] ?? null );
		$pay_no = self::bounded_text(
			'atm' === $family ? ( $params['vAccount'] ?? null ) : ( $params['PaymentNo'] ?? null ),
			100
		);
		$expires = self::bounded_text( $params['ExpireDate'] ?? null, 100 );
		$bank = self::bounded_text( $params['BankCode'] ?? null, 30 );
		$canonical = [
			'action'            => $action,
			'merchant_id'       => (string) ( $params['MerchantID'] ?? '' ),
			'merchant_trade_no' => (string) ( $params['MerchantTradeNo'] ?? '' ),
			'trade_amount'      => (string) ( $params['TradeAmt'] ?? '' ),
			'rtn_code'          => (string) ( $params['RtnCode'] ?? '' ),
			'payment_type'      => (string) ( $params['PaymentType'] ?? '' ),
			'trade_no_digest'   => null === $trade_no ? '' : hash( 'sha256', $trade_no ),
			'pay_no_digest'     => null === $pay_no ? '' : hash( 'sha256', $pay_no ),
			'expire_digest'     => null === $expires ? '' : hash( 'sha256', $expires ),
			'bank_digest'       => null === $bank ? '' : hash( 'sha256', $bank ),
		];
		return hash( 'sha256', (string) json_encode( $canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}

	private static function offline_payment_family( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = strtoupper( trim( $value ) );
		if ( 'ATM' === $value || 1 === preg_match( '/^ATM(?:_|$)/D', $value ) ) {
			return 'atm';
		}
		if ( in_array( $value, [ 'CVS', 'CVS_CODE' ], true )
			|| 1 === preg_match( '/^(?:CVS|BARCODE)(?:_|$)/D', $value ) ) {
			return 'cvs';
		}
		return '';
	}

	/**
	 * Classify whether selecting a gateway can expose another long-lived payment
	 * account/code while an archived ECPay instrument is still payable.
	 *
	 * ECPay descriptors are the provider's closed source of truth. Cross-provider
	 * delayed gateways are the exact current Core inventory; a genuinely unknown
	 * target is deliberately unclassified (null) and therefore fails closed at
	 * the OPEN-receipt gate. This does not grant any predecessor rotation right.
	 */
	private static function successor_may_issue_delayed_instrument( string $gateway_id ): ?bool {
		$descriptor = EcpayPaymentCatalog::get( $gateway_id );
		if ( is_array( $descriptor ) ) {
			$choose_payment = (string) ( $descriptor['choose_payment'] ?? '' );
			return in_array( $choose_payment, [ 'ATM', 'CVS', 'BARCODE' ], true );
		}
		if ( isset( self::EXTERNAL_DELAYED_GATEWAYS[ $gateway_id ] ) ) {
			return true;
		}
		if ( isset( self::KNOWN_NON_DELAYED_GATEWAYS[ $gateway_id ] ) ) {
			return false;
		}
		return null;
	}

	private static function bounded_text( mixed $value, int $max_bytes ): ?string {
		if ( ! is_string( $value ) || $max_bytes <= 0 || '' === $value
			|| trim( $value ) !== $value || strlen( $value ) > $max_bytes
			|| 1 === preg_match( '/[\x00-\x1F\x7F]/D', $value ) ) {
			return null;
		}
		return $value;
	}

	private static function provider_trade_no( mixed $value ): ?string {
		$value = self::bounded_text( $value, 100 );
		return null !== $value && 1 === preg_match( '/^[A-Za-z0-9._-]{1,100}$/D', $value )
			? $value
			: null;
	}

	private static function provider_trade_no_or_empty( mixed $value ): bool {
		return '' === $value || null !== self::provider_trade_no( $value );
	}

	private static function digest( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $value );
	}

	private static function digest_or_empty( mixed $value ): bool {
		return '' === $value || self::digest( $value );
	}

	private static function mysql_time( mixed $value ): bool {
		return is_string( $value )
			&& 1 === preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $value );
	}

	/** @param array<string,mixed> $claim */
	private static function callback_claim_matches( array $detail, array $claim ): bool {
		$mode = (string) ( $claim['mode'] ?? '' );
		$mtn  = (string) ( $claim['merchant_trade_no'] ?? '' );
		$amount = (int) ( $claim['charged_amount'] ?? 0 );
		if ( ! self::root_aliases_match( $detail, $mtn, 'legacy' !== $mode )
			|| ( array_key_exists( 'ecpay_charged_amount', $detail )
				? ! self::required_positive_amount( $detail, 'ecpay_charged_amount', $amount )
				: 'legacy' !== $mode ) ) {
			return false;
		}

		if ( 'legacy' === $mode ) {
			return ! array_key_exists( YSPaymentAttempt::KEY, $detail )
				&& ! array_key_exists( YSPaymentDispatch::KEY, $detail )
				&& ! array_key_exists( YSPaymentAttempt::HISTORY_KEY, $detail )
				&& '' === (string) ( $detail['ecpay_operation_key'] ?? '' )
				&& self::optional_exact( $detail, 'payment_provider', 'ecpay' )
				&& self::optional_exact( $detail, 'payment_method', (string) ( $claim['gateway_id'] ?? '' ) )
				&& self::optional_exact( $detail, 'ecpay_merchant_id', (string) ( $claim['merchant_id'] ?? '' ) )
				&& ( '' === (string) ( $claim['environment'] ?? '' )
					|| self::optional_exact( $detail, 'ecpay_environment', (string) $claim['environment'] ) );
		}
		if ( ! self::required_exact( $detail, 'payment_provider', 'ecpay' )
			|| ! self::required_exact( $detail, 'payment_method', (string) ( $claim['gateway_id'] ?? '' ) )
			|| ! self::required_exact( $detail, 'ecpay_merchant_id', (string) ( $claim['merchant_id'] ?? '' ) )
			|| ( '' !== (string) ( $claim['environment'] ?? '' )
				&& ! self::required_exact( $detail, 'ecpay_environment', (string) $claim['environment'] ) ) ) {
			return false;
		}

		$attempt = YSPaymentAttempt::current( $detail );
		$record  = YSPaymentDispatch::current( $detail );
		$attempt_id = $attempt['id'] ?? null;
		if ( ! is_string( $attempt_id )
			|| ( 'migrated' === $mode ? '' !== $attempt_id : ! hash_equals( $attempt_id, $mtn ) )
			|| ( $attempt['generation'] ?? null ) !== ( $claim['attempt_generation'] ?? null )
			|| ( $attempt['nonce'] ?? null ) !== ( $claim['attempt_nonce'] ?? null )
			|| ( $record['token'] ?? null ) !== ( $claim['dispatch_token'] ?? null ) ) {
			return false;
		}

		return self::modern_dispatch_matches(
			$detail,
			(int) ( $claim['order_id'] ?? 0 ),
			(string) ( $claim['gateway_id'] ?? '' ),
			(string) ( $claim['operation_key'] ?? '' ),
			(string) ( $claim['dispatch_token'] ?? '' ),
			[ YSPaymentDispatch::STATE_SUBMITTED, YSPaymentDispatch::STATE_INDETERMINATE, YSPaymentDispatch::STATE_TERMINAL ]
		) && self::callback_action_allows_record( $detail, (string) ( $claim['action'] ?? '' ) );
	}

	/** @param list<string> $states */
	private static function modern_dispatch_matches(
		array $detail,
		int $order_id,
		string $gateway_id,
		string $operation_key,
		string $dispatch_token,
		array $states
	): bool {
		$attempt = YSPaymentAttempt::current( $detail );
		$record  = YSPaymentDispatch::current( $detail );
		$state = YSPaymentDispatch::state( $detail );
		if ( [] === $attempt || [] === $record
			|| 'ecpay' !== ( $attempt['provider'] ?? null )
			|| $gateway_id !== ( $attempt['gateway_id'] ?? null )
			|| $gateway_id !== ( $record['gateway_id'] ?? null )
			|| ! is_string( $attempt['id'] ?? null )
			|| ( $attempt['generation'] ?? null ) !== ( $record['attempt_generation'] ?? null )
			|| ( $attempt['nonce'] ?? null ) !== ( $record['attempt_nonce'] ?? null )
			|| ! is_int( $attempt['generation'] ?? null )
			|| $attempt['generation'] <= 0
			|| ! is_string( $attempt['nonce'] ?? null )
			|| 1 !== preg_match( '/^[a-f0-9]{32}$/D', $attempt['nonce'] )
			|| ! is_string( $attempt['started_at'] ?? null )
			|| 1 !== preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $attempt['started_at'] )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $operation_key )
			|| ! hash_equals( $operation_key, (string) ( $record['operation_key'] ?? '' ) )
			|| 1 !== preg_match( '/^[a-f0-9]{32}$/D', $dispatch_token )
			|| ! hash_equals( $dispatch_token, (string) ( $record['token'] ?? '' ) )
			|| ! is_int( $record['started_at'] ?? null )
			|| $record['started_at'] <= 0
			|| ! is_int( $record['expires_at'] ?? null )
			|| $record['expires_at'] < $record['started_at']
			|| ! in_array( $record['state'] ?? null, [ YSPaymentDispatch::STATE_RESERVED, YSPaymentDispatch::STATE_SUBMITTED, YSPaymentDispatch::STATE_INDETERMINATE, YSPaymentDispatch::STATE_TERMINAL ], true )
			|| ! in_array( $state, $states, true ) ) {
			return false;
		}
		if ( YSPaymentDispatch::STATE_RESERVED !== $state
			&& ( ! is_int( $record['submitted_at'] ?? null ) || $record['submitted_at'] <= 0 ) ) {
			return false;
		}
		if ( YSPaymentDispatch::STATE_TERMINAL === $state
			&& ( ! is_int( $record['ended_at'] ?? null )
				|| $record['ended_at'] <= 0
				|| ! in_array( $record['result'] ?? null, [ 'success', 'failed' ], true ) ) ) {
			return false;
		}
		return $order_id <= 0 || hash_equals( YSPaymentDispatch::operation_key( $order_id, $attempt ), $operation_key );
	}

	private static function core_available(): bool {
		return class_exists( YSPaymentAttempt::class )
			&& class_exists( YSPaymentDispatch::class )
			&& method_exists( YSPaymentDispatch::class, 'current_operation_key' )
			&& method_exists( YSPaymentDispatch::class, 'current_token' )
			&& class_exists( YSPaymentDetailStore::class );
	}

	private static function canonical_gateway( string $gateway_id ): bool {
		return 1 === preg_match( '/^ys_ec_ecpay_[a-z0-9_]{1,80}$/D', $gateway_id );
	}

	private static function canonical_merchant_trade_no( string $value ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9]{1,20}$/D', $value );
	}

	private static function root_aliases_match( array $detail, string $value, bool $require_both = false ): bool {
		$found = 0;
		foreach ( [ 'mer_trade_no', 'ecpay_merchant_trade_no' ] as $key ) {
			if ( ! array_key_exists( $key, $detail ) ) {
				continue;
			}
			if ( ! is_string( $detail[ $key ] ) || ! hash_equals( $detail[ $key ], $value ) ) {
				return false;
			}
			++$found;
		}
		return $require_both ? 2 === $found : $found > 0;
	}

	private static function optional_exact( array $detail, string $key, string $value ): bool {
		return ! array_key_exists( $key, $detail )
			|| ( is_string( $detail[ $key ] ) && hash_equals( $detail[ $key ], $value ) );
	}

	private static function required_exact( array $detail, string $key, string $value ): bool {
		return array_key_exists( $key, $detail )
			&& is_string( $detail[ $key ] )
			&& hash_equals( $detail[ $key ], $value );
	}

	private static function callback_action_allows_record( array $detail, string $action ): bool {
		$state = YSPaymentDispatch::state( $detail );
		if ( in_array( $state, [ YSPaymentDispatch::STATE_SUBMITTED, YSPaymentDispatch::STATE_INDETERMINATE ], true ) ) {
			return true;
		}
		if ( YSPaymentDispatch::STATE_TERMINAL !== $state ) {
			return false;
		}
		$result = (string) ( YSPaymentDispatch::current( $detail )['result'] ?? '' );
		return ( self::ACTION_SUCCESS === $action && 'success' === $result )
			|| ( self::ACTION_FAILURE === $action && 'failed' === $result );
	}

	/** @return list<array<string,mixed>> */
	private static function history_entries( array $detail ): array {
		$history = $detail[ YSPaymentAttempt::HISTORY_KEY ] ?? null;
		return is_array( $history ) && array_is_list( $history )
			? array_slice( $history, -10 )
			: [];
	}

	private static function legacy_order_amount( object $order ): int {
		$value = $order->total ?? null;
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : 0;
		}
		if ( is_float( $value ) ) {
			return is_finite( $value ) && $value > 0 && floor( $value ) === $value ? (int) $value : 0;
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[0-9]+(?:\.0+)?$/D', $value ) ) {
			return 0;
		}
		$numeric = (float) $value;
		return is_finite( $numeric ) && $numeric > 0 && floor( $numeric ) === $numeric && $numeric <= PHP_INT_MAX
			? (int) $numeric
			: 0;
	}

	private static function fresh_gateway_row_matches( ?object $row, string $gateway_id ): bool {
		return is_object( $row )
			&& property_exists( $row, 'gateway_id' )
			&& property_exists( $row, 'payment_method' )
			&& is_string( $row->gateway_id )
			&& is_string( $row->payment_method )
			&& hash_equals( $row->gateway_id, $gateway_id )
			&& hash_equals( $row->payment_method, $gateway_id );
	}

	/** The signed ECPay amount is always a positive whole TWD amount. */
	private static function fresh_order_amount_matches( ?object $row, int $charged_amount ): bool {
		return is_object( $row )
			&& property_exists( $row, 'currency' )
			&& is_string( $row->currency )
			&& hash_equals( 'TWD', $row->currency )
			&& self::legacy_order_amount( $row ) === $charged_amount;
	}

	private static function fresh_legacy_gateway_row_matches( ?object $row, string $gateway_id ): bool {
		return is_object( $row )
			&& property_exists( $row, 'gateway_id' )
			&& property_exists( $row, 'payment_method' )
			&& null === $row->gateway_id
			&& is_string( $row->payment_method )
			&& hash_equals( $row->payment_method, $gateway_id );
	}

	/**
	 * Initial checkout persists payment_method before the provider owns the
	 * scalar gateway_id.  Only that exact method plus an empty/NULL gateway may
	 * be promoted by bind_payment_identity(); every later callback stays on the
	 * stricter fresh_gateway_row_matches() path.
	 */
	private static function fresh_gateway_row_can_bind( ?object $row, string $gateway_id ): bool {
		if ( ! is_object( $row )
			|| ! property_exists( $row, 'gateway_id' )
			|| ! property_exists( $row, 'payment_method' )
			|| ! is_string( $row->payment_method )
			|| ! hash_equals( $row->payment_method, $gateway_id ) ) {
			return false;
		}

		return null === $row->gateway_id
			|| ( is_string( $row->gateway_id )
				&& ( '' === $row->gateway_id || hash_equals( $row->gateway_id, $gateway_id ) ) );
	}

	private static function compatible_root_value( array $detail, string $key, string $value ): bool {
		return ! array_key_exists( $key, $detail )
			|| ( is_string( $detail[ $key ] ) && hash_equals( $detail[ $key ], $value ) );
	}

	private static function required_positive_amount( array $detail, string $key, int $value ): bool {
		return array_key_exists( $key, $detail ) && self::amount_equals( $detail[ $key ], $value );
	}

	private static function required_positive_amount_value( mixed $value ): bool {
		return ( is_int( $value ) || ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $value ) ) )
			&& (int) $value > 0;
	}

	private static function compatible_positive_amount( array $detail, string $key, int $value ): bool {
		return ! array_key_exists( $key, $detail ) || self::amount_equals( $detail[ $key ], $value );
	}

	private static function amount_equals( mixed $stored, int $expected ): bool {
		return $expected > 0
			&& ( is_int( $stored ) || ( is_string( $stored ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $stored ) ) )
			&& (int) $stored === $expected;
	}
}
