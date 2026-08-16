<?php
/**
 * v0.2.16 — api tab 原子管線（R13 compute-first）＋ provider 維護鎖契約。
 *
 * L1  全等儲存＝no-op（desired 空、零寫入）
 * L2  signer 變更＋active label → 拒絕，且**零寫入**（compute-first：拒絕的
 *     request 連一個暫時值都沒寫過——R12 的「寫了再回滾」出網窗口不存在）
 * L3  authority 查詢失敗 → 拒絕＋零寫入
 * L4  乾淨狀態開 reuse（payment 空→effective signer 不變）→ commit＋readback
 * L4b 開 reuse 且 payment 完整（signer 會變）＋active label → 拒絕＋零寫入
 * L5  mid-commit 寫入失敗 → 全量回滾：已寫鍵還原、**原本 absent 的鍵恢復
 *     absent（delete，不是寫 ''）**——test_mode 語意不被 '' 汙染
 * L6a clear 整組四鍵清空（維護態 commit）
 * L6b secret 空白＝保留（key 不進 desired）
 * G1  reuse 生效中旋轉 payment key＋active label → 拒絕＋OLDPAY 全程不動
 * G2  clear 使用中的 explicit 組（fallback 切換）＋active label → 拒絕＋零寫入
 * G3  signer 變更＋任一方法啟用 → 拒絕
 * G4  維護態（全停用＋零 label）clear → commit
 * G5  非 signer 變更＋active labels → 放行（不觸發 gate）
 * G6  reuse toggle 會改變 signer＋方法啟用 → 拒絕
 * G8  rollback 單鍵失敗 → signer_gate_rollback_failed；其餘鍵仍還原（全量掃、不早退）
 * P1  維護鎖持有中 → create_order 拒送＋零 HTTP（與設定 commit 共用同一把鎖）
 * P2  對照組：無鎖同 fixture → 通過 pre-send gate（在後續欄位驗證才中止）
 * P3  逾期鎖 → is_held()=false（lease 語意）
 * P4  鎖四件套：NX 取得／owner-conditional 釋放／被接管後 fence=false
 */

declare(strict_types=1);

namespace {
	defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );
	defined( 'YS_ECOMMERCE_TABLE_PREFIX' ) || define( 'YS_ECOMMERCE_TABLE_PREFIX', 'ys_ec_' );

	$GLOBALS['v0216l_settings']         = []; // settings 表
	$GLOBALS['v0216l_options']          = []; // wp_options（維護鎖）
	$GLOBALS['v0216l_update_fail_keys'] = [];
	$GLOBALS['v0216l_update_fail_once_keys'] = [];
	$GLOBALS['v0216l_delete_fail_keys'] = [];
	$GLOBALS['v0216l_write_log']        = []; // update_setting 呼叫紀錄（零寫入證明）
	$GLOBALS['v0216l_http_calls']       = 0;
	$GLOBALS['v0216l_cache_probe']      = false;
	$GLOBALS['v0216l_cache_writer_acquired'] = false;

	function get_option( string $k, $d = false ) { return $d; }
	function update_option( string $k, $v ): bool { return true; }
	function __( $t, $d = null ) { return (string) $t; }
	function sanitize_text_field( $t ) { return trim( (string) $t ); }
	function sanitize_key( $t ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $t ) ) ); }
	function wp_unslash( $v ) { return $v; }
	function current_time( string $f ) { return date( $f ); }
	function rest_url( string $p = '' ) { return 'https://stub.local/wp-json/' . $p; }
	function wp_strip_all_tags( $t ) { return (string) $t; }
	function wp_json_encode( $value, int $flags = 0, int $depth = 512 ) { return json_encode( $value, $flags, $depth ); }
	function get_transient( string $key ) {
		if ( $GLOBALS['v0216l_cache_probe'] ) {
			$owner = \YangSheep\YSCartEcpay\Support\ProviderMaintenanceLock::acquire();
			$GLOBALS['v0216l_cache_writer_acquired'] = null !== $owner;
			if ( null !== $owner ) {
				\YangSheep\YSCartEcpay\Support\ProviderMaintenanceLock::release( $owner );
			}
		}
		return [ 'OLD' => [ 'store_id' => 'OLD' ] ];
	}

	/** settings 表＋wp_options（reader/writer lease）＋authority counts 的 wpdb stub */
	class V0216L_Wpdb {
		public string $prefix = 'wp_';
		public string $options = 'wp_options';
		public string $last_error = '';
		public $active_count = 0;
		public $legacy_count = 0;
		public $pending_orders = 0;   // R14：unresolved ECPay payment attempts
		public $offline_payment_orders = 0;
		public $timeout_orders = 0;
		public $cancelled_orders = 0;
		public bool $error_mode = false; // 只影響 authority／orders 查詢
		public bool $lock_read_error = false;
		public bool $takeover_before_marker_delete = false;
		public bool $complete_b2c_before_writer_insert = false;
		public array $option_insert_fail_names = [];
		public array $option_delete_fail_names = [];
		public function prepare( $sql, ...$args ) {
			foreach ( $args as $a ) {
				$sql = preg_replace( '/%s/', "'" . str_replace( "'", "''", (string) $a ) . "'", (string) $sql, 1 );
			}
			return $sql;
		}
		public function esc_like( $text ): string {
			return addcslashes( (string) $text, '_%\\' );
		}
		public function get_var( $sql ) {
			$sql = (string) $sql;
			if ( preg_match( "/SELECT option_value FROM wp_options WHERE option_name = '([^']+)'/", $sql, $m ) ) {
				if ( $this->lock_read_error ) {
					$this->last_error = 'injected option read failure';
					return null;
				}
				$this->last_error = '';
				return $GLOBALS['v0216l_options'][ $m[1] ] ?? null;
			}
			if ( preg_match( "/SELECT setting_value FROM wp_ys_ec_settings WHERE setting_key = '([^']+)'/", $sql, $m ) ) {
				$this->last_error = '';
				return array_key_exists( $m[1], $GLOBALS['v0216l_settings'] )
					? (string) $GLOBALS['v0216l_settings'][ $m[1] ]
					: null;
			}
			if ( $this->error_mode ) {
				$this->last_error = 'injected';
				return null;
			}
			$this->last_error = '';
			if ( false !== strpos( $sql, 'FROM wp_ys_ec_orders' ) ) {
				return (string) ( $this->pending_orders
					+ ( false !== strpos( $sql, "'offline_payment'" ) ? $this->offline_payment_orders : 0 )
					+ ( false !== strpos( $sql, "'timeout'" ) ? $this->timeout_orders : 0 )
					+ ( false !== strpos( $sql, "'cancelled'" ) ? $this->cancelled_orders : 0 ) );
			}
			if ( false !== strpos( $sql, 'active_order_key IS NOT NULL' ) ) {
				return (string) $this->active_count;
			}
			if ( false !== strpos( $sql, 'a.id IS NULL' ) ) {
				return (string) $this->legacy_count;
			}
			return null;
		}
		public function get_results( $sql ) {
			if ( preg_match( "/WHERE option_name LIKE '([^']+)'/", (string) $sql, $m ) ) {
				$like  = $m[1];
				$regex = '';
				for ( $i = 0, $n = strlen( $like ); $i < $n; $i++ ) {
					$char = $like[ $i ];
					if ( '\\' === $char && $i + 1 < $n ) {
						$regex .= preg_quote( $like[ ++$i ], '/' );
					} elseif ( '%' === $char ) {
						$regex .= '.*';
					} elseif ( '_' === $char ) {
						$regex .= '.';
					} else {
						$regex .= preg_quote( $char, '/' );
					}
				}
				$out    = [];
				foreach ( $GLOBALS['v0216l_options'] as $k => $v ) {
					if ( preg_match( '/^' . $regex . '$/D', (string) $k ) ) {
						$out[] = (object) [ 'option_name' => $k, 'option_value' => $v ];
					}
				}
				return $out;
			}
			return [];
		}
		public function get_row( $sql ) {
			if ( preg_match( "/setting_key = '([^']+)'/", (string) $sql, $m )
				&& array_key_exists( $m[1], $GLOBALS['v0216l_settings'] ) ) {
				return (object) [ 'id' => 1, 'setting_value' => $GLOBALS['v0216l_settings'][ $m[1] ] ];
			}
			return null; // 該列不存在＝沿用歷史預設 b2c_home
		}
		public function insert( $table, array $data ) {
			if ( 'wp_options' === $table && isset( $data['option_name'] ) ) {
				if ( \YangSheep\YSCartEcpay\Support\ProviderMaintenanceLock::OPTION === (string) $data['option_name']
					&& $this->complete_b2c_before_writer_insert ) {
					$this->complete_b2c_before_writer_insert = false;
					// Request A committed between Request B's pre-lock compute and acquire.
					$GLOBALS['v0216l_settings']['ys_ec_ecpay_logistics_b2c_home_hash_key'] = 'enc:QKEY';
					$GLOBALS['v0216l_settings']['ys_ec_ecpay_logistics_b2c_home_hash_iv']  = 'enc:QIV';
				}
				if ( in_array( (string) $data['option_name'], $this->option_insert_fail_names, true ) ) {
					return false;
				}
				if ( array_key_exists( (string) $data['option_name'], $GLOBALS['v0216l_options'] ) ) {
					return false; // 唯一鍵撞擊＝NX 失敗
				}
				$GLOBALS['v0216l_options'][ (string) $data['option_name'] ] = (string) ( $data['option_value'] ?? '' );
				return 1;
			}
			return false;
		}
		public function query( $sql ) {
			$sql = (string) $sql;
			if ( false !== strpos( $sql, 'DELETE crashed FROM wp_options AS crashed' ) ) {
				preg_match_all( "/'([^']*)'/", $sql, $quoted );
				[ $writer_name, $writer_value, $marker_name, $marker_value ] = $quoted[1] ?? [];
				if ( $this->takeover_before_marker_delete ) {
					$this->takeover_before_marker_delete = false;
					$GLOBALS['v0216l_options'][ \YangSheep\YSCartEcpay\Support\ProviderMaintenanceLock::OPTION ] = 'newwriter|' . time() . '|takeover';
				}
				if ( in_array( (string) $marker_name, $this->option_delete_fail_names, true ) ) {
					return false;
				}
				if ( ( $GLOBALS['v0216l_options'][ $writer_name ] ?? null ) === $writer_value
					&& ( $GLOBALS['v0216l_options'][ $marker_name ] ?? null ) === $marker_value ) {
					unset( $GLOBALS['v0216l_options'][ $marker_name ] );
					return 1;
				}
				return 0;
			}
			if ( preg_match( "/UPDATE wp_options SET option_value = '([^']+)' WHERE option_name = '([^']+)' AND option_value = '([^']+)'/", $sql, $m ) ) {
				if ( ( $GLOBALS['v0216l_options'][ $m[2] ] ?? null ) === $m[3] ) {
					$GLOBALS['v0216l_options'][ $m[2] ] = $m[1];
					return 1;
				}
				return 0;
			}
			if ( preg_match( "/UPDATE wp_options SET option_value = '([^']+)' WHERE option_name = '([^']+)' AND option_value LIKE '([^']+)'/", $sql, $m ) ) {
				$cur    = $GLOBALS['v0216l_options'][ $m[2] ] ?? null;
				$prefix = rtrim( $m[3], '%' );
				if ( null !== $cur && 0 === strpos( (string) $cur, $prefix ) ) {
					$GLOBALS['v0216l_options'][ $m[2] ] = $m[1];
					return 1;
				}
				return 0;
			}
			if ( preg_match( "/DELETE FROM wp_options WHERE option_name = '([^']+)' AND option_value LIKE '([^']+)'/", $sql, $m ) ) {
				$cur    = $GLOBALS['v0216l_options'][ $m[1] ] ?? null;
				$prefix = rtrim( $m[2], '%' );
				if ( null !== $cur && 0 === strpos( (string) $cur, $prefix ) ) {
					unset( $GLOBALS['v0216l_options'][ $m[1] ] );
					return 1;
				}
				return 0;
			}
			// R14：value-exact 條件式收割（writer/reader reap）
			if ( preg_match( "/DELETE FROM wp_options WHERE option_name = '([^']+)' AND option_value = '([^']+)'/", $sql, $m ) ) {
				if ( \YangSheep\YSCartEcpay\Support\ProviderMaintenanceLock::CRASHED_FLAG === $m[1] && $this->takeover_before_marker_delete ) {
					$this->takeover_before_marker_delete = false;
					$GLOBALS['v0216l_options'][ \YangSheep\YSCartEcpay\Support\ProviderMaintenanceLock::OPTION ] = 'newwriter|' . time() . '|takeover';
				}
				if ( in_array( $m[1], $this->option_delete_fail_names, true ) ) {
					return false;
				}
				if ( ( $GLOBALS['v0216l_options'][ $m[1] ] ?? null ) === $m[2] ) {
					unset( $GLOBALS['v0216l_options'][ $m[1] ] );
					return 1;
				}
				return 0;
			}
			// R14：無條件 DELETE（clear_crashed_flag）
			if ( preg_match( "/DELETE FROM wp_options WHERE option_name = '([^']+)'$/", $sql, $m ) ) {
				if ( in_array( $m[1], $this->option_delete_fail_names, true ) ) {
					return false;
				}
				$existed = array_key_exists( $m[1], $GLOBALS['v0216l_options'] );
				unset( $GLOBALS['v0216l_options'][ $m[1] ] );
				return $existed ? 1 : 0;
			}
			return 0;
		}
		public function delete( $table, array $where ) {
			if ( 'wp_ys_ec_settings' === $table && isset( $where['setting_key'] ) ) {
				$key = (string) $where['setting_key'];
				if ( in_array( $key, $GLOBALS['v0216l_delete_fail_keys'], true ) ) {
					return false; // 注入：刪除失敗
				}
				unset( $GLOBALS['v0216l_settings'][ $key ] );
				return 1;
			}
			return 0;
		}
	}
	$GLOBALS['wpdb'] = new V0216L_Wpdb();

	// HttpFormClient 底層（若 pre-send gate 失守才會走到；記錄呼叫數）
	function wp_remote_post( $url, $args = [] ) {
		$GLOBALS['v0216l_http_calls']++;
		return new WP_Error( 'stub', 'no network in tests' );
	}
	function is_wp_error( $thing ): bool { return $thing instanceof WP_Error; }
	function wp_remote_retrieve_body( $r ) { return ''; }
	function wp_remote_retrieve_response_code( $r ) { return 0; }
	class WP_Error {
		private string $msg;
		public function __construct( $code = '', $message = '', $data = null ) { $this->msg = (string) $message; }
		public function get_error_message(): string { return $this->msg; }
	}
}

