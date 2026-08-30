<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Shipping\Ecpay;

defined( 'ABSPATH' ) || exit;

/**
 * Durable store for subscription-bound store selections.
 *
 * 訂閱綁定的選店 token 不能放 transient：外部 object cache 的刪除是 MySQL
 * ROLLBACK 救不回來的外部耐久寫，Core 的 profile CAS 一旦 COMMIT 失敗，token
 * 就永遠消失（frozen blocker I2）。因此這一類 token 的**唯一** authority 放在
 * wp_options 的一列（raw wpdb 直讀直寫），一次性認領是同一條 global $wpdb、
 * 同一個 InnoDB 交易內的 issued→consumed **exact BINARY bytes CAS**：
 * Core ROLLBACK ⇒ generation=N 且 token 回到 issued；COMMIT ⇒ N+1 且 consumed。
 *
 * 契約（缺一即 fail closed）：
 * - options 表必須以 SHOW TABLE STATUS 證明是 InnoDB；否則拒發、拒認領。
 * - 新列 autoload='no'；option_name 是 token 的 sha256 digest，raw token bytes
 *   永不落入 option_name 或 option_value。
 * - issue／read／claim／cleanup 全走 raw $wpdb——絕不呼叫 option/transient/
 *   cache API（那會建立第二份（快取）authority，與交易內狀態脫鉤）。
 * - 本類別不發 START/COMMIT/ROLLBACK，也不另開連線：認領一律騎乘呼叫端
 *   （Core coordinator）已開啟的交易與同一個 global $wpdb handle。
 * - 到期以列內 expires_at（UTC epoch）判定；清理只刪已到期列，且帶 exact
 *   bytes 守門。
 */
final class EcpaySubscriptionSelectionStore {

	private const OPTION_PREFIX = 'ys_ec_ecpay_subsel_';

	public const STATE_ISSUED = 'issued';

	public const STATE_CONSUMED = 'consumed';

	/**
	 * 實體 session fence（跨 repo 契約，拼法與 Core
	 * YSSubscription::PROFILE_SESSION_FENCE_SQL_V1 逐字相同）：同一個 PHP wpdb
	 * 物件不等於同一條 MySQL session——errno 2006 時 wpdb 會自動重連並在**新
	 * session**（autocommit、Core 交易已消失）上重送同一句 SQL。consume UPDATE
	 * 因此把 Core 凍結的 CONNECTION_ID／DATABASE／@ys_profile_tx_owner nonce 放進
	 * 同一句 statement 的 predicate：重送必然 0 列，token 絕不會在交易外被消耗。
	 */
	public const PROFILE_SESSION_FENCE_SQL_V1 = 'CAST(CAST(CONNECTION_ID() AS CHAR) AS BINARY) = CAST(%s AS BINARY)'
		. ' AND CAST(DATABASE() AS BINARY) = CAST(%s AS BINARY)'
		. ' AND CAST(CAST(@ys_profile_tx_owner AS CHAR) AS BINARY) = CAST(%s AS BINARY)';

	/**
	 * Per-request memo of the InnoDB proof，**綁定 exact handle＋options 表名**：
	 * global $wpdb 被換掉（drift）或表名不同時，memo 失效並重新驗證。
	 * WeakReference：物件身分不能拿 spl_object_id 之類可重用的鍵來記。
	 */
	private static ?\WeakReference $proof_handle = null;

	private static string $proof_options = '';

	private static bool $proof_ok = false;

	/** option_name＝前綴＋token digest；raw token 永不進 DB。 */
	private static function option_name( string $token ): string {
		return self::OPTION_PREFIX . hash( 'sha256', $token );
	}

	/**
	 * 共用 handle 守門：全域 wpdb 必須可用且未被 Core 邊界封鎖（ready!==false）。
	 * 帶 $expected 時＝same-handle fence：目前的 global 必須是**同一個物件**——
	 * Core 交易起始時凍結的那一條連線。global 被換掉（drift）時一律 fail
	 * closed，絕不在第二條連線上執行消耗。
	 */
	private static function usable_wpdb( ?object $expected = null ): ?object {
		global $wpdb;
		if ( ! is_object( $wpdb )
			|| empty( $wpdb->options )
			|| ! method_exists( $wpdb, 'prepare' )
			|| ! method_exists( $wpdb, 'query' )
			|| ! method_exists( $wpdb, 'get_row' )
			|| ! method_exists( $wpdb, 'get_var' )
			|| ! method_exists( $wpdb, 'insert' ) ) {
			return null;
		}
		if ( property_exists( $wpdb, 'ready' ) && false === $wpdb->ready ) {
			return null;
		}
		if ( null !== $expected && $wpdb !== $expected ) {
			return null;
		}
		return $wpdb;
	}

