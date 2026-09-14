<?php
/**
 * v063 — actual Core Attempt/Dispatch/Store + actual ECPay attempt ownership.
 *
 * All provider I/O is absent. The fixture exercises the exact JSON/scalar CAS
 * boundary used by AIO and ECPG callbacks.
 */
declare(strict_types=1);

namespace {
	define( 'ABSPATH', __DIR__ );
	define( 'YS_ECOMMERCE_TABLE_PREFIX', 'ys_ec_' );
	function wp_json_encode( $value ) { return json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); }
	function current_time( string $type ): string { unset( $type ); return '2026-09-14 12:00:00'; }

	require_once __DIR__ . '/fixtures/payment_detail_wpdb_adapter.php';

	final class AttemptSeamWpdb extends PaymentDetailWpdbAdapter {
		public function __construct() {
			$this->columns = [
				'status'           => 'pending',
				'gateway_id'       => 'ys_ec_ecpay_credit',
				'payment_method'   => 'ys_ec_ecpay_credit',
				'gateway_trade_no' => '',
				'total'            => '100',
			];
		}

		public function query( string $sql ): int|false {
			if ( null !== $this->before_write ) {
				$before = $this->before_write;
				$this->before_write = null;
				$before( $this, $sql );
			}
			if ( preg_match( "/AND\\s+(?:BINARY\\s+)?status\\s*=\\s*(?:BINARY\\s+)?'([^']*)'/i", $sql, $status )
				&& $status[1] !== (string) ( $this->columns['status'] ?? '' ) ) {
				return 0;
			}
			foreach ( [ 'gateway_id', 'payment_method', 'gateway_trade_no' ] as $column ) {
				$current = $this->columns[ $column ] ?? null;
				if ( str_contains( $sql, "{$column} IS NULL" ) ) {
					if ( null !== $current ) { return 0; }
					continue;
				}
				if ( preg_match( "/AND BINARY {$column} = BINARY '((?:''|[^'])*)'/", $sql, $match )
					&& str_replace( "''", "'", $match[1] ) !== (string) $current ) {
					return 0;
				}
			}

			$result = parent::query( $sql );
			if ( 1 !== $result ) { return $result; }
			foreach ( [ 'gateway_id', 'payment_method', 'gateway_trade_no' ] as $column ) {
				if ( preg_match( "/(?:SET|,) {$column} = '((?:''|[^'])*)'(?:,| WHERE)/s", $sql, $match ) ) {
					$this->columns[ $column ] = str_replace( "''", "'", $match[1] );
				}
			}
			return $result;
		}
	}
}

namespace YangSheep\Ecommerce\Models {
	class YSOrder {
		public static function table(): string { return 'wp_ys_ec_orders'; }
		public static function forget( int $id ): void { unset( $id ); }
		public static function find( int $id ): ?object {
			global $wpdb;
			if ( false === $wpdb->value ) { return null; }
			return (object) [
				'id'               => $id,
				'status'           => (string) ( $wpdb->columns['status'] ?? '' ),
				'gateway_id'       => $wpdb->columns['gateway_id'] ?? null,
				'payment_method'   => $wpdb->columns['payment_method'] ?? null,
				'gateway_trade_no' => $wpdb->columns['gateway_trade_no'] ?? null,
				'total'            => $wpdb->columns['total'] ?? null,
				'payment_detail'   => $wpdb->value,
			];
		}
	}
}

namespace YangSheep\Ecommerce\Utils {
	class YSLogger {
		public static function error( string $channel, string $message, array $context = [] ): void { unset( $channel, $message, $context ); }
		public static function warning( string $channel, string $message, array $context = [] ): void { unset( $channel, $message, $context ); }
		public static function info( string $channel, string $message, array $context = [] ): void { unset( $channel, $message, $context ); }
	}
}

namespace YangSheep\YSCartEcpay\Support {
	final class Settings {
		public static array $credentials = [
			'merchant_id' => '3002607',
			'hash_key'    => 'fixture',
			'hash_iv'     => 'fixture',
			'test_mode'   => true,
		];
		public static function payment_credentials(): array {
			return self::$credentials;
		}
	}
}

