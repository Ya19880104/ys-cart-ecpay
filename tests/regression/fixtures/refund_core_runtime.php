<?php
/**
 * Offline WP/SQL model copied from Core 82c383c1
 * tests/regression/v25604_refund_finalization_behavior.php, namespace fixtures only.
 * Production registry, handler, fences, CAS and outbox are autoloaded from --core.
 * No WordPress installation, database, provider HTTP or live state is used.
 */
declare(strict_types=1);

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'YS_ECOMMERCE_TABLE_PREFIX', 'ys_ec_' );
	define( 'DB_USER', 'test-user' );
	define( 'DB_PASSWORD', 'test-password' );
	define( 'DB_NAME', 'ys_test' );
	define( 'DB_HOST', 'test-host' );

	$GLOBALS['ys_test_options'] = [];
	$GLOBALS['ys_test_hooks']   = [];
	$GLOBALS['ys_test_hide_observer_detail'] = false;
	$GLOBALS['ys_test_hide_observer_finalization'] = false;
	$GLOBALS['ys_test_effect_time'] = '2026-07-17 12:00:00';
	$GLOBALS['ys_test_current_time'] = '2026-07-17 12:00:00';

	function wp_using_ext_object_cache(): bool {
		return false;
	}
	function add_option( string $name, $value = '', string $d = '', $autoload = 'yes' ): bool {
		if ( array_key_exists( $name, $GLOBALS['ys_test_options'] ) ) {
			return false;
		}
		$GLOBALS['ys_test_options'][ $name ] = $value;
		return true;
	}
	function get_option( string $name, $default = false ) {
		return $GLOBALS['ys_test_options'][ $name ] ?? $default;
	}
	function delete_option( string $name ): bool {
		unset( $GLOBALS['ys_test_options'][ $name ] );
		return true;
	}
	function update_option( string $name, $value, $autoload = null ): bool {
		$GLOBALS['ys_test_options'][ $name ] = $value;
		return true;
	}
	function wp_generate_uuid4(): string {
		return 'uuid-' . bin2hex( random_bytes( 6 ) );
	}
	function wp_json_encode( $data ) {
		return json_encode( $data, JSON_UNESCAPED_UNICODE );
	}
	function current_time( string $type ): string {
		return (string) $GLOBALS['ys_test_current_time'];
	}
	// R9：stub 需真的呼叫註冊的 callback（測 consumer 失敗回報與 provider sync typed result）
	$GLOBALS['ys_test_actions'] = [];
	$GLOBALS['ys_test_filters'] = [];

	function add_action( string $hook, $cb, int $priority = 10, int $args = 1 ): bool {
		$GLOBALS['ys_test_actions'][ $hook ][] = $cb;
		return true;
	}
	function do_action( string $hook, ...$args ): void {
		$GLOBALS['ys_test_hooks'][] = $hook;
		foreach ( $GLOBALS['ys_test_actions'][ $hook ] ?? [] as $cb ) {
			$cb( ...$args );
		}
	}
	function add_filter( string $hook, $cb, int $priority = 10, int $args = 1 ): bool {
		$GLOBALS['ys_test_filters'][ $hook ][] = $cb;
		return true;
	}
	function apply_filters( string $hook, $value, ...$args ) {
		foreach ( $GLOBALS['ys_test_filters'][ $hook ] ?? [] as $cb ) {
			$value = $cb( $value, ...$args );
		}
		return $value;
	}
	function is_admin(): bool {
		return false;
	}
	function sanitize_text_field( $str ): string {
		return trim( (string) $str );
	}
	function wp_cache_add( ...$args ): bool {
		return false;
	}
	function wp_cache_get( ...$args ) {
		return false;
	}
	function wp_cache_set( ...$args ): bool {
		return true;
	}
	function wp_cache_delete( ...$args ): bool {
		return true;
	}

	// wpdb stub：refund lock Path B（options 表）用 delete/update；credit transaction
	// 記錄 query log（斷言 ROLLBACK）；其餘防禦性 no-op。
	final class YSTestWpdbStub {
		private static int $next_connection_id = 100;
		public string $prefix  = 'wp_';
		public string $options = 'wp_options';
		public string $dbname  = 'ys_test';
		public string $last_error = '';
		public string $connection_id;
		public string $profile_owner = '';
		public int $autocommit_mode = 1;
		public int $transaction_active = 0;
		public ?string $idle_probe_error = null;
		public bool $closed = false;
		/** Provider/listener-owned transaction snapshot; ROLLBACK must restore it. */
		public ?array $transaction_snapshot = null;
		/** @var string[] 供 ROLLBACK/COMMIT 斷言 */
		public array $queries = [];
		private array $bound  = [];

		public function __construct( ...$ignored ) {
			unset( $ignored );
			$this->connection_id = (string) self::$next_connection_id++;
		}
		public function suppress_errors( bool $suppress = true ): bool { return $suppress; }
		public function set_prefix( string $prefix ): string {
			$this->prefix  = $prefix;
			$this->options = $prefix . 'options';
			return $prefix;
		}
		public function close(): bool { $this->closed = true; return true; }

		public function prepare( string $sql, ...$args ): string {
			$this->bound = $args;
			return $sql;
		}
		public function get_var( string $sql ) {
			// v2.58.0：冪等重放要先問「這顆退款事件還在佇列裡嗎」——在佇列裡就
			// 由 consumer 交付，重放不得同步補發（那會越過前面尚未消化的事件、
			// 之後 drain 時再執行一次）。這句必須真的答，否則整條分流測不到。
			if ( str_contains( $sql, 'status_event_queue' ) && str_contains( $sql, 'wp_ys_ec_orders' ) ) {
				$queue = \YangSheep\Ecommerce\Models\YSOrder::$state['status_event_queue'] ?? null;
				return is_string( $queue ) ? $queue : null;
			}
			return null;
		}
		public function get_row( string $sql ) {
			if ( str_contains( $sql, 'ys_external_effect_idle_admission' )
				|| str_contains( $sql, 'ys_external_effect_session_capture' )
				|| str_contains( $sql, 'ys_checkout_exact_idle_session_proof' )
				|| str_contains( $sql, 'ys_checkout_exact_session_proof' ) ) {
				return (object) [
					'autocommit_mode'   => (string) $this->autocommit_mode,
					'transaction_active' => (string) $this->transaction_active,
					'cid'                => $this->connection_id,
					'dbname'             => $this->dbname,
					'owner'              => $this->profile_owner,
				];
			}
			if ( str_contains( $sql, 'ys_site_effect_time' ) ) {
				return (object) [
					'option_name'       => 'timezone_string',
					'option_value'      => 'UTC',
					'ys_site_effect_utc' => (string) $GLOBALS['ys_test_effect_time'],
				];
			}
			if ( str_contains( $sql, 'SELECT * FROM wp_options' ) ) {
				$name = (string) ( $this->bound[0] ?? '' );
				if ( ! array_key_exists( $name, $GLOBALS['ys_test_options'] ) ) {
					return null;
				}
				return (object) [
					'option_name'  => $name,
					'option_value' => $GLOBALS['ys_test_options'][ $name ],
				];
			}
			// v2.58.0：退款結算與有序事件入列收斂為同一次 CAS，store 先以自帶
			// 註解標記的投影讀回整列（含佇列欄位與 JSON_VALID）。
			if ( str_contains( $sql, 'ys_order_status_event_row_read' ) ) {
				return (object) $this->outbox_projection();
			}
			if ( str_contains( $sql, 'ys_order_status_event_receipt_read' ) ) {
				return null; // 這份 fixture 不模型化 done 收據。
			}
			// v2.57.0：核心共用 CAS 以 get_row() 讀取 payment_detail（先前的私有實作
			// 走 YSOrder::find()）。回傳 state 內的原始值，讓 CAS 的 WHERE 條件成立。
			if ( str_contains( $sql, 'wp_ys_ec_orders' )
				&& ( str_contains( $sql, 'payment_detail' ) || str_contains( $sql, 'SELECT *' ) ) ) {
				$state = \YangSheep\Ecommerce\Models\YSOrder::$state;
				$primary = $GLOBALS['wpdb'] ?? null;
				if ( $primary instanceof self && $primary !== $this
					&& 1 === $primary->transaction_active && is_array( $primary->transaction_snapshot ) ) {
					// Distinct observer C sees only the last committed snapshot.
					$state = $primary->transaction_snapshot;
				}
				if ( ! empty( $GLOBALS['ys_test_hide_observer_detail'] )
					&& ( $GLOBALS['wpdb'] ?? null ) !== $this
					&& str_contains( $sql, 'SELECT *' ) ) {
					return null;
				}
				if ( ! empty( $GLOBALS['ys_test_hide_observer_finalization'] )
					&& ( $GLOBALS['wpdb'] ?? null ) !== $this && str_contains( $sql, 'SELECT *' ) ) {
					$hidden = json_decode( (string) ( $state['payment_detail'] ?? '{}' ), true );
					if ( is_array( $hidden['_ys_refund_finalization'] ?? null ) ) {
						foreach ( $hidden['_ys_refund_finalization'] as &$hidden_entry ) {
							if ( is_array( $hidden_entry ) && true === ( $hidden_entry['finalized'] ?? null ) ) {
								$hidden_entry['finalized'] = false;
							}
						}
						unset( $hidden_entry );
						$state['payment_detail'] = json_encode( $hidden, JSON_UNESCAPED_UNICODE );
					}
				}
				return (object) $state;
			}
			return null;
		}

		/** 訂單列在 outbox store 眼中的樣子（佇列欄位缺席時視同乾淨新列）。 */
		private function outbox_projection(): array {
			$state = \YangSheep\Ecommerce\Models\YSOrder::$state;
			$queue = array_key_exists( 'status_event_queue', $state ) ? $state['status_event_queue'] : '[]';

			return [
				'id'                          => (int) ( $state['id'] ?? 0 ),
				'status'                      => $state['status'] ?? null,
				'payment_detail'              => $state['payment_detail'] ?? null,
				'refunded_amount'             => $state['refunded_amount'] ?? null,
				'refunded_at'                 => $state['refunded_at'] ?? null,
				'status_event_seq'            => (string) ( $state['status_event_seq'] ?? '0' ),
				'status_event_queue'          => $queue,
				'status_event_pending'        => (int) ( $state['status_event_pending'] ?? 0 ),
				'status_event_available_at'   => $state['status_event_available_at'] ?? null,
				'status_event_last_published' => (string) ( $state['status_event_last_published'] ?? '0' ),
				'queue_valid'                 => is_string( $queue ) && is_array( json_decode( $queue, true ) ) ? 1 : 0,
			];
		}

		/**
		 * 有序事件 CAS（append／refund finalize）的 SQL 模型。
		 *
		 * 圍欄語意逐條對齊 production：整列 `<=>` 比對、NULL 走 IS NULL、佇列另
		 * 釘 OCTET_LENGTH 位元組長度與 JSON_VALID。寫入一律委派
		 * `YSOrder::update()`，既有的 `fail_update_calls` 注入與 `update_calls`
		 * 計數因此對這條路徑同樣有效——退款帳務的寫入失敗仍測得到。
		 */
		private function outbox_cas( string $sql ): int|false {
			$index  = 0;
			$bound  = $this->bound;
			$inline = (string) preg_replace_callback(
				'/%[sdf]/',
				static function () use ( &$index, $bound ): string {
					$value = $bound[ $index++ ] ?? null;
					return "\x01" . base64_encode( (string) $value ) . "\x01";
				},
				$sql
			);

			$where_at = strrpos( $inline, ' WHERE ' );
			$set_at   = strpos( $inline, ' SET ' );
			if ( false === $where_at || false === $set_at || $set_at > $where_at ) {
				return 0;
			}
			$set_part   = substr( $inline, $set_at + 5, $where_at - $set_at - 5 );
			$where_part = substr( $inline, $where_at + 7 );
			$row        = $this->outbox_projection();
			$decode     = static fn( string $token ): string => (string) base64_decode( trim( $token, "\x01" ), true );

			preg_match_all( '/(?:CAST\(\s*)?`(\w+)`(?:\s*AS CHAR\s*\))?\s*(IS NULL|<=> \x01[^\x01]*\x01)/', $where_part, $fences, PREG_SET_ORDER );
			foreach ( $fences as $fence ) {
				$current = $row[ $fence[1] ] ?? null;
				if ( 'IS NULL' === $fence[2] ) {
					if ( null !== $current ) {
						return 0;
					}
					continue;
				}
				if ( null === $current || (string) $current !== $decode( substr( $fence[2], 4 ) ) ) {
					return 0;
				}
			}
			if ( preg_match( '/OCTET_LENGTH\( `status_event_queue` \) = \x01([^\x01]*)\x01/', $where_part, $octet )
				&& strlen( (string) ( $row['status_event_queue'] ?? '' ) ) !== (int) base64_decode( $octet[1], true ) ) {
				return 0;
			}
			if ( str_contains( $where_part, 'JSON_VALID( `status_event_queue` )' )
				&& 1 !== (int) $row['queue_valid'] ) {
				return 0;
			}

			preg_match_all( '/`(\w+)` = (\x01[^\x01]*\x01|\d+)/', $set_part, $writes, PREG_SET_ORDER );
			$data = [];
			foreach ( $writes as $write ) {
				$data[ $write[1] ] = str_starts_with( $write[2], "\x01" ) ? $decode( $write[2] ) : (int) $write[2];
			}

			return \YangSheep\Ecommerce\Models\YSOrder::update( 0, $data ) ? 1 : false;
		}
		public function get_results( string $sql ): array {
			return [];
		}
		public function update( ...$args ): int {
			return 1;
		}
		public function delete( string $table, array $where, $formats = null ): int {
			if ( isset( $where['option_name'] ) ) {
				unset( $GLOBALS['ys_test_options'][ $where['option_name'] ] );
			}
			return 1;
		}
		public function query( string $sql ) {
			$this->queries[] = $sql;
			// Match the real MySQL-8 fence statement before the legacy fallback.
			// SET TRANSACTION refuses an active caller transaction without ending it.
			if ( '/* ys_mysql8_idle_transaction_probe */ SET TRANSACTION READ WRITE' === $sql ) {
				if ( null !== $this->idle_probe_error ) {
					$this->last_error = $this->idle_probe_error;
					return false;
				}
				if ( 1 === $this->transaction_active ) {
					$this->last_error = "Transaction characteristics can't be changed while a transaction is in progress";
					return false;
				}
				$this->last_error = '';
				return 0;
			}
			if ( str_starts_with( trim( $sql ), 'SET SESSION autocommit' ) ) {
				$this->autocommit_mode   = 1;
				$this->transaction_active = 0;
				return 0;
			}
			if ( str_starts_with( trim( $sql ), 'SET @ys_profile_tx_owner' ) ) {
				$this->profile_owner = (string) ( $this->bound[0] ?? '' );
				return 0;
			}
			if ( 'START TRANSACTION' === trim( $sql ) ) {
				if ( 0 === $this->transaction_active ) {
					$this->transaction_snapshot = \YangSheep\Ecommerce\Models\YSOrder::$state;
				}
				$this->transaction_active = 1;
				return 0;
			}
			if ( 'COMMIT' === trim( $sql ) ) {
				$this->transaction_snapshot = null;
				$this->transaction_active = 0;
				return 0;
			}
			if ( 'ROLLBACK' === trim( $sql ) ) {
				if ( is_array( $this->transaction_snapshot ) ) {
					\YangSheep\Ecommerce\Models\YSOrder::$state = $this->transaction_snapshot;
				}
				$this->transaction_snapshot = null;
				$this->transaction_active = 0;
				return 0;
			}
			if ( str_contains( $sql, 'DELETE FROM wp_options' ) ) {
				$name = (string) ( $this->bound[0] ?? '' );
				$raw  = (string) ( $this->bound[1] ?? '' );
				if ( ( $GLOBALS['ys_test_options'][ $name ] ?? null ) !== $raw ) {
					return 0;
				}
				unset( $GLOBALS['ys_test_options'][ $name ] );
				return 1;
			}
			// v2.58.0：狀態轉換／退款結算與事件入列都是同一句 CAS。
			if ( str_contains( $sql, 'ys_order_status_refund_finalize' )
				|| str_contains( $sql, 'ys_order_status_event_append' ) ) {
				return $this->outbox_cas( $sql );
			}
			// R13-F3/F4：cas_merge_payment_detail 的真 CAS UPDATE——模擬 WHERE
			// payment_detail=舊 raw 的匹配語意，並**委派 YSOrder::update** 寫回
			// state（保留既有 fail_update_calls 失敗注入與 update_calls 計數）。
			// 語意對齊真 wpdb：SQL 失敗回 false（update 注入失敗）、CAS 不匹配回 0。
			if ( str_contains( $sql, 'SET payment_detail = %s' ) && str_contains( $sql, 'wp_ys_ec_orders' ) ) {
				$new_raw = (string) ( $this->bound[0] ?? '' );
				$current = \YangSheep\Ecommerce\Models\YSOrder::$state['payment_detail'] ?? null;
				if ( str_contains( $sql, 'IS NULL' ) ) {
					if ( null !== $current ) {
						return 0; // CAS 落敗
					}
				} else {
					$old_raw = (string) ( $this->bound[3] ?? '' );
					if ( (string) $current !== $old_raw ) {
						return 0; // CAS 落敗（期間被改寫）
					}
				}
				return \YangSheep\Ecommerce\Models\YSOrder::update( 0, [ 'payment_detail' => $new_raw ] ) ? 1 : false;
			}
			return 1;
		}
	}
	class_alias( YSTestWpdbStub::class, 'wpdb' );
	$GLOBALS['wpdb'] = new \wpdb();
}

