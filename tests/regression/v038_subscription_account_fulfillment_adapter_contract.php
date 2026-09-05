<?php
/** Production contract for the ECPay subscription account selector seam. */

declare(strict_types=1);

if ( in_array( '--callback-child', $argv, true ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
	function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags | JSON_UNESCAPED_SLASHES ); }
	function home_url( string $path = '' ): string { return 'https://account.test' . $path; }
	function esc_url( string $url ): string { return htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' ); }
	function esc_url_raw( string $url ): string { return $url; }
	function esc_attr( string $value ): string { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
	function add_query_arg( array $args, string $url ): string {
		$separator = str_contains( $url, '?' ) ? '&' : '?';
		return $url . $separator . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
	}
	function status_header( int $status ): void { unset( $status ); }
	function nocache_headers(): void {}
	require_once dirname( __DIR__, 2 ) . '/src/Shipping/Ecpay/EcpayStoreSelector.php';
	$method = new ReflectionMethod( YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayStoreSelector::class, 'render_callback_page' );
	$method->invoke( null, [
		'context'         => 'subscription',
		'return_url'      => 'https://account.test/dashboard?tab=subscriptions',
		'selection_token' => 'RAW-SELECTION-TOKEN-MUST-NOT-RENDER',
		'cvs_store_id'    => '991234',
		'cvs_store_name'  => '權威門市',
		'cvs_store_addr'  => '台北市測試路1號',
	], 'ABCDEFGHIJKLMNOPQRSTUVWXYZ123456' );
	exit( 0 );
}

$root = dirname( __DIR__, 2 );
$plugin = (string) file_get_contents( $root . '/src/Plugin.php' );
$selector = (string) file_get_contents( $root . '/src/Shipping/Ecpay/EcpayStoreSelector.php' );
$adapter_path = $root . '/assets/js/ys-cart-ecpay-account-fulfillment.js';
$adapter = is_file( $adapter_path ) ? (string) file_get_contents( $adapter_path ) : '';

$command = [ PHP_BINARY, __FILE__, '--callback-child' ];
$pipes = [];
$process = proc_open( $command, [ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
$stdout = '';
$stderr = '';
$exit = 1;
if ( is_resource( $process ) ) {
	fclose( $pipes[0] );
	$stdout = (string) stream_get_contents( $pipes[1] );
	$stderr = (string) stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	$exit = proc_close( $process );
}

$pass = 0;
$fail = 0;
$check = static function ( string $label, bool $ok ) use ( &$pass, &$fail ): void {
	if ( $ok ) { ++$pass; echo "PASS {$label}\n"; return; }
	++$fail; echo "FAIL {$label}\n";
};

$check(
	'Plugin registers native and headless account adapter seams without checkout selector reuse',
	str_contains( $plugin, "add_action( 'ys_ec_enqueue_account_assets'" )
		&& str_contains( $plugin, "add_filter( 'ys_ec_account_ui_assets'" )
		&& str_contains( $plugin, 'ys-cart-ecpay-account-fulfillment.js' )
		&& ! str_contains( $adapter, 'ys-ec-checkout' )
);
$check(
	'provider adapter production asset registers both fresh-map and saved-reauthorization operations',
	'' !== $adapter
		&& str_contains( $adapter, "registerFulfillmentProviderAdapter( 'ecpay'" )
		&& str_contains( $adapter, 'selectStore' )
		&& str_contains( $adapter, 'reauthorizeSavedStore' )
);
$check(
	'subscription callback renders a result-code-only redirect',
	0 === $exit
		&& '' === $stderr
		&& str_contains( $stdout, 'ys_ec_store_result' )
		&& str_contains( $stdout, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ123456' )
		&& ! str_contains( $stdout, 'RAW-SELECTION-TOKEN-MUST-NOT-RENDER' )
		&& ! str_contains( $stdout, '991234' )
		&& ! str_contains( $stdout, 'localStorage' )
		&& ! str_contains( $stdout, 'sessionStorage' )
		&& ! str_contains( $stdout, 'postMessage' )
);
$renderer_pos = strpos( $selector, 'private static function render_callback_page' );
$renderer = false !== $renderer_pos ? substr( $selector, $renderer_pos, 5200 ) : '';
$subscription_pos = strpos( $renderer, "if ( 'subscription' === \$context )" );
$serialization_pos = strpos( $renderer, '$json_data = wp_json_encode' );
$check(
	'subscription renderer branches before serializing provider store bytes',
	false !== $subscription_pos
		&& false !== $serialization_pos
		&& $subscription_pos < $serialization_pos
		&& str_contains( substr( $renderer, $subscription_pos, $serialization_pos - $subscription_pos ), 'window.location.replace' )
);

echo "\nv0.3.0 subscription account adapter contract: {$pass} PASS / {$fail} FAIL\n";
exit( $fail > 0 || 0 === $pass ? 1 : 0 );