namespace {
	$core = (string) getenv( 'YS_CORE_ROOT' );
	if ( '' === $core || ! is_file( $core . '/src/Services/Payment/YSPaymentAttempt.php' ) ) {
		fwrite( STDERR, "YS_CORE_ROOT must name the paired Core candidate.\n" );
		exit( 2 );
	}
	$provider = dirname( __DIR__, 2 );
	require_once $core . '/src/Services/Payment/YSPaymentDetailResult.php';
	require_once $core . '/src/Services/Payment/YSPaymentAttempt.php';
	require_once $core . '/src/Services/Payment/YSPaymentDispatch.php';
	require_once $core . '/src/Services/Payment/YSPaymentDetailStore.php';
	require_once $provider . '/src/Support/DetailWriteOutcome.php';
	require_once $provider . '/src/Support/OrderPaymentDetail.php';
	require_once $provider . '/src/Payment/EcpayPaymentAttempt.php';

	use YangSheep\Ecommerce\Services\Payment\YSPaymentAttempt as Attempt;
	use YangSheep\Ecommerce\Services\Payment\YSPaymentDetailResult as Result;
	use YangSheep\Ecommerce\Services\Payment\YSPaymentDetailStore as Store;
	use YangSheep\Ecommerce\Services\Payment\YSPaymentDispatch as Dispatch;
	use YangSheep\YSCartEcpay\Payment\EcpayPaymentAttempt as EcpayAttempt;
	use YangSheep\YSCartEcpay\Support\Settings;

	$gateway = 'ys_ec_ecpay_credit';
	$mtn1    = 'YS7TATTEMPTONE';
	$mtn2    = 'YS7TATTEMPTTWO';
	$merchant = '3002607';

	$pass = 0;
	$fail = 0;
	$check = static function ( bool $ok, string $label ) use ( &$pass, &$fail ): void {
		if ( $ok ) { ++$pass; echo "PASS {$label}\n"; return; }
		++$fail; echo "FAIL {$label}\n";
	};
	$seed = static function ( array $detail, ?string $scalar_gateway = null ) use ( $gateway ): AttemptSeamWpdb {
		global $wpdb;
		$wpdb = new AttemptSeamWpdb();
		$wpdb->value = wp_json_encode( $detail );
		$wpdb->columns['gateway_id'] = null === $scalar_gateway ? $gateway : $scalar_gateway;
		$wpdb->columns['payment_method'] = null === $scalar_gateway ? $gateway : $scalar_gateway;
		return $wpdb;
	};
	$make = static function ( string $mtn, string $state = Dispatch::STATE_SUBMITTED, bool $bound = true ) use ( $gateway, $merchant ): array {
		$detail  = Attempt::begin( [], 'ecpay', $gateway, [], $bound ? $mtn : '' );
		$made    = Dispatch::make( 7, Attempt::current( $detail ), $gateway );
		$record  = $made['record'];
		if ( Dispatch::STATE_RESERVED !== $state ) {
			$record['state'] = $state;
			$record['submitted_at'] = 1770000000;
		}
		if ( Dispatch::STATE_TERMINAL === $state ) {
			$record['result'] = 'success';
			$record['ended_at'] = 1770000010;
		}
		$detail[ Dispatch::KEY ] = $record;
		if ( $bound ) {
			$detail += [
				'mer_trade_no'           => $mtn,
				'ecpay_merchant_trade_no' => $mtn,
				'ecpay_operation_key'     => $made['operation_key'],
				'ecpay_charged_amount'    => 100,
				'ecpay_environment'       => 'stage',
				'ecpay_merchant_id'       => $merchant,
				'payment_provider'        => 'ecpay',
				'payment_method'          => $gateway,
			];
		}
		return [ $detail, $made ];
	};
	$order = static function () use ( $gateway ): object {
		return (object) [ 'id' => 7, 'status' => 'pending', 'gateway_id' => $gateway, 'payment_method' => $gateway, 'total' => '100' ];
	};

	// Real helper must bind at RESERVED, before provider I/O and submitted_at.
	[ $reserved, $made ] = $make( $mtn1, Dispatch::STATE_RESERVED, false );
	$db = $seed( $reserved );
	$bound = Dispatch::with_context(
		$made['token'],
		7,
		$made['operation_key'],
		static fn () => EcpayAttempt::bind_payment_identity( 7, $gateway, $mtn1, 100, 'stage', $merchant )
	);
	$bound_detail = json_decode( (string) $db->value, true );
	$check(
		$bound->is_persisted()
		&& $mtn1 === ( $bound_detail[ Attempt::KEY ]['id'] ?? null )
		&& $mtn1 === ( $bound_detail['ecpay_merchant_trade_no'] ?? null )
		&& 1 === $db->updates,
		'R1 actual reserved dispatch binds attempt/provider identity in one Store CAS'
	);

