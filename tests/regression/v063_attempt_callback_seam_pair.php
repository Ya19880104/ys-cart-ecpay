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
				'currency'         => 'TWD',
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
			foreach ( [ 'gateway_id', 'payment_method', 'gateway_trade_no', 'currency' ] as $column ) {
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
			$current_total = $this->columns['total'] ?? null;
			if ( str_contains( $sql, 'total IS NULL' ) ) {
				if ( null !== $current_total ) { return 0; }
			} elseif ( preg_match( "/AND total = '((?:''|[^'])*)'/", $sql, $match )
				&& str_replace( "''", "'", $match[1] ) !== (string) $current_total ) {
				return 0;
			}

			$result = parent::query( $sql );
			if ( 1 !== $result ) { return $result; }
			foreach ( [ 'gateway_id', 'payment_method', 'gateway_trade_no', 'currency' ] as $column ) {
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
				'currency'         => $wpdb->columns['currency'] ?? null,
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
	require_once $provider . '/src/Payment/EcpayPaymentCatalog.php';
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
		return (object) [
			'id' => 7, 'status' => 'pending', 'gateway_id' => $gateway,
			'payment_method' => $gateway, 'total' => '100', 'currency' => 'TWD',
		];
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

	foreach ( [ 'total' => '101', 'currency' => 'USD' ] as $drift_column => $drift_value ) {
		$db = $seed( $reserved );
		$db->columns['gateway_id'] = null;
		$db->before_write = static function ( AttemptSeamWpdb $store, string $sql ) use ( $drift_column, $drift_value ): void {
			unset( $sql );
			$store->columns[ $drift_column ] = $drift_value;
		};
		$drifted_bind = Dispatch::with_context(
			$made['token'], 7, $made['operation_key'],
			static fn () => EcpayAttempt::bind_payment_identity( 7, $gateway, $mtn1, 100, 'stage', $merchant )
		);
		$after_drift = json_decode( (string) $db->value, true );
		$check(
			! $drifted_bind->is_persisted()
				&& $drift_value === ( $db->columns[ $drift_column ] ?? null )
				&& ! array_key_exists( 'ecpay_charged_amount', $after_drift )
				&& null === ( $db->columns['gateway_id'] ?? null )
				&& 0 === $db->updates,
			'R1d ' . $drift_column . ' drift at the bind CAS cannot persist or expose a stale signed amount'
		);
	}

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
			static function ( array $detail, int $attempt, &$decision, ?object $fresh_row = null ) use ( $guard, $patch ): ?array {
				unset( $attempt );
				if ( ! $guard( $detail, $fresh_row ) ) { $decision = 'stale'; return null; }
				return $patch( $detail );
			},
			5,
			true,
			$options['columns'] ?? [],
			'pending',
			array_values( array_unique( array_merge(
				[ 'gateway_id', 'payment_method', 'gateway_trade_no' ],
				array_keys( $options['expected_column_values'] ?? [] )
			) ) )
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
	$exact_fresh = (object) [
		'gateway_id'       => $gateway,
		'payment_method'   => $gateway,
		'gateway_trade_no' => '',
	];
	$check(
		$guard( $current, $exact_fresh )
		&& ! $guard( $current, (object) [ 'gateway_id' => $gateway, 'payment_method' => $gateway . ' ', 'gateway_trade_no' => '' ] )
		&& ! $guard( $current, (object) [ 'gateway_id' => $gateway, 'payment_method' => 'ys_ec_payuni_credit', 'gateway_trade_no' => '' ] )
		&& ! $guard( $current, (object) [ 'gateway_id' => strtoupper( $gateway ), 'payment_method' => $gateway, 'gateway_trade_no' => '' ] )
		&& ! $guard( $current ),
		'R6a modern callback guard rechecks both fresh scalar gateway owners byte-exact on every CAS attempt'
	);
	$patch = EcpayAttempt::callback_detail_patch( $current_claim, [ 'gwsr' => 'LOSER' ] );
	$options = EcpayAttempt::callback_lifecycle_options( $current_claim, $patch, [ 'gateway_trade_no' => 'LOSER-TRADE' ] );
	$race_result = Store::mutate(
		7,
		static function ( array $detail, int $attempt, &$decision, ?object $fresh_row = null ) use ( $guard, $patch ): ?array {
			unset( $attempt );
			if ( ! $guard( $detail, $fresh_row ) ) { $decision = 'stale'; return null; }
			return $patch( $detail );
		},
		5,
		true,
		$options['columns'],
		'pending',
		array_values( array_unique( array_merge(
			[ 'gateway_id', 'payment_method', 'gateway_trade_no' ],
			array_keys( $options['expected_column_values'] )
		) ) )
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
	$hosted_gateway = 'ys_ec_ecpay_ecpg_credit';
	$hosted_legacy = Attempt::begin( [], 'ecpay', $hosted_gateway, [], '' );
	$hosted_legacy_made = Dispatch::make( 7, Attempt::current( $hosted_legacy ), $hosted_gateway );
	$hosted_legacy_record = $hosted_legacy_made['record'];
	$hosted_legacy_record['state'] = Dispatch::STATE_SUBMITTED;
	$hosted_legacy_record['submitted_at'] = 1770000000;
	$hosted_legacy[ Dispatch::KEY ] = $hosted_legacy_record;
	$hosted_legacy += [
		'mer_trade_no' => $mtn2,
		'ecpay_merchant_trade_no' => $mtn2,
		'ecpay_operation_key' => $hosted_legacy_made['operation_key'],
		'ecpay_charged_amount' => 100,
		'ecpay_environment' => 'stage',
		'ecpay_merchant_id' => $merchant,
		'payment_provider' => 'ecpay',
		'payment_method' => $hosted_gateway,
		'ecpay_ecpg_flow' => 'pay',
	];
	$db = $seed( $hosted_legacy, $hosted_gateway );
	$hosted_migration = EcpayAttempt::migrate_hosted_identity( 7, $hosted_gateway );
	$hosted_migrated_detail = json_decode( (string) $db->value, true );
	$check(
		$mtn2 === ( $hosted_migration['merchant_trade_no'] ?? null )
			&& 1 === preg_match( '/^[a-f0-9]{32}$/D', (string) ( $hosted_migration['fingerprint'] ?? '' ) )
			&& $mtn2 === ( $hosted_migrated_detail[ Attempt::KEY ]['id'] ?? null ),
		'R10a 0.5.9 hosted identity migration remains available for an exact unchanged TWD order'
	);
	foreach ( [ 'total' => '101', 'currency' => 'USD' ] as $drift_column => $drift_value ) {
		$db = $seed( $hosted_legacy, $hosted_gateway );
		$db->before_write = static function ( AttemptSeamWpdb $store, string $sql ) use ( $drift_column, $drift_value ): void {
			unset( $sql );
			$store->columns[ $drift_column ] = $drift_value;
		};
		$blocked_migration = EcpayAttempt::migrate_hosted_identity( 7, $hosted_gateway );
		$blocked_migration_detail = json_decode( (string) $db->value, true );
		$check(
			null === $blocked_migration
				&& '' === ( $blocked_migration_detail[ Attempt::KEY ]['id'] ?? null )
				&& 0 === $db->updates,
			'R10b hosted migration loses a concurrent ' . $drift_column . ' change without granting page authority'
		);
	}

	$legacy_detail = [ 'mer_trade_no' => 'YS7TLEGACY', 'payment_provider' => 'ecpay', 'payment_method' => $gateway ];
	$legacy_order = (object) [ 'id' => 7, 'status' => 'pending', 'gateway_id' => null, 'payment_method' => $gateway, 'total' => '100' ];
	$legacy_claim = EcpayAttempt::callback_claim( $legacy_order, $legacy_detail, 'YS7TLEGACY', 100, $merchant, 'stage', EcpayAttempt::ACTION_SUCCESS );
	$legacy_options = is_array( $legacy_claim )
		? EcpayAttempt::callback_lifecycle_options( $legacy_claim, EcpayAttempt::callback_detail_patch( $legacy_claim ) )
		: [];
	$legacy_guard = is_array( $legacy_claim ) ? EcpayAttempt::callback_guard( $legacy_claim ) : null;
	$check(
		'legacy' === ( $legacy_claim['mode'] ?? null )
		&& ! isset( $legacy_options['expected_column_values'] )
		&& ! isset( $legacy_options['columns']['gateway_id'] )
		&& is_callable( $legacy_guard )
		&& $legacy_guard( $legacy_detail, (object) [ 'gateway_id' => null, 'payment_method' => $gateway ] )
		&& ! $legacy_guard( $legacy_detail, (object) [ 'gateway_id' => '', 'payment_method' => $gateway ] )
		&& ! $legacy_guard( $legacy_detail, (object) [ 'gateway_id' => null, 'payment_method' => $gateway . ' ' ] ),
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
	$hosted_db = $db;
	foreach ( [ 'total' => '101', 'currency' => 'USD' ] as $drift_column => $drift_value ) {
		$db = $seed( $claimed );
		$db->before_write = static function ( AttemptSeamWpdb $store, string $sql ) use ( $drift_column, $drift_value ): void {
			unset( $sql );
			$store->columns[ $drift_column ] = $drift_value;
		};
		$blocked_send = EcpayAttempt::authorize_browser_send( 7, $gateway, $mtn2, $fingerprint, $claim_nonce );
		$blocked_detail = json_decode( (string) $db->value, true );
		$released = EcpayAttempt::release_browser_authorization( 7, $gateway, $mtn2, $fingerprint, $claim_nonce );
		$released_detail = json_decode( (string) $db->value, true );
		$check(
			! $blocked_send
				&& 'reserved' === ( $blocked_detail[ Dispatch::KEY ]['ecpay_browser_authorization']['state'] ?? null )
				&& $released
				&& ! array_key_exists( 'ecpay_browser_authorization', $released_detail[ Dispatch::KEY ] ?? [] ),
			'R14b hosted ' . $drift_column . ' drift loses the pre-I/O CAS while its unsent reservation remains releasable'
		);
	}
	$db = $hosted_db;
	$wpdb = $hosted_db;
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

	// A current, already-issued ECPay ATM/CVS instruction is the only submitted
	// attempt that may rotate. The provider receipt and successor are committed
	// together; callbacks can then update only the bounded predecessor ledger.
	$offline_make = static function ( string $offline_gateway, string $payment_type, string $rtn_code, string $pay_no ) use ( $merchant ): array {
		$mtn = 'YS7T' . strtoupper( substr( hash( 'sha256', $offline_gateway ), 0, 12 ) );
		$detail = Attempt::begin( [], 'ecpay', $offline_gateway, [], $mtn );
		$made = Dispatch::make( 7, Attempt::current( $detail ), $offline_gateway );
		$record = $made['record'];
		$record['state'] = Dispatch::STATE_SUBMITTED;
		$record['submitted_at'] = 1770000000;
		$record['payable_handoff'] = [
			'kind'          => 'provider',
			'operation_key' => $made['operation_key'],
			'nonce'         => str_repeat( 'c', 32 ),
			'claimed_at'    => 1770000001,
		];
		$detail[ Dispatch::KEY ] = $record;
		$detail += [
			'mer_trade_no'            => $mtn,
			'ecpay_merchant_trade_no' => $mtn,
			'ecpay_operation_key'     => $made['operation_key'],
			'ecpay_charged_amount'    => 100,
			'ecpay_environment'       => 'stage',
			'ecpay_merchant_id'       => $merchant,
			'payment_provider'        => 'ecpay',
			'payment_method'          => $offline_gateway,
			'payment_type'            => $payment_type,
			'trade_status'            => $rtn_code,
			'pay_no'                  => $pay_no,
			'expire_date'             => '2026/09/30 23:59:59',
		];
		if ( 'ys_ec_ecpay_atm' === $offline_gateway ) {
			$detail['bank_type'] = '812';
		}
		$order = (object) [
			'id'             => 7,
			'status'         => 'offline_payment',
			'gateway_id'     => $offline_gateway,
			'payment_method' => $offline_gateway,
			'gateway_trade_no' => '',
			'total'          => '100',
			'currency'       => 'TWD',
		];
		return [ $order, $detail, $mtn ];
	};
	$rotate_offline = static function ( array $old_detail, array $candidate, string $old_gateway ): ?array {
		$successor_gateway = 'ys_ec_payuni_credit';
		$history_keys = EcpayAttempt::contribute_history_keys( [], $old_gateway, $successor_gateway, 7 );
		$next = Attempt::begin( $old_detail, 'payuni', $successor_gateway, $history_keys );
		$made = Dispatch::make( 7, Attempt::current( $next ), $successor_gateway );
		$next[ Dispatch::KEY ] = $made['record'];
		return EcpayAttempt::append_for_rotation(
			$next,
			$candidate,
			Attempt::current( $next ),
			$made['record'],
			$successor_gateway
		);
	};

	$offline_cases = [
		'ATM' => [ 'ys_ec_ecpay_atm', 'ATM_TAISHIN', '2', '0012345678901234' ],
		'CVS' => [ 'ys_ec_ecpay_cvs', 'CVS_CVS', '10100073', '009988776655' ],
	];
	$offline_rotations = [];
	foreach ( $offline_cases as $label => [ $offline_gateway, $payment_type, $rtn_code, $pay_no ] ) {
		[ $offline_order, $offline_detail, $offline_mtn ] = $offline_make( $offline_gateway, $payment_type, $rtn_code, $pay_no );
		$gate = EcpayAttempt::repay_gate( $offline_order, $offline_detail, 'ys_ec_payuni_credit' );
		$candidate = is_array( $gate['candidate'] ?? null ) ? $gate['candidate'] : [];
		$rotated = [] !== $candidate ? $rotate_offline( $offline_detail, $candidate, $offline_gateway ) : null;
		$offline_rotations[ $label ] = [ $offline_order, $offline_detail, $offline_mtn, $candidate, $rotated ];
		$check(
			true === ( $gate['recognized'] ?? null )
				&& true === ( $gate['actionable'] ?? null )
				&& is_array( $rotated )
				&& isset( $rotated[ EcpayAttempt::LATE_SETTLEMENT_KEY ]['entries'] )
				&& 'payuni' === ( $rotated[ Attempt::KEY ]['provider'] ?? null ),
			'R19 ' . $label . ' exact issued instruction rotates only with a same-CAS predecessor receipt'
		);
	}

	[ $atm_order, $atm_detail, $atm_mtn, $atm_candidate, $atm_rotated ] = $offline_rotations['ATM'];
	$blocked_by_open = EcpayAttempt::repay_gate(
		(object) [
			'id' => 7, 'status' => 'pending', 'gateway_id' => 'ys_ec_payuni_credit',
			'payment_method' => 'ys_ec_payuni_credit', 'total' => '100', 'currency' => 'TWD',
		],
		$atm_rotated,
		'ys_ec_payuni_credit'
	);
	$check(
		false === ( $blocked_by_open['recognized'] ?? null )
			&& false === ( $blocked_by_open['actionable'] ?? null )
			&& str_contains( (string) ( $blocked_by_open['warning'] ?? '' ), '避免重複繳款' ),
		'R20 a non-ECPay successor remains under Core dispatch policy and is not permanently refused by one open receipt'
	);
	foreach ( [
		'ECPay CVS'       => 'ys_ec_ecpay_cvs',
		'ECPay barcode'   => 'ys_ec_ecpay_barcode',
		'PayUni ATM'      => 'ys_ec_payuni_atm',
		'unknown gateway' => 'ys_ec_future_delayed',
	] as $target_label => $target_gateway ) {
		$blocked_target_offline = EcpayAttempt::repay_gate(
			(object) [
				'id' => 7, 'status' => 'pending', 'gateway_id' => 'ys_ec_payuni_credit',
				'payment_method' => 'ys_ec_payuni_credit', 'total' => '100', 'currency' => 'TWD',
			],
			$atm_rotated,
			$target_gateway
		);
		$check(
			true === ( $blocked_target_offline['recognized'] ?? null )
				&& false === ( $blocked_target_offline['actionable'] ?? null )
				&& 'ecpay_predecessor_unresolved' === ( $blocked_target_offline['reason'] ?? null ),
			'R20b OPEN predecessor blocks delayed/unknown target: ' . $target_label
		);
	}
	$second_offline = clone $atm_order;
	$second_offline->gateway_id = 'ys_ec_ecpay_cvs';
	$second_offline->payment_method = 'ys_ec_ecpay_cvs';
	$blocked_second_offline = EcpayAttempt::repay_gate( $second_offline, $atm_rotated, 'ys_ec_ecpay_cvs' );
	$check(
		true === ( $blocked_second_offline['recognized'] ?? null )
			&& false === ( $blocked_second_offline['actionable'] ?? null )
			&& 'ecpay_predecessor_unresolved' === ( $blocked_second_offline['reason'] ?? null ),
		'R20a one open predecessor still blocks archiving a second issued ECPay offline instrument'
	);

	$db = $seed( $atm_rotated, 'ys_ec_payuni_credit' );
	$db->columns['status'] = 'pending';
	$db->columns['payment_method'] = 'ys_ec_payuni_credit';
	$payment_info = [
		'MerchantID'      => $merchant,
		'MerchantTradeNo' => $atm_mtn,
		'TradeAmt'        => '100',
		'RtnCode'         => '2',
		'PaymentType'     => 'ATM_TAISHIN',
		'vAccount'        => '0012345678901234',
		'ExpireDate'      => '2026/09/30 23:59:59',
		'BankCode'        => '812',
	];
	$info_result = EcpayAttempt::record_historical_callback(
		(object) [ 'id' => 7 ], $payment_info, $merchant, 'stage', EcpayAttempt::ACTION_PAYMENT_INFO
	);
	$after_info = json_decode( (string) $db->value, true );
	$late_key = hash( 'sha256', 'ecpay|7|' . $atm_mtn );
	$check(
		EcpayAttempt::LATE_CALLBACK_PERSISTED === $info_result
			&& EcpayAttempt::LATE_STATE_OPEN === ( $after_info[ EcpayAttempt::LATE_SETTLEMENT_KEY ]['entries'][ $late_key ]['state'] ?? null )
			&& EcpayAttempt::ACTION_PAYMENT_INFO === ( $after_info[ EcpayAttempt::LATE_SETTLEMENT_KEY ]['entries'][ $late_key ]['last_action'] ?? null )
			&& 'payuni' === ( $after_info[ Attempt::KEY ]['provider'] ?? null ),
		'R21 late ATM payment-info updates only the predecessor receipt and preserves the successor attempt'
	);

	$paid = [
		'MerchantID'      => $merchant,
		'MerchantTradeNo' => $atm_mtn,
		'TradeAmt'        => '100',
		'RtnCode'         => '1',
		'PaymentType'     => 'ATM_TAISHIN',
		'TradeNo'         => '2609150000000001',
	];
	$paid_result = EcpayAttempt::record_historical_callback(
		(object) [ 'id' => 7 ], $paid, $merchant, 'stage', EcpayAttempt::ACTION_SUCCESS
	);
	$after_paid = json_decode( (string) $db->value, true );
	$updates_after_paid = $db->updates;
	$replay_result = EcpayAttempt::record_historical_callback(
		(object) [ 'id' => 7 ], $paid, $merchant, 'stage', EcpayAttempt::ACTION_SUCCESS
	);
	$after_replay = (string) $db->value;
	$check(
		EcpayAttempt::LATE_CALLBACK_PERSISTED === $paid_result
			&& EcpayAttempt::LATE_STATE_PAID_MANUAL === ( $after_paid[ EcpayAttempt::LATE_SETTLEMENT_KEY ]['entries'][ $late_key ]['state'] ?? null )
			&& '2609150000000001' === ( $after_paid[ EcpayAttempt::LATE_SETTLEMENT_KEY ]['entries'][ $late_key ]['last_trade_no'] ?? null )
			&& '' === (string) ( $db->columns['gateway_trade_no'] ?? '' )
			&& EcpayAttempt::LATE_CALLBACK_PERSISTED === $replay_result
			&& $updates_after_paid === $db->updates
			&& wp_json_encode( $after_paid ) === $after_replay,
		'R22 late success becomes manual-paid exactly once and replay cannot settle or rewrite the successor'
	);
	$receipt_only = $after_paid;
	unset( $receipt_only[ Attempt::HISTORY_KEY ] );
	$check(
		EcpayAttempt::identity_is_discoverable( $receipt_only, $atm_mtn, 7 )
			&& ! EcpayAttempt::identity_is_discoverable( $receipt_only, $atm_mtn ),
		'R22a exact durable receipt keeps historical lookup discoverable after bounded generic history eviction'
	);
	$check(
		EcpayAttempt::successor_dispatch_allows( $atm_rotated, 7 )
			&& ! EcpayAttempt::successor_dispatch_allows( $after_paid, 7 ),
		'R22b an open receipt permits its successor, while durable late-paid evidence closes every further form or provider send'
	);

	$failure_after_paid = $paid;
	$failure_after_paid['RtnCode'] = '10200095';
	unset( $failure_after_paid['TradeNo'] );
	$paid_entry_before_anomaly = $after_paid[ EcpayAttempt::LATE_SETTLEMENT_KEY ]['entries'][ $late_key ] ?? [];
	$check(
		'anomaly_persisted' === EcpayAttempt::record_historical_callback(
			(object) [ 'id' => 7 ], $failure_after_paid, $merchant, 'stage', EcpayAttempt::ACTION_FAILURE
		),
		'R23a divergent callback is ACK-eligible only after a durable anomaly receipt'
	);
	$after_paid_anomaly = json_decode( (string) $db->value, true );
	$paid_entry_after_anomaly = $after_paid_anomaly[ EcpayAttempt::LATE_SETTLEMENT_KEY ]['entries'][ $late_key ] ?? [];
	$paid_anomalies = is_array( $paid_entry_after_anomaly['anomalies'] ?? null ) ? $paid_entry_after_anomaly['anomalies'] : [];
	$first_paid_anomaly = [] === $paid_anomalies ? null : reset( $paid_anomalies );
	$check(
		EcpayAttempt::LATE_STATE_PAID_MANUAL === ( $paid_entry_after_anomaly['state'] ?? null )
			&& ( $paid_entry_before_anomaly['last_callback_evidence_digest'] ?? null ) === ( $paid_entry_after_anomaly['last_callback_evidence_digest'] ?? null )
			&& '2609150000000001' === ( $paid_entry_after_anomaly['last_trade_no'] ?? null )
			&& 1 === count( $paid_anomalies )
			&& 'divergent_after_paid' === ( is_array( $first_paid_anomaly ) ? ( $first_paid_anomaly['reason'] ?? null ) : null ),
		'R23 success remains authoritative while divergent evidence is retained separately'
	);

	$db = $seed( $atm_rotated, 'ys_ec_payuni_credit' );
	$db->columns['status'] = 'pending';
	$db->columns['payment_method'] = 'ys_ec_payuni_credit';
	$missing_trade_no = $paid;
	unset( $missing_trade_no['TradeNo'] );
	$check(
		'anomaly_persisted' === EcpayAttempt::record_historical_callback(
			(object) [ 'id' => 7 ], $missing_trade_no, $merchant, 'stage', EcpayAttempt::ACTION_SUCCESS
		),
		'R23b signed late-paid callback missing TradeNo is ACK-eligible only after durable anomaly evidence'
	);
	$after_missing_trade = json_decode( (string) $db->value, true );
	$missing_entry = $after_missing_trade[ EcpayAttempt::LATE_SETTLEMENT_KEY ]['entries'][ $late_key ] ?? [];
	$missing_anomalies = is_array( $missing_entry['anomalies'] ?? null ) ? $missing_entry['anomalies'] : [];
	$first_missing_anomaly = [] === $missing_anomalies ? null : reset( $missing_anomalies );
	$check(
		EcpayAttempt::LATE_STATE_OPEN === ( $missing_entry['state'] ?? null )
			&& 1 === count( $missing_anomalies )
			&& 'trade_no_missing' === ( is_array( $first_missing_anomaly ) ? ( $first_missing_anomaly['reason'] ?? null ) : null )
			&& ! EcpayAttempt::successor_dispatch_allows( $after_missing_trade, 7 )
			&& false === ( EcpayAttempt::repay_gate(
				(object) [
					'id' => 7,
					'gateway_id' => 'ys_ec_payuni_credit',
					'payment_method' => 'ys_ec_payuni_credit',
					'status' => 'pending',
				],
				$after_missing_trade,
				'ys_ec_payuni_credit'
			)['actionable'] ?? null ),
		'R23c missing transaction identity cannot promote the predecessor but holds every successor for review'
	);
	foreach ( [ 'paid_manual' => $after_paid, 'success_anomaly' => $after_missing_trade ] as $late_state => $late_source ) {
		$blocked_browser = $current;
		$blocked_browser[ EcpayAttempt::LATE_SETTLEMENT_KEY ] = $late_source[ EcpayAttempt::LATE_SETTLEMENT_KEY ];
		$blocked_fingerprint = EcpayAttempt::browser_fingerprint( $blocked_browser );
		$db = $seed( $blocked_browser );
		$blocked_bytes = (string) $db->value;
		$blocked_nonce = EcpayAttempt::claim_browser_authorization(
			7, $gateway, $mtn2, $blocked_fingerprint, 'confirm'
		);
		$check(
			! EcpayAttempt::browser_owner_matches(
				$blocked_browser, 7, $gateway, $mtn2, $blocked_fingerprint, $order()
			)
				&& '' === $blocked_nonce
				&& 0 === $db->updates
				&& $blocked_bytes === (string) $db->value,
			'R23d durable ' . $late_state . ' predecessor evidence blocks hosted-page and browser-send authority byte-exact'
		);
	}

	[ $cvs_order, $cvs_detail, $cvs_mtn, $cvs_candidate, $cvs_rotated ] = $offline_rotations['CVS'];
	$db = $seed( $cvs_rotated, 'ys_ec_payuni_credit' );
	$db->columns['status'] = 'pending';
	$db->columns['payment_method'] = 'ys_ec_payuni_credit';
	$cvs_failure = [
		'MerchantID'      => $merchant,
		'MerchantTradeNo' => $cvs_mtn,
		'TradeAmt'        => '100',
		'RtnCode'         => '10200095',
		'PaymentType'     => 'CVS_CVS',
	];
	$terminal_result = EcpayAttempt::record_historical_callback(
		(object) [ 'id' => 7 ], $cvs_failure, $merchant, 'stage', EcpayAttempt::ACTION_FAILURE
	);
	$after_terminal = json_decode( (string) $db->value, true );
	$cvs_key = hash( 'sha256', 'ecpay|7|' . $cvs_mtn );
	$check(
		EcpayAttempt::LATE_CALLBACK_PERSISTED === $terminal_result
			&& EcpayAttempt::LATE_STATE_PROVIDER_TERMINAL === ( $after_terminal[ EcpayAttempt::LATE_SETTLEMENT_KEY ]['entries'][ $cvs_key ]['state'] ?? null ),
		'R24 a signed terminal predecessor failure reopens the contemporary repay policy without touching current payment state'
	);

	$wrong_amount = $cvs_failure;
	$wrong_amount['RtnCode'] = '1';
	$wrong_amount['TradeNo'] = '2609150000000002';
	$wrong_amount['TradeAmt'] = '101';
	$check(
		'anomaly_persisted' === EcpayAttempt::record_historical_callback(
			(object) [ 'id' => 7 ], $wrong_amount, $merchant, 'stage', EcpayAttempt::ACTION_SUCCESS
		),
		'R25a wrong amount is ACK-eligible only after a durable anomaly receipt'
	);
	$after_wrong_amount = json_decode( (string) $db->value, true );
	$wrong_entry = $after_wrong_amount[ EcpayAttempt::LATE_SETTLEMENT_KEY ]['entries'][ $cvs_key ] ?? [];
	$wrong_anomalies = is_array( $wrong_entry['anomalies'] ?? null ) ? $wrong_entry['anomalies'] : [];
	$first_wrong_anomaly = [] === $wrong_anomalies ? null : reset( $wrong_anomalies );
	$check(
		EcpayAttempt::LATE_STATE_PROVIDER_TERMINAL === ( $wrong_entry['state'] ?? null )
			&& 1 === count( $wrong_anomalies )
			&& 'receipt_mismatch' === ( is_array( $first_wrong_anomaly ) ? ( $first_wrong_anomaly['reason'] ?? null ) : null )
			&& 101 === ( is_array( $first_wrong_anomaly ) ? ( $first_wrong_anomaly['amount'] ?? null ) : null )
			&& ! EcpayAttempt::successor_dispatch_allows( $after_wrong_amount, 7 ),
		'R25b wrong identity or amount cannot change predecessor state and signed success remains manual-hold'
	);

	$bad_gate_detail = $atm_detail;
	$bad_gate_detail['pay_no'] = 'DIFFERENT';
	$bad_candidate = $atm_candidate;
	$bad_candidate['predecessor_generation'] = 999;
	$bad_gate = EcpayAttempt::repay_gate( $atm_order, $bad_gate_detail, 'ys_ec_payuni_credit' );
	$check(
		true === ( $bad_gate['actionable'] ?? null )
			&& ! hash_equals( (string) $atm_candidate['pay_no_digest'], (string) $bad_gate['candidate']['pay_no_digest'] )
			&& null === $rotate_offline( $atm_detail, $bad_candidate, 'ys_ec_ecpay_atm' ),
		'R26 candidate and archived attempt must match exactly at append; a stale candidate cannot create a receipt'
	);

	$malformed_ledger = $atm_rotated;
	$malformed_ledger[ EcpayAttempt::LATE_SETTLEMENT_KEY ]['entries'][ $late_key ]['claim_digest'] = str_repeat( '0', 64 );
	$db = $seed( $malformed_ledger, 'ys_ec_payuni_credit' );
	$db->columns['status'] = 'pending';
	$db->columns['payment_method'] = 'ys_ec_payuni_credit';
	$check(
		EcpayAttempt::LATE_CALLBACK_RETRY === EcpayAttempt::record_historical_callback(
			(object) [ 'id' => 7 ], $paid, $merchant, 'stage', EcpayAttempt::ACTION_SUCCESS
		)
			&& true === ( EcpayAttempt::repay_gate( $atm_order, $malformed_ledger, 'ys_ec_payuni_credit' )['recognized'] ?? null )
			&& false === ( EcpayAttempt::repay_gate( $atm_order, $malformed_ledger, 'ys_ec_payuni_credit' )['actionable'] ?? null )
			&& ! EcpayAttempt::successor_dispatch_allows( $malformed_ledger, 7 ),
		'R27 malformed durable ledger is retryable for callbacks and fail-closed for every new repay'
	);

	$db = $seed( $next );
	$check(
		EcpayAttempt::LATE_CALLBACK_NOT_LATE === EcpayAttempt::record_historical_callback(
			(object) [ 'id' => 7 ], $paid, $merchant, 'stage', EcpayAttempt::ACTION_SUCCESS
		),
		'R28 legacy generic history remains discovery-only and zero-write when no new receipt exists'
	);
	$malformed_current = $paid;
	$malformed_current['TradeAmt'] = '0';
	$db = $seed( $next );
	$check(
		EcpayAttempt::LATE_CALLBACK_NOT_LATE === EcpayAttempt::record_historical_callback(
			(object) [ 'id' => 7 ], $malformed_current, $merchant, 'stage', EcpayAttempt::ACTION_SUCCESS
		)
			&& 0 === $db->updates,
		'R28a malformed current callbacks stay under ordinary controller validation and cannot be swallowed by the late interceptor'
	);

	echo "\nattempt callback seam pair: {$pass} PASS / {$fail} FAIL\n";
	exit( $fail > 0 ? 1 : 0 );
}