namespace YangSheep\Ecommerce\Utils {
	final class YSLogger {
		public static array $errors = [];
		public static function debug( string $c, string $m, array $x = [] ): void {}
		public static function info( string $c, string $m, array $x = [] ): void {}
		public static function warning( string $c, string $m, array $x = [] ): void {}
		public static function error( string $c, string $m, array $x = [] ): void {
			self::$errors[] = $m;
		}
	}

	final class YSPriceHelper {
		public static function round( float $v ): float {
			return round( $v );
		}
	}
}

namespace YangSheep\Ecommerce\Models {
	final class YSOrder {
		/** 訂單持久化狀態（find 反映 update 結果——值冪等測試的關鍵） */
		public static array $state = [];
		/** 第 N 次 update 呼叫（1-based，跨 process 呼叫累計）回 false */
		public static array $fail_update_calls = [];
		public static int $update_calls = 0;

		public static function seed( array $state ): void {
			self::$state             = $state;
			self::$fail_update_calls = [];
			self::$update_calls      = 0;
		}

		public static function find( int $id ): ?object {
			return (object) self::$state;
		}

		public static function update( int $id, array $data ): bool {
			++self::$update_calls;
			if ( in_array( self::$update_calls, self::$fail_update_calls, true ) ) {
				return false;
			}
			foreach ( $data as $k => $v ) {
				self::$state[ $k ] = $v;
			}
			return true;
		}