	// Core's real checkout builder writes payment_method first; gateway_id is
	// nullable and is owned by the provider identity bind itself.
	foreach ( [ 'NULL' => null, 'empty' => '' ] as $shape => $initial_gateway ) {
		[ $initial_detail, $initial_made ] = $make( $mtn1, Dispatch::STATE_RESERVED, false );
		$db = $seed( $initial_detail );
		$db->columns['gateway_id'] = $initial_gateway;
		$initial_bind = Dispatch::with_context(
			$initial_made['token'],
			7,
			$initial_made['operation_key'],
			static fn () => EcpayAttempt::bind_payment_identity( 7, $gateway, $mtn1, 100, 'stage', $merchant )
		);
		$initial_bound_detail = json_decode( (string) $db->value, true );
		$check(
			$initial_bind->is_persisted()
				&& $gateway === ( $db->columns['gateway_id'] ?? null )
				&& $gateway === ( $db->columns['payment_method'] ?? null )
				&& $mtn1 === ( $initial_bound_detail[ Attempt::KEY ]['id'] ?? null )
				&& 1 === $db->updates,
			'R1a initial ' . $shape . ' gateway is atomically bound from the matching payment_method'
		);
	}

	foreach ( [
		'foreign gateway' => [ 'ys_ec_payuni_credit', $gateway ],
		'foreign method'  => [ null, 'ys_ec_payuni_credit' ],
	] as $shape => [ $initial_gateway, $initial_method ] ) {
		[ $initial_detail, $initial_made ] = $make( $mtn1, Dispatch::STATE_RESERVED, false );
		$db = $seed( $initial_detail );
		$db->columns['gateway_id'] = $initial_gateway;
		$db->columns['payment_method'] = $initial_method;
		$initial_bind = Dispatch::with_context(
			$initial_made['token'],
			7,
			$initial_made['operation_key'],
			static fn () => EcpayAttempt::bind_payment_identity( 7, $gateway, $mtn1, 100, 'stage', $merchant )
		);
		$check(
			! $initial_bind->is_persisted()
				&& $initial_gateway === ( $db->columns['gateway_id'] ?? null )
				&& $initial_method === ( $db->columns['payment_method'] ?? null )
				&& 0 === $db->updates,
			'R1b ' . $shape . ' cannot be taken over by initial identity bind'
		);
	}

	[ $rotating_detail, $rotating_made ] = $make( $mtn1, Dispatch::STATE_RESERVED, false );
	$db = $seed( $rotating_detail );
	$db->columns['gateway_id'] = null;
	$successor = Attempt::begin( $rotating_detail, 'ecpay', $gateway, [], '' );
	$successor_made = Dispatch::make( 7, Attempt::current( $successor ), $gateway );
	$successor[ Dispatch::KEY ] = $successor_made['record'];
	$successor_bytes = wp_json_encode( $successor );
	$db->before_write = static function ( AttemptSeamWpdb $store, string $sql ) use ( $successor_bytes ): void {
		unset( $sql );
		$store->value = $successor_bytes;
	};
	$rotated_bind = Dispatch::with_context(
		$rotating_made['token'],
		7,
		$rotating_made['operation_key'],
		static fn () => EcpayAttempt::bind_payment_identity( 7, $gateway, $mtn1, 100, 'stage', $merchant )
	);
	$check(
		! $rotated_bind->is_persisted()
			&& $successor_bytes === $db->value
			&& null === ( $db->columns['gateway_id'] ?? null )
			&& 1 === $db->updates,
		'R1c attempt rotation before the gateway bind CAS preserves the successor byte-exact'
	);

	$malformed = $reserved;
	unset( $malformed[ Dispatch::KEY ]['expires_at'] );
	$db = $seed( $malformed );
	$bad_bind = Dispatch::with_context(
		$made['token'], 7, $made['operation_key'],
		static fn () => EcpayAttempt::bind_payment_identity( 7, $gateway, $mtn1, 100, 'stage', $merchant )
	);
	$check( ! $bad_bind->is_persisted() && 0 === $db->updates, 'R2 malformed reserved evidence grants zero bind authority' );

