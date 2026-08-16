<?php
/**
 * v0.2.16 — 顯式「物流重用金流憑證」模式契約。
 *
 * 生效條件（全部成立才回金流 tuple，否則 fail-closed）：
 *   開關='1' ＋ 三組物流憑證（b2c_home/c2c/legacy）全空 ＋ 金流 tuple 完整；
 *   環境（test_mode）以金流為單一真相；partial 一律不進 reuse（回各自原規則）。
 */

declare(strict_types=1);

namespace {
	defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );
	defined( 'YS_ECOMMERCE_TABLE_PREFIX' ) || define( 'YS_ECOMMERCE_TABLE_PREFIX', 'ys_ec_' );

	$GLOBALS['v0216_settings'] = [];

	// home_credential_family() 直讀 settings 表（0.2.15 fail-closed 設計）→ stub wpdb
	class V0216_Wpdb {
		public string $prefix = 'wp_';
		public string $last_error = '';
		public function prepare( $sql, ...$args ) {
			foreach ( $args as $a ) { $sql = preg_replace( '/%s/', "'" . $a . "'", $sql, 1 ); }
			return $sql;
		}
		public function get_row( $sql ) {
			if ( preg_match( "/setting_key = '([^']+)'/", (string) $sql, $m ) ) {
				$key = $m[1];
				if ( array_key_exists( $key, $GLOBALS['v0216_settings'] ) ) {
					return (object) [ 'id' => 1, 'setting_value' => $GLOBALS['v0216_settings'][ $key ] ];
				}
			}
			return null; // 該列不存在＝沿用歷史預設 b2c_home
		}
	}
	$GLOBALS['wpdb'] = new V0216_Wpdb();

	// ── WP stubs ──
	function get_option( string $k, $d = false ) { return $d; }
	function update_option( string $k, $v ): bool { return true; }
	function __( $t, $d = null ) { return (string) $t; }
}

// YSEcommerce settings 面（Settings::get 經 core get_setting）
namespace YangSheep\Ecommerce {
	class YSEcommerce {
		private static $i = null;
		public static function get_instance() { return self::$i ??= new self(); }
		public function get_setting( string $key, $default = '' ) {
			return $GLOBALS['v0216_settings'][ $key ] ?? $default;
		}
		public function update_setting( string $key, $value ): bool {
			$GLOBALS['v0216_settings'][ $key ] = $value;
			return true;
		}
	}
}

namespace YangSheep\Ecommerce\Utils {
	class YSCrypto {
		public static function encrypt_for_storage( string $p ): string { return 'enc:' . $p; }
		public static function decrypt_from_storage( string $s ): string {
			return 0 === strpos( $s, 'enc:' ) ? substr( $s, 4 ) : '';
		}
	}
}

