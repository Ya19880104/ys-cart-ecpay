<?php
/** Explicit per-family sources use only their chosen tuple and environment. No SQL connection. */
declare(strict_types=1);

namespace {

define( 'ABSPATH', __DIR__ . '/' );
define( 'YS_ECOMMERCE_TABLE_PREFIX', 'ys_ec_' );
$GLOBALS['v050_settings'] = [];

final class V050_Wpdb {
	public string $prefix = 'wp_';
	public string $last_error = '';
	public bool $read_error = false;
	public function prepare( string $sql, ...$args ): string {
		foreach ( $args as $arg ) {
			$sql = preg_replace( '/%s/', "'" . str_replace( "'", "''", (string) $arg ) . "'", $sql, 1 );
		}
		return $sql;
	}
	public function get_row( string $sql ): ?object {
		if ( $this->read_error ) {
			$this->last_error = 'synthetic read failure';
			return null;
		}
		if ( ! preg_match( "/setting_key = '([^']+)'/", $sql, $match ) ) {
			throw new \RuntimeException( 'Unexpected fixture query' );
		}
		return array_key_exists( $match[1], $GLOBALS['v050_settings'] )
			? (object) [ 'id' => 1, 'setting_value' => $GLOBALS['v050_settings'][ $match[1] ] ] : null;
	}
}
$GLOBALS['wpdb'] = new V050_Wpdb();
function __( $text, $domain = null ) { return (string) $text; }
}

namespace YangSheep\Ecommerce {
final class YSEcommerce {
	public static function get_instance(): self { static $instance; return $instance ??= new self(); }
	public function get_setting( string $key, $default = '' ) { return $GLOBALS['v050_settings'][ $key ] ?? $default; }
}
}

namespace YangSheep\Ecommerce\Utils {
final class YSCrypto {
	public static function decrypt_from_storage( string $stored ): string {
		return str_starts_with( $stored, 'enc:' ) ? substr( $stored, 4 ) : '';
	}
}
}