	[ $browser_detail ] = $make( $mtn1, Dispatch::STATE_SUBMITTED, true );
	$browser_detail['ecpay_ecpg_flow'] = 'pay';
	$db = $seed( $browser_detail );
	$browser_fingerprint = EcpayAttempt::browser_fingerprint( $browser_detail );
	Settings::$credentials = [
		'merchant_id' => 'NEW-MERCHANT',
		'hash_key'    => 'new-key',
		'hash_iv'     => 'new-iv',
		'test_mode'   => false,
	];
	$check(
		! EcpayAttempt::browser_owner_is_current( 7, $gateway, $mtn1, $browser_fingerprint )
			&& EcpayAttempt::browser_owner_is_current( 7, $gateway, $mtn1, $browser_fingerprint, [
				'merchant_id' => $merchant,
				'environment' => 'stage',
			] ),
		'R2a post-provider owner check uses the verified credential snapshot while a fresh pre-I/O check follows current settings'
	);
	Settings::$credentials = [
		'merchant_id' => $merchant,
		'hash_key'    => 'fixture',
		'hash_iv'     => 'fixture',
		'test_mode'   => true,
	];

	$history_keys = EcpayAttempt::contribute_history_keys( [], $gateway, $gateway, 7 );
	$check(
		in_array( 'ecpay_environment', $history_keys, true )
		&& in_array( 'gwsr', $history_keys, true )
		&& ! in_array( 'ecpay_browser_authorization', $history_keys, true )
		&& [] === EcpayAttempt::contribute_history_keys( [], 'ys_ec_payuni_credit', $gateway, 7 ),
		'R3 provider hook archives only bounded ECPay-owned reconciliation facts'
	);

	[ $old ] = $make( $mtn1 );
	$old_order = $order();
	$claims = [
		EcpayAttempt::callback_claim( $old_order, $old, $mtn1, 100, $merchant, 'stage', EcpayAttempt::ACTION_SUCCESS ),
		EcpayAttempt::callback_claim( $old_order, $old, $mtn1, 100, $merchant, 'stage', EcpayAttempt::ACTION_FAILURE ),
		EcpayAttempt::callback_claim( $old_order, $old, $mtn1, 100, $merchant, 'stage', EcpayAttempt::ACTION_PAYMENT_INFO ),
	];
	$next = Attempt::begin( $old, 'ecpay', $gateway, $history_keys, '' );
	$next_made = Dispatch::make( 7, Attempt::current( $next ), $gateway );
	$next[ Dispatch::KEY ] = $next_made['record'];
	$next_bytes = wp_json_encode( $next );
	$check(
		! in_array( null, $claims, true )
		&& EcpayAttempt::identity_is_discoverable( $next, $mtn1 )
		&& EcpayAttempt::historical_callback_matches( $old_order, $next, $mtn1, 100, $merchant, 'stage' ),
		'R4 old success/failure/payment-info identities remain discoverable only as archived facts'
	);
	$zero_writes = true;
	foreach ( $claims as $claim ) {
		$db = $seed( $next );
		$guard = EcpayAttempt::callback_guard( $claim );
		$patch = EcpayAttempt::callback_detail_patch( $claim, [ 'gwsr' => 'OLD' ] );
		$options = EcpayAttempt::callback_lifecycle_options( $claim, $patch, [ 'gateway_trade_no' => 'OLD-TRADE' ] );
		$result = Store::mutate(
			7,
			static function ( array $detail, int $attempt, &$decision ) use ( $guard, $patch ): ?array {
				unset( $attempt );
				if ( ! $guard( $detail ) ) { $decision = 'stale'; return null; }
				return $patch( $detail );
			},
			5,
			true,
			$options['columns'] ?? [],
			'pending',
			array_keys( $options['expected_column_values'] ?? [] )
		);
		$zero_writes = $zero_writes
			&& Result::ABORTED === $result->get_outcome()
			&& $next_bytes === $db->value
			&& '' === (string) $db->columns['gateway_trade_no'];
	}
	$check( $zero_writes, 'R5 rotation-before-bind old success/failure/ATM perform zero detail or scalar writes' );