	/**
	 * SHOW TABLE STATUS 證明 options 表是 InnoDB。
	 *
	 * MyISAM／未知引擎沒有交易語意——認領會變成「假裝可回滾」的外部寫，
	 * 一律 fail closed。SHOW 不是 DDL，不會隱式提交呼叫端的交易。
	 * 證明綁 handle＋表名：連線一換即重驗。
	 */
	public static function storage_ready( ?object $expected = null ): bool {
		$wpdb = self::usable_wpdb( $expected );
		if ( null === $wpdb ) {
			return false;
		}
		if ( null !== self::$proof_handle
			&& self::$proof_handle->get() === $wpdb
			&& self::$proof_options === (string) $wpdb->options ) {
			return self::$proof_ok;
		}
		$wpdb->last_error = '';
		$status = $wpdb->get_row(
			$wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $wpdb->options ),
			ARRAY_A
		);
		self::$proof_ok = is_array( $status )
			&& 0 === strcasecmp( (string) ( $status['Engine'] ?? '' ), 'InnoDB' )
			&& '' === (string) ( $wpdb->last_error ?? '' );
		self::$proof_handle  = \WeakReference::create( $wpdb );
		self::$proof_options = (string) $wpdb->options;
		return self::$proof_ok;
	}

	/** @param array<string,mixed> $row */
	private static function encode( array $row ): ?string {
		$encoded = wp_json_encode( $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return is_string( $encoded ) && '' !== $encoded ? $encoded : null;
	}

	/**
	 * 簽發一列 issued。撞名（digest 撞列）＝直接失敗，由呼叫端重擲 token。
	 *
	 * @param array<string,mixed> $record 伺服器擁有的選店紀錄（含 expires_at）。
	 */
	public static function issue( string $token, array $record ): bool {
		$wpdb = self::usable_wpdb();
		if ( null === $wpdb || '' === $token || ! self::storage_ready() ) {
			return false;
		}
		$payload = self::encode( [ 'state' => self::STATE_ISSUED, 'record' => $record ] );
		if ( null === $payload ) {
			return false;
		}
		$wpdb->last_error = '';
		$inserted = $wpdb->insert( $wpdb->options, [
			'option_name'  => self::option_name( $token ),
			'option_value' => $payload,
			'autoload'     => 'no',
		] );
		return 1 === $inserted && '' === (string) ( $wpdb->last_error ?? '' );
	}

	/**
	 * 直讀（read-only）。在呼叫端交易內讀＝一致性讀，看得到自己交易的狀態。
	 *
	 * @return array{state:string,record:array<string,mixed>,bytes:string}|null
	 */
	public static function read( string $token ): ?array {
		$wpdb = self::usable_wpdb();
		if ( null === $wpdb || '' === $token || ! self::storage_ready() ) {
			return null;
		}
		$wpdb->last_error = '';
		$bytes = $wpdb->get_var( $wpdb->prepare(
			"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
			self::option_name( $token )
		) );
		if ( ! is_string( $bytes ) || '' === $bytes || '' !== (string) ( $wpdb->last_error ?? '' ) ) {
			return null;
		}
		$decoded = json_decode( $bytes, true );
		if ( ! is_array( $decoded )
			|| ! in_array( (string) ( $decoded['state'] ?? '' ), [ self::STATE_ISSUED, self::STATE_CONSUMED ], true )
			|| ! is_array( $decoded['record'] ?? null ) ) {
			return null;
		}
		return [
			'state'  => (string) $decoded['state'],
			'record' => $decoded['record'],
			'bytes'  => $bytes,
		];
	}

	/**
	 * issued→consumed 的 exact BINARY bytes CAS。
	 *
	 * 必須在呼叫端（Core coordinator）已開啟的交易內執行：這裡**不**發任何
	 * transaction control，也不換 handle。rows=1 才算贏；0＝已被消耗或位元組
	 * 已變（併發輸家），呼叫端據此拒絕並回滾自己的 CAS。
	 */
	/**
	 * 驗證 Core 交易圍籬（物件＋實體 session 原始值）；無效＝null＝fail closed。
	 *
	 * @param array<string,mixed> $session_fence
	 * @return array{transaction_db:object,connection_id:string,database:string,owner_nonce:string}|null
	 */
	private static function claim_session_fence( array $session_fence ): ?array {
		$transaction_db = $session_fence['transaction_db'] ?? null;
		$connection_id  = $session_fence['connection_id'] ?? null;
		$database       = $session_fence['database'] ?? null;
		$owner_nonce    = $session_fence['owner_nonce'] ?? null;
		if ( ! is_object( $transaction_db )
			|| ! is_string( $connection_id ) || 1 !== preg_match( '/^[0-9]{1,20}$/D', $connection_id )
			|| ! is_string( $database ) || '' === $database || strlen( $database ) > 64
			|| ! is_string( $owner_nonce ) || 1 !== preg_match( '/^[a-f0-9]{32}$/D', $owner_nonce ) ) {
			return null;
		}
		return [
			'transaction_db' => $transaction_db,
			'connection_id'  => $connection_id,
			'database'       => $database,
			'owner_nonce'    => $owner_nonce,
		];
	}

	/**
	 * issued→consumed 的 exact BINARY bytes CAS，帶實體 session fence。
	 *
	 * 必須在呼叫端（Core coordinator）已開啟的交易內、同一條凍結連線上執行：
	 * 這裡**不**發任何 transaction control，也不換 handle。物件同一性只是第一
	 * 道便宜圍籬——真正擋 2006 重連重送的是同一句 statement 內的凍結
	 * CONNECTION_ID／DATABASE／owner nonce predicate。rows=1 才算贏；0＝已被
	 * 消耗、位元組已變、或 session 已 drift（呼叫端據此拒絕並回滾自己的 CAS，
	 * 絕不補送）。
	 *
	 * @param array<string,mixed> $session_fence Core 凍結的圍籬（物件＋三原始值）。
	 */
	public static function claim( string $token, string $expected_bytes, int $subscription_id, int $target_generation, array $session_fence = [] ): bool {
		$fence = self::claim_session_fence( $session_fence );
		if ( null === $fence ) {
			return false;
		}
		$wpdb = self::usable_wpdb( $fence['transaction_db'] );
		if ( null === $wpdb || '' === $token || '' === $expected_bytes
			|| $subscription_id < 1 || $target_generation < 1 || ! self::storage_ready( $fence['transaction_db'] ) ) {
			return false;
		}
		$decoded = json_decode( $expected_bytes, true );
		if ( ! is_array( $decoded )
			|| self::STATE_ISSUED !== (string) ( $decoded['state'] ?? '' )
			|| ! is_array( $decoded['record'] ?? null )
			// 縱深防禦：CAS 前重驗列內綁定——訂閱 id 必須一致、且未到期。
			|| $subscription_id !== (int) ( $decoded['record']['subscription_id'] ?? 0 )
			|| (int) ( $decoded['record']['expires_at'] ?? 0 ) <= time() ) {
			return false;
		}
		$decoded['state']    = self::STATE_CONSUMED;
		$decoded['consumed'] = [
			'subscription_id' => $subscription_id,
			'generation'      => $target_generation,
			'at'              => (string) current_time( 'mysql' ),
		];
		$payload = self::encode( $decoded );
		if ( null === $payload ) {
			return false;
		}
		$wpdb->last_error = '';
		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = BINARY %s AND " . self::PROFILE_SESSION_FENCE_SQL_V1,
			$payload,
			self::option_name( $token ),
			$expected_bytes,
			$fence['connection_id'],
			$fence['database'],
			$fence['owner_nonce']
		) );
		return 1 === $updated && '' === (string) ( $wpdb->last_error ?? '' );
	}

	/** 補償刪除（發證流程失敗時回收）；raw、以名稱刪。 */
	public static function delete( string $token ): bool {
		$wpdb = self::usable_wpdb();
		if ( null === $wpdb || '' === $token ) {
			return false;
		}
		$wpdb->last_error = '';
		$deleted = $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name = %s",
			self::option_name( $token )
		) );
		return 1 === $deleted && '' === (string) ( $wpdb->last_error ?? '' );
	}

	/**
	 * 機會式清理：只刪已到期列（transient 的 TTL GC 在這條 durable 路徑不存在）。
	 * 只在簽發時（交易外）被呼叫；每次最多掃 $limit 列，DELETE 帶 exact bytes。
	 */
	public static function cleanup_expired( int $limit = 20 ): void {
		$wpdb = self::usable_wpdb();
		if ( null === $wpdb || $limit < 1 || ! self::storage_ready()
			|| ! method_exists( $wpdb, 'get_results' ) || ! method_exists( $wpdb, 'esc_like' ) ) {
			return;
		}
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT %d",
			$wpdb->esc_like( self::OPTION_PREFIX ) . '%',
			$limit
		), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return;
		}
		$now = time();
		foreach ( $rows as $row ) {
			$bytes = (string) ( $row['option_value'] ?? '' );
			$decoded = json_decode( $bytes, true );
			$expires_at = is_array( $decoded ) ? (int) ( $decoded['record']['expires_at'] ?? 0 ) : 0;
			if ( $expires_at > $now ) {
				continue;
			}
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = BINARY %s",
				(string) ( $row['option_name'] ?? '' ),
				$bytes
			) );
		}
	}
}
