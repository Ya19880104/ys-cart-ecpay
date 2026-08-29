<?php
/**
 * LIVE DB gate (NOT part of the offline regression matrix): proves on a real
 * MySQL/MariaDB server that the durable subscription-selection claim is a true
 * two-connection one-winner CAS, and that a rolled-back claim leaves the row
 * issued. Without a real DSN this gate exits 2 and the atomicity claim MUST
 * NOT be reported green.
 *
 * Run: YS_TEST_MYSQL_DSN=host:port php -n tests/live/live_subscription_selection_two_connections.php
 * Env: YS_TEST_MYSQL_USER (default root), YS_TEST_MYSQL_PASSWORD, YS_TEST_MYSQL_DB (default ys_live_gate)
 */

declare(strict_types=1);

$dsn = (string) getenv( 'YS_TEST_MYSQL_DSN' );
if ( '' === $dsn ) {
	echo "LIVE-GATE SKIPPED: YS_TEST_MYSQL_DSN not set - two-connection atomicity NOT proven on this host\n";
	exit( 2 );
}
if ( ! extension_loaded( 'mysqli' ) ) {
	echo "LIVE-GATE SKIPPED: mysqli extension unavailable (-n?) - rerun with mysqli enabled\n";
	exit( 2 );
}

[ $host, $port ] = array_pad( explode( ':', $dsn, 2 ), 2, '3306' );
$user = (string) ( getenv( 'YS_TEST_MYSQL_USER' ) ?: 'root' );
$password = (string) ( getenv( 'YS_TEST_MYSQL_PASSWORD' ) ?: '' );
$database = (string) ( getenv( 'YS_TEST_MYSQL_DB' ) ?: 'ys_live_gate' );

$connect = static function () use ( $host, $port, $user, $password, $database ): \mysqli {
	$link = mysqli_init();
	if ( ! $link || ! @mysqli_real_connect( $link, $host, $user, $password, '', (int) $port ) ) {
		echo 'LIVE-GATE FAIL: cannot connect: ' . mysqli_connect_error() . "\n";
		exit( 1 );
	}
	$link->query( 'CREATE DATABASE IF NOT EXISTS `' . $link->real_escape_string( $database ) . '`' );
	$link->select_db( $database );
	return $link;
};

$pass = 0;
$fail = 0;
$check = static function ( string $label, bool $ok ) use ( &$pass, &$fail ): void {
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
	$ok ? ++$pass : ++$fail;
};

$a = $connect();
$b = $connect();
$check( 'two distinct server connections established', $a->thread_id !== $b->thread_id );

$table = 'ys_live_subsel_gate_' . getmypid();
$a->query( "DROP TABLE IF EXISTS `{$table}`" );
$created = $a->query(
	"CREATE TABLE `{$table}` (
		option_name VARCHAR(191) NOT NULL PRIMARY KEY,
		option_value LONGTEXT NOT NULL
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);
$check( 'InnoDB gate table created', false !== $created );

$issued = '{"state":"issued","record":{"subscription_id":41}}';
$consumed = '{"state":"consumed","record":{"subscription_id":41}}';
$name = 'ys_ec_ecpay_subsel_' . hash( 'sha256', 'live-token' );
$stmt = $a->prepare( "INSERT INTO `{$table}` (option_name, option_value) VALUES (?, ?)" );
$stmt->bind_param( 'ss', $name, $issued );
$check( 'issued row inserted', $stmt->execute() );

$cas = static function ( \mysqli $link, string $table, string $name, string $old, string $new ): int {
	$stmt = $link->prepare(
		"UPDATE `{$table}` SET option_value = ? WHERE option_name = ? AND option_value = BINARY ?"
	);
	$stmt->bind_param( 'sss', $new, $name, $old );
	$stmt->execute();
	return $stmt->affected_rows;
};

// Round 1: connection A claims inside a transaction and ROLLS BACK; the row
// must stay issued and remain claimable afterwards.
$b->query( 'SET SESSION innodb_lock_wait_timeout = 3' );
$a->begin_transaction();
$rolled_cas = $cas( $a, $table, $name, $issued, $consumed );
$a->rollback();
$after_rollback = $a->query( "SELECT option_value FROM `{$table}` WHERE option_name = '" . $a->real_escape_string( $name ) . "'" )->fetch_row()[0] ?? '';
$check(
	'a rolled-back claim leaves the durable row issued',
	1 === $rolled_cas && $issued === $after_rollback
);

// Round 2: A claims inside an open transaction and COMMITs while B races the
// same CAS on a second connection. Exactly one connection may win.
$a->begin_transaction();
$win_a = $cas( $a, $table, $name, $issued, $consumed );
$b->begin_transaction();
$win_b = $cas( $b, $table, $name, $issued, $consumed ); // blocks until A resolves, then re-evaluates
$a->commit();
if ( 0 === $win_b ) {
	$b->rollback();
} else {
	// If B reported a win it must have happened after A rolled back — invalid here.
	$b->commit();
}
$final = $a->query( "SELECT option_value FROM `{$table}` WHERE option_name = '" . $a->real_escape_string( $name ) . "'" )->fetch_row()[0] ?? '';
$check(
	'two connections racing the same token produce exactly one committed winner',
	1 === $win_a && 0 === $win_b && $consumed === $final
);

$replay = $cas( $b, $table, $name, $issued, $consumed );
$check( 'a replay against the consumed row affects zero rows', 0 === $replay );

$a->query( "DROP TABLE IF EXISTS `{$table}`" );
$a->close();
$b->close();

echo "live subscription selection two-connection gate: {$pass} PASS / {$fail} FAIL\n";
exit( $fail > 0 ? 1 : 0 );