namespace YangSheep\Ecommerce {
	class YSEcommerce {
		private static $i = null;
		public static function get_instance() { return self::$i ??= new self(); }
		public function get_setting( string $key, $default = '' ) {
			return $GLOBALS['v0216l_settings'][ $key ] ?? $default;
		}
		public function update_setting( string $key, $value ): bool {
			$GLOBALS['v0216l_write_log'][] = $key;
			if ( in_array( $key, $GLOBALS['v0216l_update_fail_once_keys'], true ) ) {
				$GLOBALS['v0216l_update_fail_once_keys'] = array_values( array_diff( $GLOBALS['v0216l_update_fail_once_keys'], [ $key ] ) );
				return false;
			}
			if ( in_array( $key, $GLOBALS['v0216l_update_fail_keys'], true ) ) {
				return false; // 注入：寫入被丟棄
			}
			$GLOBALS['v0216l_settings'][ $key ] = $value;
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

namespace YangSheep\Ecommerce\Shipping {
	interface YSShippingInterface {}
}

namespace {

$root = dirname( __DIR__, 2 );
require_once $root . '/src/Shipping/Ecpay/EcpayShippingCatalog.php';
require_once $root . '/src/Support/Settings.php';
require_once $root . '/src/Support/ProviderMaintenanceLock.php';
require_once $root . '/src/Admin/EcpaySettings.php';
require_once $root . '/src/Support/ShippingMethodOperability.php';
require_once $root . '/src/Support/CheckMacValue.php';
require_once $root . '/src/Support/HttpFormClient.php';
require_once $root . '/src/Shipping/Ecpay/EcpayShipping.php';
require_once $root . '/src/Shipping/Ecpay/EcpayShippingRequester.php';
require_once $root . '/src/Shipping/Ecpay/EcpayStoreDirectory.php';

use YangSheep\YSCartEcpay\Admin\EcpaySettings;
use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShipping;
use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShippingCatalog;
use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShippingRequester;
use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayStoreDirectory;
use YangSheep\YSCartEcpay\Support\ProviderMaintenanceLock;
use YangSheep\YSCartEcpay\Support\Settings;

$pass = 0; $fail = 0;
function v0216l_check( string $name, bool $ok, string $detail = '' ): void {
	global $pass, $fail;
	if ( $ok ) { $pass++; echo "PASS  $name\n"; } else { $fail++; echo "FAIL  $name  [$detail]\n"; }
}

/** Read one top-level class method body for omission/order contracts. */
function v0216l_method_source( string $relative_file, string $method ): string {
	global $root;
	$source = (string) file_get_contents( $root . '/' . $relative_file );
	$start  = strpos( $source, 'function ' . $method . '(' );
	if ( false === $start ) {
		return '';
	}
	$tail = substr( $source, $start );
	if ( preg_match( '/\n\t(?:public|private|protected)\s+(?:static\s+)?function\s+[A-Za-z_]/', substr( $tail, 1 ), $m, PREG_OFFSET_CAPTURE ) ) {
		$tail = substr( $tail, 0, 1 + (int) $m[0][1] );
	}

	return $tail;
}

function v0216l_fence_precedes( string $source, string $effect, int $minimum_fences = 1 ): bool {
	$fence = 'ProviderMaintenanceLock::reader_fence(';
	$effect_position = strpos( $source, $effect );
	return false !== $effect_position
		&& substr_count( substr( $source, 0, $effect_position ), $fence ) >= $minimum_fences;
}

$apply = new ReflectionMethod( EcpaySettings::class, 'apply_api_tab_atomically' );
$apply->setAccessible( true );

/** @return string settings_error（''＝成功）；$provider_enabled 由 $_POST['ys_ec_ecpay_enabled'] 推 */
function v0216l_apply(): string {
	global $apply;
	return (string) $apply->invoke( null, isset( $_POST['ys_ec_ecpay_enabled'] ) );
}

function v0216l_reset( array $settings = [] ): void {
	$GLOBALS['v0216l_settings']         = $settings;
	$GLOBALS['v0216l_options']          = [];
	$GLOBALS['v0216l_update_fail_keys'] = [];
	$GLOBALS['v0216l_update_fail_once_keys'] = [];
	$GLOBALS['v0216l_delete_fail_keys'] = [];
	$GLOBALS['v0216l_write_log']        = [];
	$GLOBALS['v0216l_http_calls']       = 0;
	$GLOBALS['v0216l_cache_probe']      = false;
	$GLOBALS['v0216l_cache_writer_acquired'] = false;
	$GLOBALS['wpdb']->active_count      = 0;
	$GLOBALS['wpdb']->legacy_count      = 0;
	$GLOBALS['wpdb']->pending_orders    = 0;
	$GLOBALS['wpdb']->offline_payment_orders = 0;
	$GLOBALS['wpdb']->timeout_orders    = 0;
	$GLOBALS['wpdb']->cancelled_orders  = 0;
	$GLOBALS['wpdb']->error_mode        = false;
	$GLOBALS['wpdb']->lock_read_error   = false;
	$GLOBALS['wpdb']->takeover_before_marker_delete = false;
	$GLOBALS['wpdb']->complete_b2c_before_writer_insert = false;
	$GLOBALS['wpdb']->option_insert_fail_names = [];
	$GLOBALS['wpdb']->option_delete_fail_names = [];
	$_POST                              = [];
}

function v0216l_full_c2c(): array {
	return [
		'ys_ec_ecpay_logistics_c2c_test_mode'   => '1',
		'ys_ec_ecpay_logistics_c2c_merchant_id' => '2000933',
		'ys_ec_ecpay_logistics_c2c_hash_key'    => 'enc:C2CKEY',
		'ys_ec_ecpay_logistics_c2c_hash_iv'     => 'enc:C2CIV',
	];
}

function v0216l_full_payment( string $key = 'OLDPAY' ): array {
	return [
		'ys_ec_ecpay_payment_test_mode'   => '0',
		'ys_ec_ecpay_payment_merchant_id' => '3507531',
		'ys_ec_ecpay_payment_hash_key'    => 'enc:' . $key,
		'ys_ec_ecpay_payment_hash_iv'     => 'enc:OLDIV',
	];
}

// ── L1 全等儲存＝no-op：desired 空、零寫入 ──
v0216l_reset( [
	'ys_ec_ecpay_enabled'                        => '1',
	'ys_ec_ecpay_logistics_reuse_payment'        => '0',
	'ys_ec_ecpay_payment_test_mode'              => '1',
	'ys_ec_ecpay_payment_merchant_id'            => '',
	'ys_ec_ecpay_logistics_b2c_home_test_mode'   => '1',
	'ys_ec_ecpay_logistics_b2c_home_merchant_id' => '',
	'ys_ec_ecpay_logistics_c2c_test_mode'        => '1',
	'ys_ec_ecpay_logistics_c2c_merchant_id'      => '',
] );
$_POST = [
	'ys_ec_ecpay_enabled'                      => '1',
	'ys_ec_ecpay_payment_test_mode'            => '1',
	'ys_ec_ecpay_logistics_b2c_home_test_mode' => '1',
	'ys_ec_ecpay_logistics_c2c_test_mode'      => '1',
];
$r = v0216l_apply();
v0216l_check( 'L1 identical save is a no-op with zero writes',
	'' === $r && [] === $GLOBALS['v0216l_write_log'], "r=$r writes=" . count( $GLOBALS['v0216l_write_log'] ) );

// ── L2 signer 變更＋active label → 拒絕＋零寫入（compute-first 核心證明）──
v0216l_reset( v0216l_full_c2c() );
$GLOBALS['wpdb']->active_count = 1;
$_POST = [ 'ys_ec_ecpay_logistics_c2c_clear' => '1' ];
$r = v0216l_apply();
v0216l_check( 'L2 signer change with active labels refused with ZERO writes',
	'signer_change_active_labels' === $r
		&& [] === $GLOBALS['v0216l_write_log']
		&& '2000933' === ( $GLOBALS['v0216l_settings']['ys_ec_ecpay_logistics_c2c_merchant_id'] ?? '' ),
	"r=$r writes=" . implode( ',', $GLOBALS['v0216l_write_log'] ) );

// ── L3 authority 查詢失敗 → 拒絕＋零寫入 ──
v0216l_reset( v0216l_full_c2c() );
$GLOBALS['wpdb']->error_mode = true;
$_POST = [ 'ys_ec_ecpay_logistics_c2c_clear' => '1' ];
$r = v0216l_apply();
v0216l_check( 'L3 authority lookup failure refused with zero writes',
	'signer_change_label_lookup_failed' === $r && [] === $GLOBALS['v0216l_write_log'], "r=$r" );

// ── L4 乾淨狀態開 reuse（payment 空→signer 不變）→ commit＋readback ──
v0216l_reset( [ 'ys_ec_ecpay_enabled' => '1', 'ys_ec_ecpay_logistics_reuse_payment' => '0' ] );
$_POST = [ 'ys_ec_ecpay_enabled' => '1', 'ys_ec_ecpay_logistics_reuse_payment' => '1' ];
$r = v0216l_apply();
v0216l_check( 'L4 reuse-on with empty payment commits (effective signer unchanged)',
	'' === $r && '1' === ( $GLOBALS['v0216l_settings']['ys_ec_ecpay_logistics_reuse_payment'] ?? '' )
		&& [] === $GLOBALS['v0216l_options'],
	"r=$r lock=" . json_encode( $GLOBALS['v0216l_options'] ) );

// ── L4b 開 reuse 且 payment 完整（signer 會變）＋active label → 拒絕＋零寫入 ──
v0216l_reset( v0216l_full_payment() + [ 'ys_ec_ecpay_logistics_reuse_payment' => '0' ] );
$GLOBALS['wpdb']->active_count = 1;
// 擬真表單：merchant_id 欄位會回傳現值、secret 空白＝保留、test_mode unchecked＝'0'==現值
$_POST = [ 'ys_ec_ecpay_logistics_reuse_payment' => '1', 'ys_ec_ecpay_payment_merchant_id' => '3507531' ];
$r = v0216l_apply();
v0216l_check( 'L4b reuse-on that changes effective signer is gated with zero writes',
	'signer_change_active_labels' === $r && [] === $GLOBALS['v0216l_write_log']
		&& '0' === ( $GLOBALS['v0216l_settings']['ys_ec_ecpay_logistics_reuse_payment'] ?? '' ),
	"r=$r writes=" . implode( ',', $GLOBALS['v0216l_write_log'] ) );

// ── L5 mid-commit 失敗 → 全量回滾＋absent 語意（原 absent 鍵 delete 恢復，不是 ''）──
v0216l_reset( [ 'ys_ec_ecpay_logistics_reuse_payment' => '0' ] );
$_POST = [
	'ys_ec_ecpay_logistics_reuse_payment' => '1',
	'ys_ec_ecpay_payment_merchant_id'     => '3507531',
	'ys_ec_ecpay_payment_hash_key'        => 'NEWKEY',
	'ys_ec_ecpay_payment_hash_iv'         => 'NEWIV',
];
$GLOBALS['v0216l_update_fail_keys'] = [ 'ys_ec_ecpay_payment_hash_iv' ]; // 最後一鍵寫失敗
$r = v0216l_apply();
$s = $GLOBALS['v0216l_settings'];
v0216l_check( 'L5 mid-commit failure rolls back every key',
	'settings_commit_failed_rolled_back' === $r
		&& '0' === ( $s['ys_ec_ecpay_logistics_reuse_payment'] ?? '' )
		&& ! array_key_exists( 'ys_ec_ecpay_payment_merchant_id', $s )
		&& ! array_key_exists( 'ys_ec_ecpay_payment_hash_key', $s ),
	"r=$r s=" . json_encode( array_keys( $s ) ) );
v0216l_check( 'L5b originally-absent test_mode restored to ABSENT (deleted), not empty string',
	! array_key_exists( 'ys_ec_ecpay_payment_test_mode', $s ),
	'value=' . json_encode( $s['ys_ec_ecpay_payment_test_mode'] ?? '(absent)' ) );

// ── L6a clear 整組四鍵清空（維護態 commit）──
v0216l_reset( v0216l_full_c2c() );
$_POST = [ 'ys_ec_ecpay_logistics_c2c_clear' => '1', 'ys_ec_ecpay_logistics_c2c_merchant_id' => 'SHOULD-BE-IGNORED' ];
$r = v0216l_apply();
$after = array_map(
	static fn( $k ) => (string) ( $GLOBALS['v0216l_settings'][ $k ] ?? '(missing)' ),
	array_values( Settings::LOGISTICS_C2C_KEYS )
);
v0216l_check( 'L6a clear wipes all four keys and ignores other inputs',
	'' === $r && [ '', '', '', '' ] === $after, "r=$r after=" . json_encode( $after ) );

// ── L6b secret 空白＝保留（key 不進 desired）──
v0216l_reset( [ 'ys_ec_ecpay_logistics_c2c_hash_key' => 'enc:KEEPKEY' ] );
$_POST = [ 'ys_ec_ecpay_logistics_c2c_merchant_id' => '2000933', 'ys_ec_ecpay_logistics_c2c_hash_key' => '' ];
$r = v0216l_apply();
v0216l_check( 'L6b blank secret is preserved',
	'enc:KEEPKEY' === ( $GLOBALS['v0216l_settings']['ys_ec_ecpay_logistics_c2c_hash_key'] ?? '' ), "r=$r" );

// ── G1 reuse 生效中旋轉 payment key＋active label → 拒絕＋OLDPAY 全程不動 ──
v0216l_reset( v0216l_full_payment( 'OLDPAY' ) + [ 'ys_ec_ecpay_logistics_reuse_payment' => '1' ] );
$GLOBALS['wpdb']->active_count = 1;
$_POST = [
	'ys_ec_ecpay_logistics_reuse_payment' => '1',
	'ys_ec_ecpay_payment_merchant_id'     => '3507531',
	'ys_ec_ecpay_payment_hash_key'        => 'NEWKEY',
];
$r = v0216l_apply();
v0216l_check( 'G1 payment-key rotation under reuse+active labels refused; OLDPAY never touched',
	'signer_change_active_labels' === $r
		&& [] === $GLOBALS['v0216l_write_log']
		&& 'enc:OLDPAY' === ( $GLOBALS['v0216l_settings']['ys_ec_ecpay_payment_hash_key'] ?? '' ),
	"r=$r writes=" . implode( ',', $GLOBALS['v0216l_write_log'] ) );

// ── G2 clear 使用中的 explicit 組（fallback 切到 reuse）＋active → 拒絕＋零寫入 ──
v0216l_reset( v0216l_full_c2c() + v0216l_full_payment() + [ 'ys_ec_ecpay_logistics_reuse_payment' => '1' ] );
$GLOBALS['wpdb']->active_count = 1;
$_POST = [ 'ys_ec_ecpay_logistics_reuse_payment' => '1', 'ys_ec_ecpay_logistics_c2c_clear' => '1' ];
$r = v0216l_apply();
v0216l_check( 'G2 clearing in-use explicit group refused with zero writes',
	'signer_change_active_labels' === $r
		&& [] === $GLOBALS['v0216l_write_log']
		&& '2000933' === ( $GLOBALS['v0216l_settings']['ys_ec_ecpay_logistics_c2c_merchant_id'] ?? '' ),
	"r=$r" );

// ── G3 signer 變更＋任一方法啟用（零 label）→ 拒絕 ──
v0216l_reset( v0216l_full_c2c() + [ 'ys_ec_ecpay_ship_unimart_c2c_enabled' => '1' ] );
$_POST = [ 'ys_ec_ecpay_logistics_c2c_clear' => '1' ];
$r = v0216l_apply();
v0216l_check( 'G3 signer change with an enabled method is refused',
	'signer_change_requires_methods_disabled' === $r
		&& '2000933' === ( $GLOBALS['v0216l_settings']['ys_ec_ecpay_logistics_c2c_merchant_id'] ?? '' ),
	"r=$r" );

// ── G4 維護態 clear → commit ──
v0216l_reset( v0216l_full_c2c() );
$_POST = [ 'ys_ec_ecpay_logistics_c2c_clear' => '1' ];
$r = v0216l_apply();
v0216l_check( 'G4 clean maintenance state commits the signer change',
	'' === $r && '' === ( $GLOBALS['v0216l_settings']['ys_ec_ecpay_logistics_c2c_merchant_id'] ?? '(missing)' ), "r=$r" );

// ── G5 非 signer 變更＋active labels → 放行 ──
v0216l_reset( v0216l_full_c2c() );
$GLOBALS['wpdb']->active_count = 5;
$_POST = [ 'ys_ec_ecpay_logistics_c2c_test_mode' => '1', 'ys_ec_ecpay_logistics_c2c_merchant_id' => '2000933' ];
$r = v0216l_apply();
v0216l_check( 'G5 non-signer-affecting save passes without gate',
	'' === $r && '2000933' === ( $GLOBALS['v0216l_settings']['ys_ec_ecpay_logistics_c2c_merchant_id'] ?? '' ), "r=$r" );

// ── G6 reuse toggle 會改變 signer＋方法啟用 → 拒絕 ──
v0216l_reset( v0216l_full_payment() + [
	'ys_ec_ecpay_logistics_reuse_payment' => '0',
	'ys_ec_ecpay_ship_unimart_c2c_enabled' => '1',
] );
$_POST = [ 'ys_ec_ecpay_logistics_reuse_payment' => '1', 'ys_ec_ecpay_payment_merchant_id' => '3507531' ];
$r = v0216l_apply();
v0216l_check( 'G6 signer-changing reuse toggle with enabled method refused',
	'signer_change_requires_methods_disabled' === $r && [] === $GLOBALS['v0216l_write_log'], "r=$r" );

// ── G7 兩個 settings writer：B 的 signer compute 不得早於 writer acquire ──
v0216l_reset( [
	'ys_ec_ecpay_enabled'                        => '1',
	'ys_ec_ecpay_home_credential_family'         => 'b2c_home',
	'ys_ec_ecpay_logistics_reuse_payment'        => '0',
	'ys_ec_ecpay_logistics_b2c_home_test_mode'   => '0',
	'ys_ec_ecpay_logistics_b2c_home_merchant_id' => 'MIDQ', // partial until Request A commits keys.
	'ys_ec_ecpay_logistics_c2c_test_mode'        => '0',
	'ys_ec_ecpay_logistics_c2c_merchant_id'      => '',
] );
$GLOBALS['wpdb']->active_count = 1;
$GLOBALS['wpdb']->complete_b2c_before_writer_insert = true; // A: completes signer Q, then releases.
$_POST = [
	'ys_ec_ecpay_enabled'                                => '1',
	'ys_ec_ecpay_home_credential_family'                 => 'b2c_home',
	'ys_ec_ecpay_payment_merchant_id'                    => '',
	'ys_ec_ecpay_logistics_b2c_home_merchant_id'         => 'MIDP',
	'ys_ec_ecpay_logistics_c2c_merchant_id'              => '',
];
$r = v0216l_apply();
v0216l_check( 'G7 signer snapshots are recomputed after writer acquire',
	'signer_change_active_labels' === $r
		&& [] === $GLOBALS['v0216l_write_log']
		&& 'MIDQ' === ( $GLOBALS['v0216l_settings']['ys_ec_ecpay_logistics_b2c_home_merchant_id'] ?? '' )
		&& 'enc:QKEY' === ( $GLOBALS['v0216l_settings']['ys_ec_ecpay_logistics_b2c_home_hash_key'] ?? '' ),
	"r=$r writes=" . implode( ',', $GLOBALS['v0216l_write_log'] ) );

// ── G8 rollback 單鍵失敗 → 誠實回報＋其餘鍵仍還原（全量掃）──
v0216l_reset( [ 'ys_ec_ecpay_logistics_reuse_payment' => '0' ] );
$_POST = [
	'ys_ec_ecpay_logistics_reuse_payment' => '1',
	'ys_ec_ecpay_payment_merchant_id'     => '3507531',
	'ys_ec_ecpay_payment_hash_key'        => 'NEWKEY',
	'ys_ec_ecpay_payment_hash_iv'         => 'NEWIV',
];
$GLOBALS['v0216l_update_fail_keys'] = [ 'ys_ec_ecpay_payment_hash_iv' ];   // commit 失敗
$GLOBALS['v0216l_delete_fail_keys'] = [ 'ys_ec_ecpay_payment_test_mode' ]; // 該鍵回滾（delete）失敗
$r = v0216l_apply();
$s = $GLOBALS['v0216l_settings'];
v0216l_check( 'G8 rollback failure reported honestly; sweep still restores the rest',
	'signer_gate_rollback_failed' === $r
		&& '0' === ( $s['ys_ec_ecpay_logistics_reuse_payment'] ?? '' )
		&& ! array_key_exists( 'ys_ec_ecpay_payment_merchant_id', $s )
		&& ! array_key_exists( 'ys_ec_ecpay_payment_hash_key', $s ),
	"r=$r s=" . json_encode( array_keys( $s ) ) );
$blocked_after_partial_rollback = ProviderMaintenanceLock::reader_begin();
v0216l_check( 'G8b incomplete rollback retains a durable fail-closed sentinel',
	null === $blocked_after_partial_rollback
		&& ( ProviderMaintenanceLock::crashed_flag_present()
			|| array_key_exists( ProviderMaintenanceLock::OPTION, $GLOBALS['v0216l_options'] ) ),
	'token=' . var_export( $blocked_after_partial_rollback, true ) );

// ═══ P：reader/writer 真互斥（R14）═══

// 可 operable 的 C2C 方法 fixture（lifecycle class 缺席→fallback 讀 Settings 開關）
$c2c_method_id = '';
$c2c_enabled_option = '';
foreach ( EcpayShippingCatalog::all() as $mid => $d ) {
	if ( EcpayShippingCatalog::CHANNEL_C2C === (string) ( $d['channel'] ?? '' ) && empty( $d['requires_goods_weight'] ) ) {
		$c2c_method_id      = (string) $mid;
		$c2c_enabled_option = (string) ( $d['enabled_option'] ?? '' );
		break;
	}
}
$method_stub = new class() extends EcpayShipping {
	public string $id = '';
	public function get_id(): string { return $this->id; }
};
$method_stub->id = $c2c_method_id;

function v0216l_operable_fixture( string $enabled_option ): array {
	return v0216l_full_c2c() + [
		'ys_ec_ecpay_enabled' => '1',
		$enabled_option       => '1',
	];
}

// ── P1 writer 持有中（未逾期）→ create_order 拒送＋零 HTTP ──
v0216l_reset( v0216l_operable_fixture( $c2c_enabled_option ) );
$GLOBALS['v0216l_options'][ ProviderMaintenanceLock::OPTION ] = 'someowner|' . time() . '|ab';
$requester = new EcpayShippingRequester( $method_stub );
$res = $requester->create_order( [] );
v0216l_check( 'P1 live writer refuses create_order before any network I/O',
	false === $res['success']
		&& 'provider_failed' === $res['outcome']
		&& false !== strpos( (string) $res['message'], '設定維護中' )
		&& 0 === $GLOBALS['v0216l_http_calls'],
	json_encode( $res, JSON_UNESCAPED_UNICODE ) );

// ── P2 對照組：無鎖同 fixture → reader lease 取得、通過 gate（後續欄位驗證才中止）──
v0216l_reset( v0216l_operable_fixture( $c2c_enabled_option ) );
$requester = new EcpayShippingRequester( $method_stub );
$past_gate = false;
try {
	$requester->create_order( [] ); // 缺 payment_method → build_create_fields 內丟例外
} catch ( \RuntimeException $e ) {
	$past_gate = false !== strpos( $e->getMessage(), 'payment_method' );
}
$lease_released = ! array_filter(
	array_keys( $GLOBALS['v0216l_options'] ),
	static fn( $k ) => 0 === strpos( (string) $k, ProviderMaintenanceLock::READER_PREFIX )
);
v0216l_check( 'P2 without any writer the same fixture passes the gate and releases its lease',
	$past_gate && $lease_released,
	'past=' . var_export( $past_gate, true ) . ' released=' . var_export( $lease_released, true ) );

// ── P3 逾期 writer（crash 未修復）→ reader 收割但**拒絕**＋crashed 旗標 ──
v0216l_reset();
$GLOBALS['v0216l_options'][ ProviderMaintenanceLock::OPTION ] = 'someowner|' . ( time() - ProviderMaintenanceLock::TTL - 5 ) . '|ab';
$t = ProviderMaintenanceLock::reader_begin();
v0216l_check( 'P3 expired (crashed) writer: reader reaps the row, refuses, and raises the crashed flag',
	null === $t
		&& ! array_key_exists( ProviderMaintenanceLock::OPTION, $GLOBALS['v0216l_options'] )
		&& ProviderMaintenanceLock::crashed_flag_present(),
	json_encode( array_keys( $GLOBALS['v0216l_options'] ) ) );

// ── P3a crashed 旗標必須持續 fail-closed：不可只擋收割 stale writer 的第一個 reader ──
$t_again = ProviderMaintenanceLock::reader_begin();
v0216l_check( 'P3a a second reader remains blocked while the crashed flag is present',
	null === $t_again && ProviderMaintenanceLock::crashed_flag_present(),
	'token=' . var_export( $t_again, true ) );
if ( null !== $t_again ) { ProviderMaintenanceLock::reader_end( $t_again ); }

// ── P3a2 crashed 旗標寫入失敗時不得刪掉 stale writer 後放行後續 reader ──
v0216l_reset();
$GLOBALS['v0216l_options'][ ProviderMaintenanceLock::OPTION ] = 'someowner|' . ( time() - ProviderMaintenanceLock::TTL - 5 ) . '|ab';
$GLOBALS['wpdb']->option_insert_fail_names = [ ProviderMaintenanceLock::CRASHED_FLAG ];
$first_after_marker_failure  = ProviderMaintenanceLock::reader_begin();
$second_after_marker_failure = ProviderMaintenanceLock::reader_begin();
v0216l_check( 'P3a2 failed crashed-marker persistence leaves the stale writer fail-closed',
	null === $first_after_marker_failure
		&& null === $second_after_marker_failure
		&& array_key_exists( ProviderMaintenanceLock::OPTION, $GLOBALS['v0216l_options'] ),
	json_encode( array_keys( $GLOBALS['v0216l_options'] ) ) );
if ( null !== $second_after_marker_failure ) { ProviderMaintenanceLock::reader_end( $second_after_marker_failure ); }

// ── P3b unchanged re-save 不得假成功；crash quarantine 需要完整、明示的 API repair ──
v0216l_reset( [
	'ys_ec_ecpay_enabled'                        => '1',
	'ys_ec_ecpay_home_credential_family'         => 'b2c_home',
	'ys_ec_ecpay_logistics_reuse_payment'        => '0',
	'ys_ec_ecpay_payment_test_mode'              => '1',
	'ys_ec_ecpay_payment_merchant_id'            => '',
	'ys_ec_ecpay_logistics_b2c_home_test_mode'   => '1',
	'ys_ec_ecpay_logistics_b2c_home_merchant_id' => '',
	'ys_ec_ecpay_logistics_c2c_test_mode'        => '1',
	'ys_ec_ecpay_logistics_c2c_merchant_id'      => '',
] );
$GLOBALS['v0216l_options'][ ProviderMaintenanceLock::CRASHED_FLAG ] = (string) time();
$_POST = [
	'ys_ec_ecpay_enabled'                      => '1',
	'ys_ec_ecpay_home_credential_family'       => 'b2c_home',
	'ys_ec_ecpay_payment_test_mode'            => '1',
	'ys_ec_ecpay_logistics_b2c_home_test_mode' => '1',
	'ys_ec_ecpay_logistics_c2c_test_mode'      => '1',
];
$r = v0216l_apply();
v0216l_check( 'P3b unchanged API re-save cannot falsely clear or report repaired quarantine',
	'settings_crash_repair_requires_full_credentials' === $r
		&& ProviderMaintenanceLock::crashed_flag_present(),
	"r=$r" );

$_POST += [
	'ys_ec_ecpay_payment_clear'            => '1',
	'ys_ec_ecpay_logistics_b2c_home_clear' => '1',
	'ys_ec_ecpay_logistics_c2c_clear'      => '1',
];
$r = v0216l_apply();
v0216l_check( 'P3b2 explicit full API repair verifies the complete tuple, clears quarantine, and recovers readers',
	'' === $r && ! ProviderMaintenanceLock::crashed_flag_present()
		&& null !== ( $t2 = ProviderMaintenanceLock::reader_begin() ),
	"r=$r" );
if ( isset( $t2 ) && null !== $t2 ) { ProviderMaintenanceLock::reader_end( $t2 ); }

// ── P3c lock row 讀取失敗：unknown 不得當作無 writer 放行 ──
v0216l_reset();
$GLOBALS['wpdb']->lock_read_error = true;
$read_error_token = ProviderMaintenanceLock::reader_begin();
v0216l_check( 'P3c option-table read failure refuses a reader without registering it',
	null === $read_error_token && [] === $GLOBALS['v0216l_options'],
	'token=' . var_export( $read_error_token, true ) );
$GLOBALS['wpdb']->lock_read_error = false;
if ( null !== $read_error_token ) { ProviderMaintenanceLock::reader_end( $read_error_token ); }

// ── P3d clear crashed flag 的 DELETE 失敗必須可觀測，不得回報已修復 ──
v0216l_reset();
$GLOBALS['v0216l_options'][ ProviderMaintenanceLock::CRASHED_FLAG ] = (string) time();
$flag_owner = ProviderMaintenanceLock::acquire();
$GLOBALS['wpdb']->option_delete_fail_names = [ ProviderMaintenanceLock::CRASHED_FLAG ];
$flag_cleared = ProviderMaintenanceLock::clear_crashed_flag( (string) $flag_owner );
v0216l_check( 'P3d crashed-marker clear verifies deletion and reports failure',
	null !== $flag_owner && false === $flag_cleared && array_key_exists( ProviderMaintenanceLock::CRASHED_FLAG, $GLOBALS['v0216l_options'] ),
	'cleared=' . var_export( $flag_cleared, true ) );
if ( null !== $flag_owner ) { ProviderMaintenanceLock::release( $flag_owner ); }

// ── P4 鎖四件套：NX／owner-conditional 釋放／被接管後 fence=false ──
v0216l_reset();
$o1 = ProviderMaintenanceLock::acquire();
$o2 = ProviderMaintenanceLock::acquire(); // 撞 NX
$nx_ok = null !== $o1 && null === $o2;
ProviderMaintenanceLock::release( 'not-the-owner' ); // 不得刪別人的鎖
$foreign_release_noop = array_key_exists( ProviderMaintenanceLock::OPTION, $GLOBALS['v0216l_options'] );
$GLOBALS['v0216l_options'][ ProviderMaintenanceLock::OPTION ] = 'usurper|' . time() . '|cd';
$fence_lost = ! ProviderMaintenanceLock::fence( (string) $o1 );
v0216l_check( 'P4 lock quartet: NX acquire, owner-conditional release, fence fails after takeover',
	$nx_ok && $foreign_release_noop && $fence_lost,
	"nx=" . var_export( $nx_ok, true ) . " rel=" . var_export( $foreign_release_noop, true ) . " fence=" . var_export( $fence_lost, true ) );

// ── P4b 舊 writer 失去 ownership 後不得清除新 writer 持有的 crash marker ──
v0216l_reset();
$old_owner = ProviderMaintenanceLock::acquire();
$GLOBALS['v0216l_options'][ ProviderMaintenanceLock::OPTION ] =
	(string) $old_owner . '|' . ( time() - ProviderMaintenanceLock::TTL - 5 ) . '|old';
$new_owner = ProviderMaintenanceLock::acquire();
$old_clear = ProviderMaintenanceLock::clear_crashed_flag( (string) $old_owner );
v0216l_check( 'P4b a superseded writer cannot clear the successor-owned crash marker',
	null !== $new_owner && false === $old_clear && ProviderMaintenanceLock::crashed_flag_present(),
	'old_clear=' . var_export( $old_clear, true ) );
if ( null !== $new_owner ) { ProviderMaintenanceLock::release( $new_owner ); }

// ── P4c takeover 正發生在 marker read 與 DELETE 之間：DELETE 本身必須連同 exact writer owner 條件 ──
v0216l_reset();
$GLOBALS['v0216l_options'][ ProviderMaintenanceLock::CRASHED_FLAG ] = (string) time();
$old_owner = ProviderMaintenanceLock::acquire();
$GLOBALS['wpdb']->takeover_before_marker_delete = true;
$raced_clear = ProviderMaintenanceLock::clear_crashed_flag( (string) $old_owner );
v0216l_check( 'P4c takeover immediately before marker DELETE makes the owner-conditional clear fail closed',
	null !== $old_owner
		&& false === $raced_clear
		&& ProviderMaintenanceLock::crashed_flag_present()
		&& ! ProviderMaintenanceLock::fence( (string) $old_owner ),
	'cleared=' . var_export( $raced_clear, true ) );

// ── P5 🔴 R14 interleaving #1：活著的 reader 擋 writer——「reader 最後一次
// free check → 設定 commit → HTTP」的窗口不存在：reader 登記後 writer 連
// acquire 都拿不到；reader 結束後 writer 才進得來 ──
v0216l_reset();
$rt = ProviderMaintenanceLock::reader_begin();
$w_blocked = ProviderMaintenanceLock::acquire();
ProviderMaintenanceLock::reader_end( (string) $rt );
$w_after = ProviderMaintenanceLock::acquire();
if ( null !== $w_after ) { ProviderMaintenanceLock::release( $w_after ); }
v0216l_check( 'P5 a live reader blocks writer acquire; writer succeeds only after reader_end',
	null !== $rt && null === $w_blocked && null !== $w_after,
	'rt=' . var_export( $rt, true ) . ' blocked=' . var_export( $w_blocked, true ) );

// ── P6 🔴 R14 interleaving #2：stalled reader（超 READER_TTL）被 writer 收割
// → 甦醒後 pre-send reader_fence 必敗（舊簽章一個 byte 出不了網）──
v0216l_reset();
$stale_token = bin2hex( random_bytes( 6 ) );
$GLOBALS['v0216l_options'][ ProviderMaintenanceLock::READER_PREFIX . $stale_token ] =
	$stale_token . '|' . ( time() - ProviderMaintenanceLock::READER_TTL - 5 ) . '|ee';
$w = ProviderMaintenanceLock::acquire(); // 收割 stalled reader 後成功
$reaped = ! array_key_exists( ProviderMaintenanceLock::READER_PREFIX . $stale_token, $GLOBALS['v0216l_options'] );
$stalled_fence_dead = ! ProviderMaintenanceLock::reader_fence( $stale_token );
if ( null !== $w ) { ProviderMaintenanceLock::release( $w ); }
v0216l_check( 'P6 stalled reader is reaped by the writer and its pre-send fence dies',
	null !== $w && $reaped && $stalled_fence_dead,
	'w=' . var_export( $w, true ) . ' reaped=' . var_export( $reaped, true ) );

// ── P6b stale reader 條件式 DELETE 失敗：writer 不得當作已清場完成 ──
v0216l_reset();
$stale_token = bin2hex( random_bytes( 6 ) );
$stale_name  = ProviderMaintenanceLock::READER_PREFIX . $stale_token;
$GLOBALS['v0216l_options'][ $stale_name ] = $stale_token . '|' . ( time() - ProviderMaintenanceLock::READER_TTL - 5 ) . '|ee';
$GLOBALS['wpdb']->option_delete_fail_names = [ $stale_name ];
$w_delete_failed = ProviderMaintenanceLock::acquire();
v0216l_check( 'P6b writer acquire fails when a stale reader row cannot be conditionally reaped',
	null === $w_delete_failed && array_key_exists( $stale_name, $GLOBALS['v0216l_options'] ),
	'w=' . var_export( $w_delete_failed, true ) );
if ( null !== $w_delete_failed ) { ProviderMaintenanceLock::release( $w_delete_failed ); }

// ── P6c READER_PREFIX 含 `_`：LIKE 必須 esc_like，不得把外形相似的其他 option 當 reader 刪除 ──
v0216l_reset();
$lookalike = 'ysXecXecpayXcredreadXnot_a_reader';
$GLOBALS['v0216l_options'][ $lookalike ] = 'foreign|' . ( time() - ProviderMaintenanceLock::READER_TTL - 5 ) . '|ee';
$w_escaped_scan = ProviderMaintenanceLock::acquire();
v0216l_check( 'P6c reader scan escapes LIKE wildcards and preserves lookalike non-reader options',
	null !== $w_escaped_scan && array_key_exists( $lookalike, $GLOBALS['v0216l_options'] ),
	json_encode( array_keys( $GLOBALS['v0216l_options'] ) ) );
if ( null !== $w_escaped_scan ) { ProviderMaintenanceLock::release( $w_escaped_scan ); }

// ── P7 🔴 R14 interleaving #3：stalled writer（超 TTL、可能寫到一半）被 reader
// 收割拒絕後，writer 甦醒——它的 per-key fence 必敗，commit 無法續行 ──
v0216l_reset();
$w_owner = 'stalledwriterownerxxxxxx';
$GLOBALS['v0216l_options'][ ProviderMaintenanceLock::OPTION ] =
	$w_owner . '|' . ( time() - ProviderMaintenanceLock::TTL - 5 ) . '|ff';
$t = ProviderMaintenanceLock::reader_begin(); // 收割＋拒絕（P3 語意）
$writer_fence_dead = ! ProviderMaintenanceLock::fence( $w_owner );
v0216l_check( 'P7 after reaping, the stalled writer\'s own fence dies — its commit cannot proceed',
	null === $t && $writer_fence_dead );

// ── P8 lease omission contracts：長暫停 reader 被 writer 收割後，每個驗簽／
// 出網／表單交付點都必須以 own-row fence 證明租約仍屬於自己。
$payment_verify = v0216l_method_source( 'src/Api/EcpayPaymentController.php', 'verify_payment_payload' );
v0216l_check( 'P8a payment callback verifies only after an own-row fence',
	v0216l_fence_precedes( $payment_verify, 'CheckMacValue::verify' ) );

$logistics_notify = v0216l_method_source( 'src/Api/EcpayLogisticsController.php', 'notify' );
v0216l_check( 'P8b logistics callback fences after lookup and immediately before credential verification',
	v0216l_fence_precedes( $logistics_notify, '$this->verify(' ) );

$print_handle = v0216l_method_source( 'src/Api/EcpayPrintController.php', 'handle' );
v0216l_check( 'P8c print delivery fences both credential verification and browser form output',
	v0216l_fence_precedes( $print_handle, 'CheckMacValue::verify' )
		&& v0216l_fence_precedes( $print_handle, 'status_header( 200 )', 2 ) );

$directory_refresh = v0216l_method_source( 'src/Shipping/Ecpay/EcpayStoreDirectory.php', 'refresh' );
v0216l_check( 'P8d store-directory refresh fences immediately before HTTP',
	v0216l_fence_precedes( $directory_refresh, '$response = ( new HttpFormClient() )->post' ) );
$directory_cache_key = v0216l_method_source( 'src/Shipping/Ecpay/EcpayStoreDirectory.php', 'cache_key' );
v0216l_check( 'P8d2 credential-derived store cache identity is leased and fenced',
	false !== strpos( $directory_cache_key, 'ProviderMaintenanceLock::reader_lease(' )
		&& v0216l_fence_precedes( $directory_cache_key, '$fingerprint = hash_hmac(' )
		&& strpos( $directory_refresh, 'ProviderMaintenanceLock::reader_lease(' )
			< strpos( $directory_refresh, '$cache_key = self::cache_key(' ) );

// ── P8d3 cache key 的 signer lease 必須活到 transient read；否則 writer 可插隊旋轉 ──
v0216l_reset( v0216l_full_c2c() );
$GLOBALS['v0216l_cache_probe'] = true;
$cached_store_list = new ReflectionMethod( EcpayStoreDirectory::class, 'cached_store_list' );
$cached_store_list->setAccessible( true );
$cached_store_list->invoke( null, 'UNIMARTC2C' );
v0216l_check( 'P8d3 writer cannot interleave between credential cache-key derivation and transient read',
	false === $GLOBALS['v0216l_cache_writer_acquired'],
	'writer_acquired=' . ( $GLOBALS['v0216l_cache_writer_acquired'] ? 'true' : 'false' ) );

$map_verify = v0216l_method_source( 'src/Shipping/Ecpay/EcpayStoreSelector.php', 'verify_map_payload' );
v0216l_check( 'P8e map callback acceptance is fenced whether optional CMV is present or absent',
	v0216l_fence_precedes( $map_verify, "if ( ! empty( \$params['CheckMacValue'] ) )" ) );

$claim_seal = v0216l_method_source( 'src/Plugin.php', 'fulfillment_claim_seal' );
v0216l_check( 'P8f fulfillment claim HMAC is covered by a reader lease and pre-use fence',
	false !== strpos( $claim_seal, 'ProviderMaintenanceLock::reader_lease(' )
		&& v0216l_fence_precedes( $claim_seal, "return hash_hmac( 'sha256'" ) );

// ═══ G9~G11：payment signer authority（R14）═══

// ── G9 payment key 旋轉＋pending ECPay 訂單 → 拒絕＋零寫入 ──
v0216l_reset( v0216l_full_payment( 'OLDPAY' ) );
$GLOBALS['wpdb']->pending_orders = 2;
$_POST = [ 'ys_ec_ecpay_payment_merchant_id' => '3507531', 'ys_ec_ecpay_payment_hash_key' => 'NEWKEY' ];
$r = v0216l_apply();
v0216l_check( 'G9 payment-key rotation with unresolved ECPay attempts refused with zero writes',
	'payment_signer_change_active_attempts' === $r
		&& [] === $GLOBALS['v0216l_write_log']
		&& 'enc:OLDPAY' === ( $GLOBALS['v0216l_settings']['ys_ec_ecpay_payment_hash_key'] ?? '' ),
	"r=$r writes=" . implode( ',', $GLOBALS['v0216l_write_log'] ) );

// ── G9b ATM/CVS/barcode 取號後的 offline_payment 仍是未決 signer authority ──
v0216l_reset( v0216l_full_payment( 'OLDPAY' ) );
$GLOBALS['wpdb']->offline_payment_orders = 1;
$_POST = [ 'ys_ec_ecpay_payment_merchant_id' => '3507531', 'ys_ec_ecpay_payment_hash_key' => 'NEWKEY' ];
$r = v0216l_apply();
v0216l_check( 'G9b payment-key rotation with an offline_payment attempt is refused with zero writes',
	'payment_signer_change_active_attempts' === $r
		&& [] === $GLOBALS['v0216l_write_log']
		&& 'enc:OLDPAY' === ( $GLOBALS['v0216l_settings']['ys_ec_ecpay_payment_hash_key'] ?? '' ),
	"r=$r writes=" . implode( ',', $GLOBALS['v0216l_write_log'] ) );

// ── G9c/G9d status-only terminal history 無 release receipt，不能無界永久鎖死 rotation ──
v0216l_reset( v0216l_full_payment( 'OLDPAY' ) );
$GLOBALS['wpdb']->timeout_orders = 1;
$_POST = [ 'ys_ec_ecpay_payment_merchant_id' => '3507531', 'ys_ec_ecpay_payment_hash_key' => 'NEWKEY' ];
$r = v0216l_apply();
v0216l_check( 'G9c historical timeout rows do not permanently block signer rotation',
	'' === $r && 'enc:NEWKEY' === ( $GLOBALS['v0216l_settings']['ys_ec_ecpay_payment_hash_key'] ?? '' ),
	"r=$r" );

v0216l_reset( v0216l_full_payment( 'OLDPAY' ) );
$GLOBALS['wpdb']->cancelled_orders = 1;
$_POST = [ 'ys_ec_ecpay_payment_merchant_id' => '3507531', 'ys_ec_ecpay_payment_hash_key' => 'NEWKEY' ];
$r = v0216l_apply();
v0216l_check( 'G9d historical cancelled rows do not permanently block signer rotation',
	'' === $r && 'enc:NEWKEY' === ( $GLOBALS['v0216l_settings']['ys_ec_ecpay_payment_hash_key'] ?? '' ),
	"r=$r" );

// ── G10 payment key 旋轉＋付款方式仍啟用 → 拒絕 ──
v0216l_reset( v0216l_full_payment( 'OLDPAY' ) + [ 'ys_ec_ecpay_credit_enabled' => '1' ] );
$_POST = [ 'ys_ec_ecpay_payment_merchant_id' => '3507531', 'ys_ec_ecpay_payment_hash_key' => 'NEWKEY' ];
$r = v0216l_apply();
v0216l_check( 'G10 payment-key rotation with an enabled payment method refused',
	'payment_signer_change_requires_methods_disabled' === $r
		&& 'enc:OLDPAY' === ( $GLOBALS['v0216l_settings']['ys_ec_ecpay_payment_hash_key'] ?? '' ),
	"r=$r" );

// ── G11 維護態（方式停用＋零 pending）payment 旋轉 → commit ──
v0216l_reset( v0216l_full_payment( 'OLDPAY' ) );
$_POST = [ 'ys_ec_ecpay_payment_merchant_id' => '3507531', 'ys_ec_ecpay_payment_hash_key' => 'NEWKEY' ];
$r = v0216l_apply();
v0216l_check( 'G11 clean maintenance state commits the payment signer change',
	'' === $r && 'enc:NEWKEY' === ( $GLOBALS['v0216l_settings']['ys_ec_ecpay_payment_hash_key'] ?? '' ),
	"r=$r" );

// ═══ G12：lifecycle 鏡像原子性（R14）——本段定義 stub lifecycle class，必須最後跑 ═══
eval( <<<'PHP'
namespace YangSheep\Ecommerce\Core\Provider;
final class YSProviderLifecycleState {
	public static bool $enabled = false;
	public static int $set_noop_remaining = 0; // >0：set 為 no-op（readback 會不符）
	public static bool $provider_read_saw_writer = false;
	public static function is_provider_enabled( string $p, ?array $m = null ): bool {
		self::$provider_read_saw_writer = array_key_exists(
			\YangSheep\YSCartEcpay\Support\ProviderMaintenanceLock::OPTION,
			$GLOBALS['v0216l_options']
		);
		return self::$enabled;
	}
	public static function is_capability_enabled( string $p, string $c, ?array $m = null ): bool { return true; }
	public static function is_method_enabled( string $d, string $id, ?array $m = null ): bool { return false; }
	public static function set_provider_enabled( string $p, bool $e, ?array $m = null ): void {
		if ( self::$set_noop_remaining > 0 ) { self::$set_noop_remaining--; return; }
		self::$enabled = $e;
	}
	public static function get_methods_state( string $d ): array { return []; }
	public static function update_methods_state( string $d, array $s ): void {}
}
PHP );
eval( <<<'PHP'
namespace YangSheep\YSCartEcpay;
if ( ! class_exists( 'YangSheep\\YSCartEcpay\\Plugin' ) ) {
	final class Plugin {
		public static function manifest(): array {
			return [
				'id' => 'ys_ecpay',
				'legacy_setting_key' => 'ys_ec_ecpay_enabled',
				'domains' => [ 'payment', 'shipping' ],
				'capabilities' => [
					'payment' => [ 'methods' => array_map(
						static fn( string $id ): array => [ 'id' => $id ],
						[ 'ys_ec_ecpay_credit', 'ys_ec_ecpay_atm', 'ys_ec_ecpay_cvs', 'ys_ec_ecpay_barcode' ]
					) ],
					'shipping' => [ 'methods' => \YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShippingCatalog::manifest_methods() ],
				],
			];
		}
	}
}
PHP );

// ── G12 Core L1/L2/L3 任一 row 寫入失敗 → 整個 API transaction 回滾 ──
v0216l_reset( [ 'ys_ec_ecpay_enabled' => '0', 'ys_ec_ecpay_logistics_reuse_payment' => '0' ] );
$GLOBALS['v0216l_update_fail_once_keys'] = [ 'ys_capability_ys_ecpay_shipping_enabled' ];
$_POST = [ 'ys_ec_ecpay_enabled' => '1' ]; // ENABLED 0→1（同時要求 lifecycle 鏡像同步）
$r = v0216l_apply();
v0216l_check( 'G12 partial L2 lifecycle write fails the whole commit and rolls every touched row back',
	'settings_commit_failed_rolled_back' === $r
		&& '0' === ( $GLOBALS['v0216l_settings']['ys_ec_ecpay_enabled'] ?? '' )
		&& ! array_key_exists( 'ys_provider_ys_ecpay_enabled', $GLOBALS['v0216l_settings'] )
		&& ! array_key_exists( 'ys_capability_ys_ecpay_payment_enabled', $GLOBALS['v0216l_settings'] )
		&& ! array_key_exists( 'ys_methods_payment_state', $GLOBALS['v0216l_settings'] ),
	"r=$r enabled=" . var_export( $GLOBALS['v0216l_settings']['ys_ec_ecpay_enabled'] ?? null, true ) );

// ── G13 crash marker 無法驗證清除：不得回報 settings success ──
v0216l_reset( [ 'ys_ec_ecpay_enabled' => '1', 'ys_ec_ecpay_logistics_reuse_payment' => '0' ] );
$GLOBALS['v0216l_options'][ ProviderMaintenanceLock::CRASHED_FLAG ] = (string) time();
$GLOBALS['wpdb']->option_delete_fail_names = [ ProviderMaintenanceLock::CRASHED_FLAG ];
\YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleState::$enabled = true;
$_POST = [
	'ys_ec_ecpay_enabled'                      => '1',
	'ys_ec_ecpay_home_credential_family'       => 'b2c_home',
	'ys_ec_ecpay_logistics_reuse_payment'      => '1',
	'ys_ec_ecpay_payment_clear'                => '1',
	'ys_ec_ecpay_logistics_b2c_home_clear'     => '1',
	'ys_ec_ecpay_logistics_c2c_clear'          => '1',
];
$r = v0216l_apply();
v0216l_check( 'G13 failed crashed-marker clear rolls the settings commit back and reports failure',
	'settings_commit_failed_rolled_back' === $r
		&& '0' === ( $GLOBALS['v0216l_settings']['ys_ec_ecpay_logistics_reuse_payment'] ?? '' )
		&& ProviderMaintenanceLock::crashed_flag_present(),
	"r=$r reuse=" . var_export( $GLOBALS['v0216l_settings']['ys_ec_ecpay_logistics_reuse_payment'] ?? null, true ) );

// ── G14 payment/shipping tab 上的 provider legacy+lifecycle pair 也必須原子 ──
v0216l_reset( [ 'ys_ec_ecpay_enabled' => '0' ] );
$GLOBALS['v0216l_update_fail_once_keys'] = [ 'ys_provider_ys_ecpay_enabled' ];
$provider_pair_result = null;
if ( method_exists( EcpaySettings::class, 'apply_provider_enabled_atomically' ) ) {
	$provider_pair = new ReflectionMethod( EcpaySettings::class, 'apply_provider_enabled_atomically' );
	$provider_pair_result = (string) $provider_pair->invoke( null, true );
}
v0216l_check( 'G14 non-API provider mirror readback failure rolls the legacy setting back',
	'provider_lifecycle_commit_failed_rolled_back' === $provider_pair_result
		&& '0' === ( $GLOBALS['v0216l_settings']['ys_ec_ecpay_enabled'] ?? '' )
		&& ! array_key_exists( 'ys_provider_ys_ecpay_enabled', $GLOBALS['v0216l_settings'] ),
	'r=' . var_export( $provider_pair_result, true ) );

$handle_save_source = v0216l_method_source( 'src/Admin/EcpaySettings.php', 'handle_save' );
v0216l_check( 'G14b non-API handle_save routes the provider pair through the verified atomic helper',
	false !== strpos( $handle_save_source, 'apply_provider_enabled_atomically( $provider_enabled )' )
		&& false === strpos( $handle_save_source, 'self::sync_provider_lifecycle( $provider_enabled )' ) );

// ── G14c narrow payment/shipping save 不得清除 API credential crash quarantine ──
v0216l_reset( [ 'ys_ec_ecpay_enabled' => '0' ] );
$GLOBALS['v0216l_options'][ ProviderMaintenanceLock::CRASHED_FLAG ] = (string) time();
\YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleState::$enabled = false;
$provider_pair_result = (string) $provider_pair->invoke( null, true );
v0216l_check( 'G14c narrow provider save cannot clear an API credential crash marker',
	'settings_crash_repair_requires_api_tab' === $provider_pair_result
		&& '0' === ( $GLOBALS['v0216l_settings']['ys_ec_ecpay_enabled'] ?? '' )
		&& ProviderMaintenanceLock::crashed_flag_present(),
	'r=' . $provider_pair_result );

// ── G14d narrow rollback 不完整也必須留下 durable fail-closed sentinel ──
v0216l_reset();
$GLOBALS['v0216l_update_fail_keys'] = [ 'ys_provider_ys_ecpay_enabled' ];
$GLOBALS['v0216l_delete_fail_keys'] = [ Settings::ENABLED ];
$provider_pair_result = (string) $provider_pair->invoke( null, true );
$reader_after_provider_rollback = ProviderMaintenanceLock::reader_begin();
v0216l_check( 'G14d incomplete provider/lifecycle rollback retains a fail-closed sentinel',
	'provider_lifecycle_rollback_failed' === $provider_pair_result
		&& null === $reader_after_provider_rollback
		&& ( ProviderMaintenanceLock::crashed_flag_present()
			|| array_key_exists( ProviderMaintenanceLock::OPTION, $GLOBALS['v0216l_options'] ) ),
	'r=' . $provider_pair_result );

// ── G14e lifecycle 判定不呼叫會 lazy-migrate 的 Core read；直接 verified rows ──
v0216l_reset( [ 'ys_ec_ecpay_enabled' => '0' ] );
\YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleState::$enabled = false;
\YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleState::$provider_read_saw_writer = false;
$provider_pair_result = (string) $provider_pair->invoke( null, false );
v0216l_check( 'G14e provider mirror uses non-mutating DB snapshot and verified lifecycle row',
	'' === $provider_pair_result
		&& false === \YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleState::$provider_read_saw_writer
		&& '0' === ( $GLOBALS['v0216l_settings']['ys_provider_ys_ecpay_enabled'] ?? '' ),
	'r=' . $provider_pair_result );

// ── G14f API signer gate 不得透過 Core read 偷做 lazy migration ──
v0216l_reset( v0216l_full_payment( 'OLDPAY' ) + [
	'ys_ec_ecpay_enabled'        => '1',
	'ys_ec_ecpay_credit_enabled' => '0',
] );
$_POST = [ 'ys_ec_ecpay_enabled' => '1', 'ys_ec_ecpay_payment_merchant_id' => '3507531', 'ys_ec_ecpay_payment_hash_key' => 'NEWKEY' ];
$r = v0216l_apply();
v0216l_check( 'G14f refused signer rotation models missing lifecycle migration with ZERO writes',
	'payment_signer_change_requires_methods_disabled' === $r
		&& [] === $GLOBALS['v0216l_write_log']
		&& ! array_key_exists( 'ys_provider_ys_ecpay_enabled', $GLOBALS['v0216l_settings'] )
		&& ! array_key_exists( 'ys_methods_payment_state', $GLOBALS['v0216l_settings'] ),
	"r=$r writes=" . implode( ',', $GLOBALS['v0216l_write_log'] ) );

// ═══ G15：method legacy rows/list/L3 mirror 是同一個 writer transaction ═══
$method_tab = method_exists( EcpaySettings::class, 'apply_methods_tab_atomically' )
	? new ReflectionMethod( EcpaySettings::class, 'apply_methods_tab_atomically' )
	: null;
$payment_state = wp_json_encode( [
	'ys_ec_ecpay_credit'  => [ 'enabled' => false, 'order' => 0, 'provider_id' => 'ys_ecpay' ],
	'ys_ec_ecpay_atm'     => [ 'enabled' => false, 'order' => 1, 'provider_id' => 'ys_ecpay' ],
	'ys_ec_ecpay_cvs'     => [ 'enabled' => false, 'order' => 2, 'provider_id' => 'ys_ecpay' ],
	'ys_ec_ecpay_barcode' => [ 'enabled' => false, 'order' => 3, 'provider_id' => 'ys_ecpay' ],
] );
$method_base = [
	'ys_ec_ecpay_enabled'                    => '1',
	'ys_provider_ys_ecpay_enabled'            => '1',
	'ys_capability_ys_ecpay_payment_enabled'  => '1',
	'ys_capability_ys_ecpay_shipping_enabled' => '1',
	'ys_methods_payment_state'                 => $payment_state,
	'ys_ec_ecpay_credit_enabled'               => '0',
	'ys_ec_ecpay_atm_enabled'                  => '0',
	'ys_ec_ecpay_cvs_enabled'                  => '0',
	'ys_ec_ecpay_barcode_enabled'              => '0',
];

v0216l_reset( $method_base );
$_POST = [ 'ys_ec_ecpay_enabled' => '1', 'ys_ec_ecpay_credit_enabled' => '1' ];
$GLOBALS['v0216l_update_fail_once_keys'] = [ 'ys_methods_payment_state' ];
$method_result = null === $method_tab ? null : (string) $method_tab->invoke( null, 'payment', true );
v0216l_check( 'G15 L3 write/readback failure rolls method switches back',
	'method_lifecycle_commit_failed_rolled_back' === $method_result
		&& '0' === ( $GLOBALS['v0216l_settings']['ys_ec_ecpay_credit_enabled'] ?? '' )
		&& $payment_state === ( $GLOBALS['v0216l_settings']['ys_methods_payment_state'] ?? '' ),
	'r=' . var_export( $method_result, true ) );

v0216l_reset( $method_base );
$_POST = [ 'ys_ec_ecpay_enabled' => '1', 'ys_ec_ecpay_credit_enabled' => '1' ];
$method_result = null === $method_tab ? null : (string) $method_tab->invoke( null, 'payment', true );
$method_after = json_decode( (string) ( $GLOBALS['v0216l_settings']['ys_methods_payment_state'] ?? '' ), true );
v0216l_check( 'G15b successful method transaction commits legacy switch and L3 mirror together',
	'' === $method_result
		&& '1' === ( $GLOBALS['v0216l_settings']['ys_ec_ecpay_credit_enabled'] ?? '' )
		&& true === ( $method_after['ys_ec_ecpay_credit']['enabled'] ?? null ),
	'r=' . var_export( $method_result, true ) );

v0216l_reset( $method_base );
unset( $GLOBALS['v0216l_settings']['ys_ec_ecpay_credit_enabled'] );
$_POST = [ 'ys_ec_ecpay_enabled' => '1', 'ys_ec_ecpay_credit_enabled' => '1' ];
$GLOBALS['v0216l_update_fail_keys'] = [ 'ys_methods_payment_state' ];
$GLOBALS['v0216l_delete_fail_keys'] = [ 'ys_ec_ecpay_credit_enabled' ];
$method_result = null === $method_tab ? null : (string) $method_tab->invoke( null, 'payment', true );
$method_reader = ProviderMaintenanceLock::reader_begin();
v0216l_check( 'G15c incomplete method rollback keeps readers fail-closed',
	'method_lifecycle_rollback_failed' === $method_result
		&& null === $method_reader,
	'r=' . var_export( $method_result, true ) );

v0216l_check( 'G15d handle_save routes payment/shipping complete state through the atomic method transaction',
	false !== strpos( $handle_save_source, 'apply_methods_tab_atomically( $tab, $provider_enabled )' ) );

echo "\nRESULT: $pass pass / $fail fail\n";
exit( $fail > 0 ? 1 : 0 );

}