	// Lookup/claim happens first; rotation lands immediately before the callback UPDATE.
	$current = $old;
	$current_claim = $claims[0];
	$db = $seed( $current );
	$db->before_write = static function ( AttemptSeamWpdb $race ) use ( $next_bytes ): void { $race->value = $next_bytes; };
	$guard = EcpayAttempt::callback_guard( $current_claim );
	$patch = EcpayAttempt::callback_detail_patch( $current_claim, [ 'gwsr' => 'LOSER' ] );
	$options = EcpayAttempt::callback_lifecycle_options( $current_claim, $patch, [ 'gateway_trade_no' => 'LOSER-TRADE' ] );
	$race_result = Store::mutate(
		7,
		static function ( array $detail, int $attempt, &$decision ) use ( $guard, $patch ): ?array {
			unset( $attempt );
			if ( ! $guard( $detail ) ) { $decision = 'stale'; return null; }
			return $patch( $detail );
		},
		5,
		true,
		$options['columns'],
		'pending',
		array_keys( $options['expected_column_values'] )
	);
	$check(
		Result::ABORTED === $race_result->get_outcome()
		&& $next_bytes === $db->value
		&& '' === (string) $db->columns['gateway_trade_no'],
		'R6 lookup-before-rotation/write-after loses CAS and preserves successor byte-exact'
	);

	[ $current ] = $make( $mtn2 );
	$current_order = $order();
	$success = EcpayAttempt::callback_claim( $current_order, $current, $mtn2, 100, $merchant, 'stage', EcpayAttempt::ACTION_SUCCESS );
	$failure = EcpayAttempt::callback_claim( $current_order, $current, $mtn2, 100, $merchant, 'stage', EcpayAttempt::ACTION_FAILURE );
	$check( is_array( $success ) && is_array( $failure ), 'R7 exact current success and failure callbacks own submitted attempt' );
	$success_options = EcpayAttempt::callback_lifecycle_options( $success, EcpayAttempt::callback_detail_patch( $success ), [ 'gateway_trade_no' => 'NEW-TRADE' ] );
	$check(
		$gateway === ( $success_options['columns']['gateway_id'] ?? null )
		&& $gateway === ( $success_options['expected_column_values']['gateway_id'] ?? null ),
		'R8 callback lifecycle options pin scalar gateway in the same CAS'
	);

	$terminal_success = $current;
	$terminal_success[ Dispatch::KEY ]['state'] = Dispatch::STATE_TERMINAL;
	$terminal_success[ Dispatch::KEY ]['result'] = 'success';
	$terminal_success[ Dispatch::KEY ]['ended_at'] = 1770000010;
	$terminal_failed = $terminal_success;
	$terminal_failed[ Dispatch::KEY ]['result'] = 'failed';
	$check(
		is_array( EcpayAttempt::callback_claim( $current_order, $terminal_success, $mtn2, 100, $merchant, 'stage', EcpayAttempt::ACTION_SUCCESS ) )
		&& null === EcpayAttempt::callback_claim( $current_order, $terminal_success, $mtn2, 100, $merchant, 'stage', EcpayAttempt::ACTION_FAILURE )
		&& is_array( EcpayAttempt::callback_claim( $current_order, $terminal_failed, $mtn2, 100, $merchant, 'stage', EcpayAttempt::ACTION_FAILURE ) )
		&& null === EcpayAttempt::callback_claim( $current_order, $terminal_failed, $mtn2, 100, $merchant, 'stage', EcpayAttempt::ACTION_SUCCESS )
		&& null === EcpayAttempt::callback_claim( $current_order, $terminal_success, $mtn2, 100, $merchant, 'stage', EcpayAttempt::ACTION_PAYMENT_INFO ),
		'R9 exact duplicates are idempotent only for their own terminal result'
	);

	$migrated = $current;
	$migrated[ Attempt::KEY ]['id'] = '';
	$migrated_claim = EcpayAttempt::callback_claim( $current_order, $migrated, $mtn2, 100, $merchant, 'stage', EcpayAttempt::ACTION_SUCCESS );
	$migrated_patch = is_array( $migrated_claim ) ? EcpayAttempt::callback_detail_patch( $migrated_claim ) : null;
	$migrated_next = is_callable( $migrated_patch ) ? $migrated_patch( $migrated ) : [];
	$check(
		'migrated' === ( $migrated_claim['mode'] ?? null )
		&& $mtn2 === ( $migrated_next[ Attempt::KEY ]['id'] ?? null ),
		'R10 submitted migrated payment binds its empty attempt id exactly once'
	);