namespace {

$root = dirname( __DIR__, 2 );
require_once $root . '/src/Shipping/Ecpay/EcpayShippingCatalog.php';
require_once $root . '/src/Support/Settings.php';

use YangSheep\YSCartEcpay\Support\Settings;

$pass = 0; $fail = 0;
function v0216_check( string $name, bool $ok, string $detail = '' ): void {
	global $pass, $fail;
	if ( $ok ) { $pass++; echo "PASS  $name\n"; } else { $fail++; echo "FAIL  $name  [$detail]\n"; }
}

function v0216_reset( array $overrides = [] ): void {
	$GLOBALS['v0216_settings'] = $overrides;
}

function v0216_set_payment( bool $test_mode = false ): void {
	$GLOBALS['v0216_settings'] += [
		'ys_ec_ecpay_payment_test_mode'   => $test_mode ? '1' : '0',
		'ys_ec_ecpay_payment_merchant_id' => '3507531',
		'ys_ec_ecpay_payment_hash_key'    => 'enc:PAYKEY',
		'ys_ec_ecpay_payment_hash_iv'     => 'enc:PAYIV',
	];
}

// ── R1 開關關＋全空 → 三 channel 全 empty（現狀不變） ──
v0216_reset();
v0216_set_payment();
foreach ( [ 'b2c', 'home', 'c2c' ] as $ch ) {
	$c = Settings::logistics_credentials_for_channel( $ch );
	v0216_check( "R1 switch-off keeps $ch empty", '' === $c['merchant_id'] );
}

// ── R2 開關開＋物流全空＋金流完整 → 三 channel 都拿到金流 tuple（test_mode=金流的） ──
v0216_reset( [ 'ys_ec_ecpay_logistics_reuse_payment' => '1' ] );
v0216_set_payment( true );
foreach ( [ 'b2c', 'home', 'c2c' ] as $ch ) {
	$c = Settings::logistics_credentials_for_channel( $ch );
	v0216_check(
		"R2 reuse serves $ch with payment tuple",
		'3507531' === $c['merchant_id'] && 'PAYKEY' === $c['hash_key'] && 'PAYIV' === $c['hash_iv'] && true === $c['test_mode']
	);
}

// ── R3 開關開＋金流不完整 → empty（fail-closed，不落其他來源） ──
v0216_reset( [
	'ys_ec_ecpay_logistics_reuse_payment' => '1',
	'ys_ec_ecpay_payment_merchant_id'     => '3507531',
	// 缺 hash_key/iv
] );
$c = Settings::logistics_credentials_for_channel( 'c2c' );
v0216_check( 'R3 incomplete payment tuple fails closed', '' === $c['merchant_id'] );

// ── R4 開關開＋任一物流組 partial → 不進 reuse（各自原規則＝partial 拒絕 empty） ──
v0216_reset( [
	'ys_ec_ecpay_logistics_reuse_payment'     => '1',
	'ys_ec_ecpay_logistics_c2c_merchant_id'   => '2000933', // partial：只有 MID
] );
v0216_set_payment();
$c = Settings::logistics_credentials_for_channel( 'c2c' );
v0216_check( 'R4 partial explicit group blocks reuse (fail-closed)', '' === $c['merchant_id'] );

// ── R5 開關開＋legacy 有值 → 不進 reuse，走 legacy 原有 unambiguous 規則 ──
v0216_reset( [
	'ys_ec_ecpay_logistics_reuse_payment' => '1',
	'ys_ec_ecpay_logistics_merchant_id'   => '9990001',
	'ys_ec_ecpay_logistics_hash_key'      => 'enc:LEGKEY',
	'ys_ec_ecpay_logistics_hash_iv'       => 'enc:LEGIV',
] );
v0216_set_payment();
$c = Settings::logistics_credentials_for_channel( 'c2c' );
v0216_check( 'R5 legacy tuple present bypasses reuse (legacy rules apply)', 'PAYKEY' !== $c['hash_key'], 'got=' . $c['hash_key'] );

// ── R6 顯式 group 完整時優先於 reuse（reuse 只是最後顯式層） ──
v0216_reset( [
	'ys_ec_ecpay_logistics_reuse_payment'    => '1',
	'ys_ec_ecpay_logistics_c2c_test_mode'    => '1',
	'ys_ec_ecpay_logistics_c2c_merchant_id'  => '2000933',
	'ys_ec_ecpay_logistics_c2c_hash_key'     => 'enc:C2CKEY',
	'ys_ec_ecpay_logistics_c2c_hash_iv'      => 'enc:C2CIV',
] );
v0216_set_payment();
$c = Settings::logistics_credentials_for_channel( 'c2c' );
v0216_check( 'R6 explicit c2c group wins over reuse', '2000933' === $c['merchant_id'] && 'C2CKEY' === $c['hash_key'] );

// ── R7 HOME family=c2c 與 reuse 正交：全空＋開關開 → HOME 拿金流 tuple ──
v0216_reset( [
	'ys_ec_ecpay_logistics_reuse_payment' => '1',
	'ys_ec_ecpay_home_credential_family'  => 'c2c',
] );
v0216_set_payment();
$c = Settings::logistics_credentials_for_channel( 'home' );
v0216_check( 'R7 home family=c2c orthogonal to reuse', '3507531' === $c['merchant_id'] );

echo "\nRESULT: $pass pass / $fail fail\n";
exit( $fail > 0 ? 1 : 0 );

}
