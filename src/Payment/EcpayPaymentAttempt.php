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
			[ 'gateway_id', 'payment_method' ]
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

	/** Discovery only: current root or a bounded retired attempt carries this MTN. */
	public static function identity_is_discoverable( array $detail, string $merchant_trade_no ): bool {
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
			[ 'gateway_id', 'payment_method' ]
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
		string $fingerprint
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
		);
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
			return self::browser_owner_matches( $detail, $order_id, $gateway_id, $merchant_trade_no, $fingerprint );
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
			);
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
					|| ! self::browser_owner_matches( $detail, $order_id, $gateway_id, $merchant_trade_no, $fingerprint ) ) {
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
			[ 'gateway_id', 'payment_method' ]
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
					|| ! self::browser_owner_matches( $detail, $order_id, $gateway_id, $merchant_trade_no, $fingerprint ) ) {
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
			[ 'gateway_id', 'payment_method' ]
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
					) ) {
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
			[ 'gateway_id', 'payment_method' ]
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