	$legacy_detail = [ 'mer_trade_no' => 'YS7TLEGACY', 'payment_provider' => 'ecpay', 'payment_method' => $gateway ];
	$legacy_order = (object) [ 'id' => 7, 'status' => 'pending', 'gateway_id' => null, 'payment_method' => $gateway, 'total' => '100' ];
	$legacy_claim = EcpayAttempt::callback_claim( $legacy_order, $legacy_detail, 'YS7TLEGACY', 100, $merchant, 'stage', EcpayAttempt::ACTION_SUCCESS );
	$legacy_options = is_array( $legacy_claim )
		? EcpayAttempt::callback_lifecycle_options( $legacy_claim, EcpayAttempt::callback_detail_patch( $legacy_claim ) )
		: [];
	$check(
		'legacy' === ( $legacy_claim['mode'] ?? null )
		&& ! isset( $legacy_options['expected_column_values'] )
		&& ! isset( $legacy_options['columns']['gateway_id'] ),
		'R11 bounded pre-attempt legacy identity keeps detail-only compatibility when scalar gateway is NULL'
	);
	$foreign_scalar_order = (object) [
		'id'             => 7,
		'status'         => 'pending',
		'gateway_id'     => 'ys_ec_payuni_credit',
		'payment_method' => $gateway,
		'total'          => '100',
	];
	$split_scalar_order = (object) [
		'id'             => 7,
		'status'         => 'pending',
		'gateway_id'     => $gateway,
		'payment_method' => 'ys_ec_ecpay_atm',
		'total'          => '100',
	];
	$check(
		null === EcpayAttempt::callback_claim( $foreign_scalar_order, $legacy_detail, 'YS7TLEGACY', 100, $merchant, 'stage', EcpayAttempt::ACTION_SUCCESS )
		&& null === EcpayAttempt::callback_claim( $split_scalar_order, $legacy_detail, 'YS7TLEGACY', 100, $merchant, 'stage', EcpayAttempt::ACTION_SUCCESS ),
		'R11b non-empty foreign or split scalar gateway never falls back to legacy ECPay detail authority'
	);

	$current['ecpay_ecpg_flow'] = 'pay';
	$fingerprint = EcpayAttempt::browser_fingerprint( $current );
	$check(
		EcpayAttempt::browser_owner_matches( $current, 7, $gateway, $mtn2, $fingerprint )
		&& ! EcpayAttempt::browser_owner_matches( $next, 7, $gateway, $mtn2, $fingerprint ),
		'R12 superseded hosted attempt cannot yield a payable form while exact current remains valid'
	);

	$bad_env = $current;
	$bad_env['ecpay_environment'] = 'live';
	$check(
		null === EcpayAttempt::callback_claim( $current_order, $bad_env, $mtn2, 100, $merchant, 'stage', EcpayAttempt::ACTION_SUCCESS )
		&& null === EcpayAttempt::callback_claim( $current_order, $current, $mtn2, 101, $merchant, 'stage', EcpayAttempt::ACTION_SUCCESS )
		&& null === EcpayAttempt::callback_claim( $current_order, $current, $mtn2, 100, 'OTHER', 'stage', EcpayAttempt::ACTION_SUCCESS ),
		'R13 amount, environment and merchant mismatches fail closed'
	);

