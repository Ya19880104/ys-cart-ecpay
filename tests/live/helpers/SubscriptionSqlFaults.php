<?php
/** Finite named fault state; actual transport results are never invented here. */
declare(strict_types=1);
namespace YSCartEcpay\Tests\Live;
final class SubscriptionSqlFaults {
	private bool $casWon = false;
	private bool $consumeWon = false;
	private array $triggered = [];
	private ?array $actualCommit = null;
	private function __construct( private string $case, private string $role ) {}
	public static function arm( string $case, string $role ): self {
		if ( ! in_array( $case, [ 'P1','P2','P3','P4','P5','P6','P7','P8','P9','P10','P11a','P11b','P11c','P11d','P11e','P11f','P11g','P11h','P12a','P12b' ], true ) || ! in_array( $role, [ 'A','B' ], true ) ) { throw new SubscriptionSqlFailure( 'fault_contract_invalid' ); }
		return new self( $case, $role );
	}
	private function trigger( string $name ): void {
		if ( isset( $this->triggered[$name] ) ) { throw new SubscriptionSqlFailure( 'fault_duplicate_trigger' ); }
		$this->triggered[$name] = true;
	}
	public function before( string $kind, string $sql, array $identity ): array {
		if(!in_array($kind,['read-or-setup','owner-set','begin','locked-read','profile-cas','claim','consume','session-verify','commit','rollback'],true)) { throw new SubscriptionSqlFailure('fault_contract_invalid'); }
		$decision = [ 'send' => true, 'action' => 'none', 'fault' => null, 'presented' => null ];
		if ( 'B' === $this->role ) { return $decision; }
		if ( 'commit' === $kind && in_array( $this->case, [ 'P2','P4','P5','P10' ], true ) && ( ! $this->casWon || ! $this->consumeWon ) ) { throw new SubscriptionSqlFailure( 'fault_stage_invalid' ); }
		if ( 'commit' === $kind && 'P5' === $this->case ) {
			$this->trigger( 'commit-suppressed' ); return [ 'send' => false, 'action' => 'none', 'fault' => 'commit-suppressed', 'presented' => [ 'error' => 'fixture_commit_suppressed', 'rows' => [], 'affected' => false ] ];
		}
		if ( 'session-verify' === $kind && $this->consumeWon && in_array( $this->case, [ 'P3','P6' ], true ) && ! isset( $this->triggered['precommit-unreadable'] ) ) {
			$this->trigger( 'precommit-unreadable' ); return [ 'send' => false, 'action' => 'none', 'fault' => 'precommit-unreadable', 'presented' => [ 'error' => 'fixture_precommit_unreadable', 'rows' => [], 'affected' => false ] ];
		}
		if ( 'rollback' === $kind && 'P6' === $this->case && isset( $this->triggered['precommit-unreadable'] ) ) {
			$this->trigger( 'rollback-suppressed' ); return [ 'send' => false, 'action' => 'none', 'fault' => 'rollback-suppressed', 'presented' => [ 'error' => 'fixture_rollback_suppressed', 'rows' => [], 'affected' => false ] ];
		}
		$action = match ( true ) {
			'P2' === $this->case && 'commit' === $kind => 'pause-before-commit',
			'P7' === $this->case && 'claim' === $kind && $this->casWon => 'swap-global',
			'P8' === $this->case && 'consume' === $kind && $this->casWon => 'replace-before-consume',
			'P9' === $this->case && 'session-verify' === $kind && $this->consumeWon && ! isset( $this->triggered['replace-before-verify'] ) => 'replace-before-verify',
			'P10' === $this->case && 'commit' === $kind => 'replace-before-commit',
			'P12a' === $this->case && 'claim' === $kind && $this->casWon => 'pause-before-claim',
			default => 'none',
		};
		if ( 'none' !== $action ) { $this->trigger( $action ); $decision['action'] = $action; $decision['fault'] = $action; }
		return $decision;
	}
	public function after( string $kind, string $sql, array $actualResult ): array {
		if ( ! is_string( $actualResult['error'] ?? null ) || ! is_array( $actualResult['rows'] ?? null ) || ! array_key_exists( 'affected', $actualResult )
			|| ( ! is_int( $actualResult['affected'] ) && false !== $actualResult['affected'] ) ) { throw new SubscriptionSqlFailure( 'transport_result_invalid' ); }
		if ( 'profile-cas' === $kind ) { $this->casWon = '' === $actualResult['error'] && 1 === $actualResult['affected']; }
		if ( 'consume' === $kind ) { $this->consumeWon = '' === $actualResult['error'] && 1 === $actualResult['affected']; }
		if ( 'commit' === $kind && 'P4' === $this->case && 'A' === $this->role ) {
			if ( ! $this->casWon || ! $this->consumeWon || '' !== $actualResult['error'] || false === $actualResult['affected'] ) { throw new SubscriptionSqlFailure( 'fault_stage_invalid' ); }
			$this->trigger( 'ack-lost' ); $this->actualCommit = $actualResult;
			return [ 'error' => 'fixture_ack_lost', 'rows' => [], 'affected' => false ];
		}
		return $actualResult;
	}
	public function receipt(): array { return [ 'case' => $this->case, 'role' => $this->role, 'trigger_count' => count( $this->triggered ), 'triggered' => array_keys( $this->triggered ), 'actual_commit' => $this->actualCommit ]; }
	public function finish():array {
		$expected='B'===$this->role?[]:match($this->case) {
			'P2'=>['pause-before-commit'],'P3'=>['precommit-unreadable'],'P4'=>['ack-lost'],'P5'=>['commit-suppressed'],
			'P6'=>['precommit-unreadable','rollback-suppressed'],'P7'=>['swap-global'],'P8'=>['replace-before-consume'],
			'P9'=>['replace-before-verify'],'P10'=>['replace-before-commit'],'P12a'=>['pause-before-claim'],default=>[],
		};
		if(array_keys($this->triggered)!==$expected) { throw new SubscriptionSqlFailure('fault_untriggered'); }
		return $this->receipt();
	}
}
