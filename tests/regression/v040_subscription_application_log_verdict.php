<?php
/** Behavioral meta-regression: application-log bytes must affect the child verdict. */
declare(strict_types=1);

$root = (string) ( getenv( 'YS_ECPAY_LOG_ORACLE_ROOT' ) ?: dirname( __DIR__, 2 ) );
if ( ! getenv( 'YS_ECPAY_ROOT' ) ) { putenv( 'YS_ECPAY_ROOT=' . $root ); }
$scratch = sys_get_temp_dir() . '/yscart-application-log-oracle-' . bin2hex( random_bytes( 8 ) );
mkdir( $scratch );
$pass = 0;
$fail = 0;
$check = static function ( string $label, bool $ok ) use ( &$pass, &$fail ): void {
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
	$ok ? ++$pass : ++$fail;
};
$files = [ 'v037_subscription_pair_commit_boundary.php', 'v039_subscription_account_to_renewal_vertical.php' ];
foreach ( $files as $file ) {
	copy( $root . '/tests/regression/' . $file, $scratch . '/' . $file );
}
if ( is_file( $root . '/tests/regression/helpers/SubscriptionApplicationLog.php' ) ) {
	mkdir( $scratch . '/helpers' );
	copy( $root . '/tests/regression/helpers/SubscriptionApplicationLog.php', $scratch . '/helpers/SubscriptionApplicationLog.php' );
}
$anchor = "\tini_set( 'error_log', is_string( \$pair_error_log_previous ) ? \$pair_error_log_previous : '' );";
$receipts = [];
foreach ( $files as $file ) {
	$source = (string) file_get_contents( $root . '/tests/regression/' . $file );
	if ( 1 !== substr_count( $source, $anchor ) ) {
		$check( $file . ' has exactly one final log restoration boundary', false );
		continue;
	}
	$cases = [
		'control' => '',
		'unexpected-event' => "\terror_log( 'benign unexpected subscription diagnostic' );\n",
		'raw-tail' => "\tfile_put_contents( \$pair_error_log_file, 'benign partial record', FILE_APPEND );\n",
	];
	if ( str_starts_with( $file, 'v037_' ) ) {
		$cases += [
			'extra-known-event' => "\terror_log( '[YS CART][subscription] profile_update_commit_indeterminate subscription_id=41' );\n",
			'known-event-with-suffix' => "\terror_log( '[YS CART][subscription] profile_update_commit_indeterminate subscription_id=41 benign-extra' );\n",
			'missing-event' => "\tcopy( \$pair_error_log_file, \$pair_error_log_file . '.before-missing-event' );\n\tfile_put_contents( \$pair_error_log_file, preg_replace( '/[^\\n]*\\n/', '', file_get_contents( \$pair_error_log_file ), 1 ) );\n",
			'missing-final-newline' => "\tcopy( \$pair_error_log_file, \$pair_error_log_file . '.before-truncation' );\n\tfile_put_contents( \$pair_error_log_file, rtrim( file_get_contents( \$pair_error_log_file ), \"\\r\\n\" ) );\n",
		];
	}
	foreach ( $cases as $case => $injection ) {
		$child = $scratch . '/' . $case . '-' . $file;
		file_put_contents( $child, str_replace( $anchor, $injection . $anchor, $source ) );
		$stdout = $child . '.stdout.txt';
		$stderr = $child . '.stderr.txt';
		$process = proc_open( [ PHP_BINARY, '-n', '-d', 'error_reporting=-1', '-d', 'display_errors=stderr', '-d', 'log_errors=0', $child ],
			[ 0 => [ 'pipe', 'r' ], 1 => [ 'file', $stdout, 'w' ], 2 => [ 'file', $stderr, 'w' ] ], $pipes );
		if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Cannot start child test' ); }
		fclose( $pipes[0] );
		$rc = proc_close( $process );
		$out = (string) file_get_contents( $stdout );
		$err = (string) file_get_contents( $stderr );
		$expected_rc = 'control' === $case ? 0 : 1;
		$normal_verdict = 1 === preg_match( '/subscription (?:pair commit boundary|account to renewal vertical): \d+ PASS \/ ' . ( 0 === $expected_rc ? '0' : '[1-9][0-9]*' ) . ' FAIL\n\z/', $out );
		$log = null;
		if ( 1 === preg_match( '/^APPLICATION_LOG (.+)$/m', $out, $match ) ) { $log = json_decode( $match[1], true ); }
		$retained = is_array( $log ) && is_string( $log['path'] ?? null ) && is_file( $log['path'] )
			&& filesize( $log['path'] ) === $log['bytes'] && hash_file( 'sha256', $log['path'] ) === $log['sha256']
			&& ( 0 === $expected_rc ) === $log['ok'];
		$ok = $expected_rc === $rc && '' === $err && $normal_verdict && $retained;
		$check( $file . ' ' . $case . ' reaches the normal verdict with expected exit code, empty stderr and retained raw log receipt', $ok );
		$receipts[] = [ 'test' => $file, 'case' => $case, 'rc' => $rc, 'expected_rc' => $expected_rc, 'normal_verdict' => $normal_verdict,
			'raw_log_retained' => $retained, 'application_log' => $log,
			'stdout' => $stdout, 'stdout_sha256' => hash( 'sha256', $out ), 'stderr' => $stderr, 'stderr_bytes' => strlen( $err ), 'stderr_sha256' => hash( 'sha256', $err ) ];
	}
}
$receipt_path = $scratch . '/receipts.json';
file_put_contents( $receipt_path, json_encode( $receipts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
echo 'CHILD_RECEIPTS ' . json_encode( [ 'path' => $receipt_path, 'bytes' => filesize( $receipt_path ), 'sha256' => hash_file( 'sha256', $receipt_path ) ], JSON_UNESCAPED_SLASHES ) . "\n";
echo "subscription application log verdict: {$pass} PASS / {$fail} FAIL\n";
exit( $fail > 0 ? 1 : 0 );