	// A hosted provider response must win one final Core Store CAS before its 3D
	// URL is returned. The CAS pins both the sent browser authorization and the
	// provider-reported identity to the exact current modern attempt.
	$db = $seed( $current );
	$claim_nonce = EcpayAttempt::claim_browser_authorization( 7, $gateway, $mtn2, $fingerprint, 'confirm' );
	$claimed = json_decode( (string) $db->value, true );
	$sent = EcpayAttempt::authorize_browser_send( 7, $gateway, $mtn2, $fingerprint, $claim_nonce );
	$hosted = json_decode( (string) $db->value, true );
	$check(
		1 === preg_match( '/^[a-f0-9]{32}$/D', $claim_nonce )
		&& 'reserved' === ( $claimed[ Dispatch::KEY ]['ecpay_browser_authorization']['state'] ?? null )
		&& $sent
		&& 'sent' === ( $hosted[ Dispatch::KEY ]['ecpay_browser_authorization']['state'] ?? null )
		&& 2 === $db->updates,
		'R14a browser authorization is reserved and promoted to durable sent intent through exact Store CAS'
	);
	$handoff = EcpayAttempt::claim_browser_result_handoff(
		7, $gateway, $mtn2, $fingerprint, $claim_nonce, $merchant, 'stage', $merchant, $mtn2, 100
	);
	$handed_off = json_decode( (string) $db->value, true );
	$authorization = $handed_off[ Dispatch::KEY ]['ecpay_browser_authorization'] ?? [];
	$check(
		$handoff
		&& 'handed_off' === ( $authorization['state'] ?? null )
		&& 1 === preg_match( '/^[a-f0-9]{32}$/D', (string) ( $authorization['handoff_nonce'] ?? '' ) )
		&& 3 === $db->updates,
		'R14 exact modern sent authorization atomically claims one hosted-result handoff'
	);
	$handed_off_bytes = (string) $db->value;
	$duplicate = EcpayAttempt::claim_browser_result_handoff(
		7, $gateway, $mtn2, $fingerprint, $claim_nonce, $merchant, 'stage', $merchant, $mtn2, 100
	);
	$check(
		! $duplicate && 3 === $db->updates && $handed_off_bytes === $db->value,
		'R15 hosted-result handoff is one-time and duplicate delivery writes nothing'
	);

	$identity_mismatches_rejected = true;
	foreach ( [
		[ 'OTHER', $mtn2, 100 ],
		[ $merchant, 'YS7TWRONGRESULT', 100 ],
		[ $merchant, $mtn2, 101 ],
	] as [ $reported_merchant, $reported_mtn, $reported_amount ] ) {
		$db = $seed( $hosted );
		$before = (string) $db->value;
		$identity_mismatches_rejected = $identity_mismatches_rejected
			&& ! EcpayAttempt::claim_browser_result_handoff(
				7,
				$gateway,
				$mtn2,
				$fingerprint,
				$claim_nonce,
				$merchant,
				'stage',
				$reported_merchant,
				$reported_mtn,
				$reported_amount
			)
			&& 0 === $db->updates
			&& $before === $db->value;
	}
	$check(
		$identity_mismatches_rejected,
		'R16 wrong provider merchant, MerchantTradeNo or amount cannot expose a hosted result or write detail'
	);

	$legacy_hosted = $legacy_detail + [
		'ecpay_ecpg_flow' => 'pay',
	];
	$db = $seed( $legacy_hosted, '' );
	$legacy_before = (string) $db->value;
	$check(
		! EcpayAttempt::claim_browser_result_handoff(
			7,
			$gateway,
			'YS7TLEGACY',
			str_repeat( 'b', 32 ),
			$claim_nonce,
			$merchant,
			'stage',
			$merchant,
			'YS7TLEGACY',
			100
		)
		&& 0 === $db->updates
		&& $legacy_before === $db->value,
		'R17 pre-attempt legacy rows never gain hosted 3D handoff authority'
	);

	$terminal_race = $hosted;
	$terminal_race[ Dispatch::KEY ]['state']    = Dispatch::STATE_TERMINAL;
	$terminal_race[ Dispatch::KEY ]['result']   = 'success';
	$terminal_race[ Dispatch::KEY ]['ended_at'] = 1770000010;
	$terminal_bytes = wp_json_encode( $terminal_race );
	$db = $seed( $hosted );
	$db->before_write = static function ( AttemptSeamWpdb $race ) use ( $terminal_bytes ): void {
		$race->value = $terminal_bytes;
	};
	$check(
		! EcpayAttempt::claim_browser_result_handoff(
			7, $gateway, $mtn2, $fingerprint, $claim_nonce, $merchant, 'stage', $merchant, $mtn2, 100
		)
		&& 1 === $db->updates
		&& $terminal_bytes === $db->value,
		'R18 callback terminalization between hosted check and write wins; no stale handoff survives CAS'
	);

	echo "\nattempt callback seam pair: {$pass} PASS / {$fail} FAIL\n";
	exit( $fail > 0 ? 1 : 0 );
}
