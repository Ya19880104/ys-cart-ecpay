<?php
/** Offline worker/oracle contracts. A matching supplied observation is never SQL acceptance. */
declare(strict_types=1);
namespace YSCartEcpay\Tests\Live;
final class SubscriptionSqlScenario {
	public static function manifest(): array {
		$rows = [
			'P1' => [ 'commit', 'new', [ 'after-commit-readback', 'renewal-projection' ], [ 'cas' => 1, 'consume' => 1, 'commit' => 1 ] ],
			'P2' => [ 'competing-update', 'new', [ 'server-lock-wait-observed', 'competitor-authority-changed' ], [ 'cas' => 1, 'consume' => 1, 'commit' => 1 ] ],
			'P3' => [ 'rollback-after-claim', 'old', [ 'precommit-drift', 'rollback-readback' ], [ 'cas' => 1, 'consume' => 1, 'commit' => 0 ] ],
			'P4' => [ 'commit-applied-ack-lost', 'new', [ 'commit-applied', 'ack-lost', 'protective-rollback' ], [ 'cas' => 1, 'consume' => 1, 'commit' => 1 ] ],
			'P5' => [ 'commit-not-applied', 'old', [ 'commit-suppressed', 'protective-rollback' ], [ 'cas' => 1, 'consume' => 1, 'commit' => 1 ] ],
			'P6' => [ 'rollback-ack-unavailable', 'old', [ 'original-closed-once', 'poison-before-close', 'no-post-close-sql' ], [ 'cas' => 1, 'consume' => 1, 'commit' => 0 ] ],
			'P7' => [ 'global-handle-swap', 'old', [ 'original-rollback', 'no-write-on-B' ], [ 'cas' => 1, 'consume' => 0, 'commit' => 0 ] ],
			'P8' => [ 'controlled-reconnect-replay', 'old', [ 'physical-session-changed', 'nonce-absent', 'fenced-consume-zero' ], [ 'cas' => 1, 'consume' => 1, 'commit' => 0 ] ],
			'P9' => [ 'post-claim-disconnect', 'old', [ 'physical-session-changed', 'no-commit-attempt' ], [ 'cas' => 1, 'consume' => 1, 'commit' => 0 ] ],
			'P10' => [ 'commit-on-replacement-session', 'old', [ 'replacement-commit', 'postcommit-drift' ], [ 'cas' => 1, 'consume' => 1, 'commit' => 1 ] ],
			'P11' => [ 'admission-or-replay', 'old', [], [ 'cas' => 0, 'consume' => 0, 'commit' => 0 ] ],
			'P12' => [ 'exact-byte-interference', 'old', [ 'exact-byte-mismatch', 'bindings-rechecked' ], [ 'cas' => 0, 'consume' => 0, 'commit' => 0 ] ],
		];
		$result = [];
		foreach ( $rows as $case => [ $label, $pair, $evidence, $counts ] ) {
			$result[$case] = [ 'case' => $case, 'label' => $label, 'expected_pair' => $pair, 'required_evidence' => $evidence, 'attempt_counts' => $counts,
				'acceptance' => 'NOT RUN', 'driver' => 'adapter', 'native_wpdb' => 'PREREQUISITE UNSATISFIED' ];
		}
		return $result;
	}
	public static function worker( string $case, string $role ): array {
		$row = self::manifest()[$case] ?? null;
		if ( null === $row || ! in_array( $role, [ 'A', 'B', 'controller' ], true ) ) { throw new SubscriptionSqlFailure( 'worker_contract_invalid' ); }
		return $row + [ 'role' => $role, 'status' => 'DESCRIPTOR ONLY', 'entrypoint' => 'YangSheep\\Ecommerce\\Services\\Subscription\\YSSubscriptionFulfillmentProfileCoordinator::update',
			'stages' => 'B' === $role && 'P2' === $case ? [ 'ready', 'before-product-lock', 'after-product-result', 'independent-readback' ]
				: ( 'A' === $role ? [ 'seed-readback', 'before-product-request', 'scenario-barrier', 'after-product-result' ] : [ 'admit-pair', 'await-workers', 'collect-readbacks' ] ),
			'barrier_deadline_ms' => 10000, 'process_deadline_ms' => 30000 ];
	}
	public static function evaluate( string $case, array $before, array $observed ): array {
		$spec = self::worker( $case, 'controller' );
		$errors = [];
		$pair = $observed['pair'] ?? [];
		if ( true !== ( $before['ok'] ?? null ) || true !== ( $pair['ok'] ?? null ) ) { $errors[] = 'pair_readback_missing'; }
		if ( 0 !== ( $observed['stderr_bytes'] ?? null ) || 0 !== ( $observed['unexpected_log_events'] ?? null ) ) { $errors[] = 'unexpected_diagnostics'; }
		if ( ! is_array( $observed['sentinels_before'] ?? null ) || [] === $observed['sentinels_before'] || $observed['sentinels_before'] !== ( $observed['sentinels_after'] ?? null ) ) { $errors[] = 'sentinel_changed_or_missing'; }
		foreach ( $spec['attempt_counts'] as $kind => $count ) { if ( $count !== ( $observed['counts'][$kind] ?? null ) ) { $errors[] = 'statement_count_' . $kind; } }
		foreach ( $spec['required_evidence'] as $name ) { if ( ! in_array( $name, $observed['evidence'] ?? [], true ) ) { $errors[] = 'evidence_missing_' . $name; } }
		if ( 'old' === $spec['expected_pair'] ) {
			if ( $pair !== $before || 'issued' !== ( $pair['selection_state'] ?? null ) ) { $errors[] = 'old_pair_changed'; }
		} elseif ( ! self::newPair( $before, $pair ) ) { $errors[] = 'new_pair_incomplete'; }
		$response = $observed['response'] ?? [];
		if ( in_array( $case, [ 'P1', 'P2' ], true ) ) {
			if ( true !== ( $response['success'] ?? null ) || 4 !== ( $response['data']['generation'] ?? null ) ) { $errors[] = 'success_response_missing'; }
		} elseif ( in_array( $case, [ 'P3','P4','P5','P6','P9','P10' ], true ) ) {
			$code = in_array( $case, [ 'P3','P9' ], true ) ? 'profile_update_session_drift' : ( 'P6' === $case ? 'profile_update_rollback_indeterminate' : 'profile_update_commit_indeterminate' );
			if ( false !== ( $response['success'] ?? null ) || $code !== ( $response['code'] ?? null ) || false !== ( $response['retryable'] ?? null ) || true !== ( $response['requires_manual_reconciliation'] ?? null ) ) { $errors[] = 'manual_failure_missing'; }
		} elseif ( false !== ( $response['success'] ?? null ) || ! is_string( $response['code'] ?? null ) || '' === $response['code'] ) { $errors[] = 'typed_rejection_missing'; }
		return [ 'matches' => [] === $errors, 'errors' => $errors, 'acceptance' => 'NOT RUN', 'scope' => 'OFFLINE ORACLE UNIT ONLY' ];
	}
	private static function newPair( array $before, array $pair ): bool {
		if ( ! is_string( $pair['profile_updated_at'] ?? null ) || $pair['profile_updated_at'] === ( $before['profile_updated_at'] ?? null )
			|| 1 !== preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/', $pair['profile_updated_at'] )
			|| $pair['profile_updated_at'] !== ( $pair['updated_at'] ?? null ) ) { return false; }
		foreach ( [ 'subscription_id', 'contract_kind', 'token_digest' ] as $field ) { if ( ! array_key_exists( $field, $before ) || $before[$field] !== ( $pair[$field] ?? null ) ) { return false; } }
		if ( 3 !== ( $before['generation'] ?? null ) || 4 !== ( $pair['generation'] ?? null ) || '65.00' !== ( $pair['shipping_total'] ?? null ) || 'consumed' !== ( $pair['selection_state'] ?? null ) || 4 !== ( $pair['selection_generation'] ?? null ) ) { return false; }
		if ( ! is_string( $pair['profile_bytes'] ?? null ) || hash( 'sha256', $pair['profile_bytes'] ) !== ( $pair['profile_hash'] ?? null )
			|| ! is_string( $pair['selection_bytes'] ?? null ) || hash( 'sha256', $pair['selection_bytes'] ) !== ( $pair['selection_sha256'] ?? null ) ) { return false; }
		$expected = json_decode( $before['profile_bytes'] ?? '' ); $new = json_decode( $pair['profile_bytes'] );
		$expectedSelection = json_decode( $before['selection_bytes'] ?? '' ); $newSelection = json_decode( $pair['selection_bytes'] );
		if ( ! $expected instanceof \stdClass || ! $new instanceof \stdClass || ! $expectedSelection instanceof \stdClass || ! $newSelection instanceof \stdClass
			|| ! ( $expected->fulfillment_snapshot ?? null ) instanceof \stdClass || ! ( $expected->fulfillment_snapshot->service ?? null ) instanceof \stdClass ) { return false; }
		// Independent literal allowed transition; never normalize through product code under test.
		$expected->shipping_method_id = 'ys_ec_ecpay_ship_unimart'; $expected->shipping_provider = 'ecpay'; $expected->shipping_total = '65.00';
		$expected->fulfillment_snapshot->provider_id = 'ecpay'; $expected->fulfillment_snapshot->method_id = 'ys_ec_ecpay_ship_unimart';
		$expected->fulfillment_snapshot->destination = (object) [ 'type' => 'cvs', 'recipient_name' => 'Pair Recipient', 'recipient_phone' => '0912345678', 'country' => 'TW', 'store_id' => '991122', 'store_name' => 'Canonical Store', 'store_address' => 'No. 1 Store Rd.' ];
		$expected->fulfillment_snapshot->service->shipping_type = 'cvs';
		$expectedSelection->state = 'consumed';
		$expectedSelection->consumed = (object) [ 'subscription_id' => 41, 'generation' => 4, 'at' => $pair['profile_updated_at'] ];
		return self::sameJsonValue( $expected, $new ) && self::sameJsonValue( $expectedSelection, $newSelection );
	}
	/** Ignore object-key order only; retain scalar types, every key and object/list identity. */
	private static function sameJsonValue( mixed $expected, mixed $actual ): bool {
		if ( get_debug_type( $expected ) !== get_debug_type( $actual ) ) { return false; }
		if ( $expected instanceof \stdClass ) {
			$left = get_object_vars( $expected ); $right = get_object_vars( $actual );
			ksort( $left, SORT_STRING ); ksort( $right, SORT_STRING );
			if ( array_keys( $left ) !== array_keys( $right ) ) { return false; }
			foreach ( $left as $key => $value ) { if ( ! self::sameJsonValue( $value, $right[$key] ) ) { return false; } }
			return true;
		}
		if ( is_array( $expected ) ) {
			if ( array_keys( $expected ) !== array_keys( $actual ) ) { return false; }
			foreach ( $expected as $key => $value ) { if ( ! self::sameJsonValue( $value, $actual[$key] ) ) { return false; } }
			return true;
		}
		return $expected === $actual;
	}
}