namespace {
use YangSheep\YSCartEcpay\Support\Settings;

require_once dirname( __DIR__, 2 ) . '/src/Shipping/Ecpay/EcpayShippingCatalog.php';
require_once dirname( __DIR__, 2 ) . '/src/Support/Settings.php';

$pass = 0;
$fail = 0;
function v050_check( string $name, bool $ok ): void {
	global $pass, $fail;
	if ( $ok ) { ++$pass; } else { ++$fail; }
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . "\n";
}
function v050_reset( array $overrides = [] ): void {
	$GLOBALS['wpdb']->read_error = false;
	$GLOBALS['v050_settings'] = $overrides + [
		'ys_ec_ecpay_payment_test_mode' => '0',
		'ys_ec_ecpay_payment_merchant_id' => 'PAY-MID',
		'ys_ec_ecpay_payment_hash_key' => 'enc:PAY-KEY',
		'ys_ec_ecpay_payment_hash_iv' => 'enc:PAY-IV',
		'ys_ec_ecpay_logistics_b2c_home_test_mode' => '1',
		'ys_ec_ecpay_logistics_b2c_home_merchant_id' => 'B2C-MID',
		'ys_ec_ecpay_logistics_b2c_home_hash_key' => 'enc:B2C-KEY',
		'ys_ec_ecpay_logistics_b2c_home_hash_iv' => 'enc:B2C-IV',
		'ys_ec_ecpay_logistics_c2c_test_mode' => '1',
		'ys_ec_ecpay_logistics_c2c_merchant_id' => 'C2C-MID',
		'ys_ec_ecpay_logistics_c2c_hash_key' => 'enc:C2C-KEY',
		'ys_ec_ecpay_logistics_c2c_hash_iv' => 'enc:C2C-IV',
	];
}
function v050_tuple( array $actual, string $merchant, string $key, string $iv, bool $test ): bool {
	return $actual['merchant_id'] === $merchant && $actual['hash_key'] === $key
		&& $actual['hash_iv'] === $iv && $actual['test_mode'] === $test;
}
function v050_empty( array $actual ): bool {
	return v050_tuple( $actual, '', '', '', true );
}
function v050_mode( string $family, ?array $pending = null ) {
	return method_exists( Settings::class, 'logistics_source_mode' )
		? Settings::logistics_source_mode( $family, $pending ) : 'MISSING_API';
}

$b2c_source = 'ys_ec_ecpay_logistics_b2c_home_source';
$c2c_source = 'ys_ec_ecpay_logistics_c2c_source';
v050_reset( [ $b2c_source => 'payment', $c2c_source => 'separate' ] );
$before = $GLOBALS['v050_settings'];
v050_check( 'mixed B2C payment ignores complete hidden B2C tuple', v050_tuple( Settings::logistics_credentials_for_channel( 'b2c' ), 'PAY-MID', 'PAY-KEY', 'PAY-IV', false ) );
v050_check( 'mixed C2C separate uses own tuple and environment', v050_tuple( Settings::logistics_credentials_for_channel( 'c2c' ), 'C2C-MID', 'C2C-KEY', 'C2C-IV', true ) );
v050_check( 'default HOME uses B2C source', v050_tuple( Settings::logistics_credentials_for_channel( 'home' ), 'PAY-MID', 'PAY-KEY', 'PAY-IV', false ) );
v050_check( 'HOME C2C selector uses C2C source', v050_tuple( Settings::logistics_credentials_for_channel( 'home', [ Settings::HOME_CREDENTIAL_FAMILY => 'c2c' ] ), 'C2C-MID', 'C2C-KEY', 'C2C-IV', true ) );
v050_check( 'source resolution preserves stored hidden values', $before === $GLOBALS['v050_settings'] );
v050_check( 'source API exposes selected payment mode', 'payment' === v050_mode( 'b2c_home' ) );
$payment_test = [ 'ys_ec_ecpay_payment_test_mode' => '1' ];
v050_check( 'payment test switch moves shared B2C to test', v050_tuple( Settings::logistics_credentials_for_channel( 'b2c', $payment_test ), 'PAY-MID', 'PAY-KEY', 'PAY-IV', true ) );
$separate_live = [ 'ys_ec_ecpay_logistics_c2c_test_mode' => '0' ];
v050_check( 'payment test switch does not override separate live C2C', v050_tuple( Settings::logistics_credentials_for_channel( 'c2c', $payment_test + $separate_live ), 'C2C-MID', 'C2C-KEY', 'C2C-IV', false ) );
v050_check( 'pending shared tuple is decrypted as one source', v050_tuple( Settings::logistics_credentials_for_channel( 'b2c', [ 'ys_ec_ecpay_payment_hash_key' => 'enc:PENDING-KEY' ] ), 'PAY-MID', 'PENDING-KEY', 'PAY-IV', false ) );
v050_check( 'disabled rejects otherwise complete credentials', v050_empty( Settings::logistics_credentials_for_channel( 'b2c', [ $b2c_source => 'disabled' ] ) ) );
v050_check( 'pending separate overrides persisted shared source', v050_tuple( Settings::logistics_credentials_for_channel( 'b2c', [ $b2c_source => 'separate' ] ), 'B2C-MID', 'B2C-KEY', 'B2C-IV', true ) );
v050_check( 'pending removal restores absent-mode legacy resolver', v050_tuple( Settings::logistics_credentials_for_channel( 'b2c', [ $b2c_source => null ] ), 'B2C-MID', 'B2C-KEY', 'B2C-IV', true ) );
v050_check( 'partial shared payment refuses hidden complete custom fallback', v050_empty( Settings::logistics_credentials_for_channel( 'b2c', [ 'ys_ec_ecpay_payment_hash_iv' => '' ] ) ) );
v050_check( 'partial separate refuses complete payment fallback', v050_empty( Settings::logistics_credentials_for_channel( 'c2c', [ 'ys_ec_ecpay_logistics_c2c_hash_iv' => '' ] ) ) );
$empty_groups = [ 'ys_ec_ecpay_logistics_reuse_payment' => '1' ];
foreach ( [ 'b2c_home', 'c2c' ] as $family ) {
	foreach ( [ 'merchant_id', 'hash_key', 'hash_iv' ] as $field ) {
		$empty_groups[ "ys_ec_ecpay_logistics_{$family}_{$field}" ] = '';
	}
}
v050_check( 'empty separate refuses legacy payment-reuse fallback', v050_empty( Settings::logistics_credentials_for_channel( 'c2c', $empty_groups ) ) );
v050_check( 'absent source control permits the otherwise identical legacy reuse', v050_tuple( Settings::logistics_credentials_for_channel( 'c2c', [ $c2c_source => null ] + $empty_groups ), 'PAY-MID', 'PAY-KEY', 'PAY-IV', false ) );
foreach ( [ '', 'unknown' ] as $invalid ) {
	v050_reset( [ $b2c_source => $invalid ] );
	v050_check( 'persisted invalid mode fails closed: ' . ( '' === $invalid ? 'empty' : 'unknown' ), '' === v050_mode( 'b2c_home' ) && v050_empty( Settings::logistics_credentials_for_channel( 'b2c' ) ) );
}
v050_reset( [ $b2c_source => 'payment' ] );
v050_check( 'pending invalid mode fails closed', '' === v050_mode( 'b2c_home', [ $b2c_source => 'unknown' ] ) && v050_empty( Settings::logistics_credentials_for_channel( 'b2c', [ $b2c_source => 'unknown' ] ) ) );
v050_check( 'pending malformed mode fails closed', '' === v050_mode( 'b2c_home', [ $b2c_source => [] ] ) );
v050_check( 'unknown family fails closed', '' === v050_mode( 'other' ) );
$GLOBALS['wpdb']->read_error = true;
v050_check( 'unavailable mode read does not become absent legacy fallback', '' === v050_mode( 'b2c_home' ) && v050_empty( Settings::logistics_credentials_for_channel( 'b2c' ) ) );

v050_reset();
v050_check( 'missing source row reports legacy mode', null === v050_mode( 'b2c_home' ) );
v050_check( 'absent mode preserves explicit legacy group', v050_tuple( Settings::logistics_credentials_for_channel( 'b2c' ), 'B2C-MID', 'B2C-KEY', 'B2C-IV', true ) );
v050_check( 'absent mode preserves complete-group duplicate refusal', v050_empty( Settings::logistics_credentials_for_channel( 'b2c', [ 'ys_ec_ecpay_logistics_c2c_merchant_id' => 'B2C-MID', 'ys_ec_ecpay_logistics_c2c_hash_key' => 'enc:B2C-KEY', 'ys_ec_ecpay_logistics_c2c_hash_iv' => 'enc:B2C-IV' ] ) ) );
$GLOBALS['v050_settings'] = array_filter( $GLOBALS['v050_settings'], static fn( $key ) => str_starts_with( $key, 'ys_ec_ecpay_payment_' ), ARRAY_FILTER_USE_KEY );
$GLOBALS['v050_settings']['ys_ec_ecpay_logistics_reuse_payment'] = '1';
foreach ( [ 'b2c', 'home', 'c2c' ] as $channel ) {
	v050_check( 'absent mode preserves old payment reuse: ' . $channel, v050_tuple( Settings::logistics_credentials_for_channel( $channel ), 'PAY-MID', 'PAY-KEY', 'PAY-IV', false ) );
}
echo "RESULT: {$pass} pass / {$fail} fail\n";
exit( $fail > 0 ? 1 : 0 );
}
