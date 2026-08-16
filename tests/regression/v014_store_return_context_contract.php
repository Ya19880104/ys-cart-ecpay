<?php
/**
 * ECPay store-map flow must preserve caller return context for one-page checkout.
 */

$root = dirname( __DIR__, 2 );
$pass = 0;
$fail = 0;
$bad  = [];

$read = static function ( string $path ) use ( $root ): string {
	$full = $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $path );
	return is_file( $full ) ? (string) file_get_contents( $full ) : '';
};

$check = static function ( string $name, bool $ok ) use ( &$pass, &$fail, &$bad ): void {
	if ( $ok ) {
		$pass++;
		echo "  PASS  {$name}\n";
		return;
	}
	$fail++;
	$bad[] = $name;
	echo "  FAIL  {$name}\n";
};

$main     = $read( 'ys-cart-ecpay.php' );
$plugin   = $read( 'src/Plugin.php' );
$selector = $read( 'src/Shipping/Ecpay/EcpayStoreSelector.php' );

preg_match( "/YS_CART_ECPAY_VERSION', '([0-9.]+)'/", $main, $v014_ver );
$check(
	'version >= 0.2.8 for store return context fix',
	version_compare( $v014_ver[1] ?? '0', '0.2.8', '>=' )
);

// 合流後（0.2.15 main）：build_map_form_data 多收 $payment_method（代收模式
// 仲裁用）——return context 語意不變。
$check(
	'map route accepts return_url/cart_scope and passes them to selector',
	strpos( $plugin, "\$cart_scope  = self::sanitize_cart_scope" ) !== false
		&& strpos( $plugin, "\$return_url  = esc_url_raw" ) !== false
		&& preg_match( '/build_map_form_data\(\s*\$shipping_id,\s*\$context,\s*\$order_id,\s*\$cart_scope,\s*\$return_url,\s*\$payment_method\s*\)/s', $plugin ) === 1
);

// 合流後：map transient 與 store payload 的 return context 欄位對齊為 8 空格
// 縮排；store payload 的 return_url 統一經 sanitize_return_url()（含
// wp_validate_redirect），不再直接抄 $store_info。
$check(
	'selector stores sanitized return context in map transient and store payload',
	strpos( $selector, "string \$cart_scope = 'default'" ) !== false
		&& strpos( $selector, "string \$return_url = ''" ) !== false
		&& strpos( $selector, "'cart_scope'        => \$cart_scope" ) !== false
		&& strpos( $selector, "'return_url'        => \$return_url" ) !== false
		&& strpos( $selector, 'sanitize_return_url' ) !== false
		&& strpos( $selector, "self::sanitize_return_url(\n\t\t\t\t(string) ( \$map_data['return_url'] ?? '' )" ) !== false
);

$check(
	'checkout callback redirects to caller page instead of hard-coded checkout',
	strpos( $selector, '$checkout_url = esc_url( $store_info[\'return_url\'] ?? self::checkout_url() );' ) !== false
		&& strpos( $selector, 'window.location.replace' ) !== false
);

if ( $fail > 0 ) {
	echo "\nECPay store return context contract failed:\n";
	foreach ( $bad as $item ) {
		echo " - {$item}\n";
	}
	exit( 1 );
}

echo "\nECPay store return context contract passed ({$pass} checks).\n";