		public static function update_status( int $id, string $status ): bool {
			self::$state['status'] = $status;
			return true;
		}

		/** R13-F3/F4：cas_merge_payment_detail 依賴（真 CAS 走 wpdb stub 的委派） */
		public static function table(): string {
			return 'wp_ys_ec_orders';
		}
		public static function forget( int $id ): void {}
	}
}

namespace YangSheep\Ecommerce\Handlers {
	// stub：真 YSCreditHandler 依賴完整 DB——這裡只需可注入 add_credit 失敗（R6-F2）。
	// 必須在 require 真 YSRefundHandler.php 之前定義（同 namespace、不同 class 無衝突）。
	final class YSCreditHandler {
		public static bool $fail_next_add = false;
		public static int $add_calls      = 0;
		/** @var array<int, array{customer_id:int, amount:float, type:string}> 成功入帳紀錄 */
		public static array $granted = [];

		public function add_credit( int $customer_id, float $amount, string $type, string $note = '', int $source_id = 0 ): int {
			++self::$add_calls;
			if ( self::$fail_next_add ) {
				self::$fail_next_add = false;
				return 0; // 真實作 insert 失敗回 0
			}
			self::$granted[] = [
				'customer_id' => $customer_id,
				'amount'      => $amount,
				'type'        => $type,
			];
			return count( self::$granted );
		}
	}
}

