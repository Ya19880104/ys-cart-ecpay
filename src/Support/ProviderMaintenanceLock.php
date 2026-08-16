<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Provider 憑證互斥（v0.2.16 R14）——設定 commit（writer）與**所有讀憑證簽章／
 * 驗章的操作**（readers）之間的真互斥，不是單次唯讀檢查。
 *
 * R13 版只有 writer 鎖＋出網側 is_held() 唯讀檢查：check → 設定 acquire/commit →
 * HTTP 的窗口仍在；且 payment client、callbacks、地圖、門市、列印根本沒檢查。
 * R14 改為 reader lease：
 *
 *   writer（設定 commit）：
 *     1. NX 取 writer row（逾期/corrupt 走條件式 CAS 接管——crash 的舊 writer
 *        由**下一個 writer** 修復，reader 永不代寫）。
 *     2. 掃 reader rows：任何**未逾期** reader ＝ 有操作正在讀憑證 → 立即釋放、
 *        本次儲存拒絕（稍後重試）；逾期 reader row 以條件式 DELETE 收割
 *        （該 reader 若甦醒，pre-send reader_fence 必敗，一個 byte 都送不出去）。
 *     3. commit 期間逐鍵 fence（owner-conditional CAS renewal）。
 *
 *   reader（build 表單／簽出網請求／驗回呼）：
 *     1. writer row 存在（**不論逾期與否**）→ 拒絕：未逾期＝commit 進行中；
 *        逾期＝writer crash，憑證可能停在半套用態——收割 row、立 crashed 旗標
 *        （後台顯示「請重新儲存設定」），但**不放行**（fail-closed，直到下一次
 *        成功的設定 commit 清旗標）。
 *     2. 註冊自己的 reader row（token；TTL 短）→ **再查一次 writer**（bracket：
 *        writer 在註冊瞬間出現時，恰一方讓步，永不同時進行）。
 *     3. 送出前 reader_fence（own-row conditional CAS renewal）：被 writer 收割
 *        過的 stalled reader 在這裡失敗——不送。
 *     4. 結束 reader_end 刪自己的 row。
 *
 * fail-closed：wpdb 不可用時 writer=null、reader=null（拒送勝於無互斥出網）。
 */
final class ProviderMaintenanceLock {
	public const OPTION = 'ys_ec_ecpay_maintenance_lock';

	/** reader row 前綴（後接 12-hex token）。 */
	public const READER_PREFIX = 'ys_ec_ecpay_credread_';

	/** writer crash 旗標：reader 收割逾期 writer 時立起，成功 commit 清除。 */
	public const CRASHED_FLAG = 'ys_ec_ecpay_maintenance_crashed';

	/** 秒。設定 commit 是幾個 DB 寫入，2 分鐘遠大於任何正常 request。 */
	public const TTL = 120;

	/** 秒。單一出網操作（HTTP timeout 20s）＋餘裕。 */
	public const READER_TTL = 45;

	// ── writer ──────────────────────────────────────────────────────

	public static function acquire(): ?string {
		global $wpdb;
		if ( ! self::wpdb_usable( $wpdb ) ) {
			return null;
		}

		$owner    = bin2hex( random_bytes( 12 ) );
		$inserted = $wpdb->insert( $wpdb->options, [
			'option_name'  => self::OPTION,
			'option_value' => self::value( $owner ),
			'autoload'     => 'no',
		] );
		if ( 1 !== $inserted ) {
			$read_ok = false;
			$held    = self::option_value( $wpdb, self::OPTION, $read_ok );
			if ( ! $read_ok || null === $held ) {
				return null; // insert 撞了但讀不到列＝競態中；放棄。
			}
			$ts = self::value_ts( $held );
			if ( $ts > 0 && ( time() - $ts ) < self::TTL ) {
				return null; // 持有中且未逾期。
			}
			if ( ! self::ensure_crashed_flag( $wpdb ) ) {
				return null; // 舊 writer 可能寫到一半；無 durable marker 不接管。
			}
			// 逾期或 corrupt：條件式接管（舊值不符＝別人已接管）。
			$taken = $wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				self::value( $owner ),
				self::OPTION,
				$held
			) );
			if ( 1 !== $taken ) {
				return null;
			}
		}

		// 🔴 掃 readers：任何未逾期 reader ＝ 出網操作正在讀憑證——writer 讓步
		// （釋放並拒絕本次儲存）；逾期 reader 收割（其甦醒後 reader_fence 必敗）。
		if ( ! self::readers_clear( $wpdb ) ) {
			self::release( $owner );
			return null;
		}
		if ( ! self::claim_crashed_flag( $wpdb, $owner ) ) {
			self::release( $owner );
			return null;
		}

		return $owner;
	}

	/**
	 * mutation fence：owner-conditional lease renewal（原子 CAS）。
	 * 成功＝仍持鎖且 lease 剛刷新；rows=0＝已被接管→呼叫端立即中止。
	 */
	public static function fence( string $owner ): bool {
		global $wpdb;
		if ( '' === $owner || ! self::wpdb_usable( $wpdb ) ) {
			return false;
		}
		$renewed = $wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value LIKE %s",
			self::value( $owner ),
			self::OPTION,
			$owner . '|%'
		) );

		return 1 === $renewed;
	}

	public static function release( string $owner ): void {
		global $wpdb;
		if ( '' === $owner || ! self::wpdb_usable( $wpdb ) ) {
			return;
		}
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s",
			self::OPTION,
			$owner . '|%'
		) );
	}

	/** 成功 commit 後由 writer 清 crashed 旗標。 */
	public static function clear_crashed_flag( string $owner ): bool {
		global $wpdb;
		if ( '' === $owner || ! self::wpdb_usable( $wpdb ) ) {
			return false;
		}
		if ( ! self::fence( $owner ) ) {
			return false;
		}
		$read_ok = false;
		$marker  = self::option_value( $wpdb, self::CRASHED_FLAG, $read_ok );
		if ( ! $read_ok ) {
			return false;
		}
		if ( null === $marker ) {
			return self::fence( $owner );
		}
		if ( 0 !== strpos( $marker, 'repair|' . $owner . '|' ) ) {
			return false;
		}
		$writer_ok = false;
		$writer    = self::option_value( $wpdb, self::OPTION, $writer_ok );
		if ( ! $writer_ok || null === $writer || 0 !== strpos( $writer, $owner . '|' ) ) {
			return false;
		}
		try {
			$deleted = $wpdb->query( $wpdb->prepare(
				"DELETE crashed FROM {$wpdb->options} AS crashed
				 INNER JOIN {$wpdb->options} AS writer
				   ON writer.option_name = %s AND writer.option_value = %s
				 WHERE crashed.option_name = %s AND crashed.option_value = %s",
				self::OPTION,
				$writer,
				self::CRASHED_FLAG,
				$marker
			) );
		} catch ( \Throwable $e ) {
			return false;
		}

		return 1 === $deleted
			&& false === self::crashed_flag_state( $wpdb )
			&& self::fence( $owner );
	}

	/**
	 * Preserve fail-closed quarantine after an incomplete writer rollback.
	 *
	 * If the marker cannot be persisted/read back, callers must retain the
	 * writer row instead of releasing an unmarked mixed state to readers.
	 */
	public static function mark_crashed( string $owner ): bool {
		global $wpdb;
		if ( '' === $owner || ! self::wpdb_usable( $wpdb ) || ! self::fence( $owner ) ) {
			return false;
		}
		if ( ! self::ensure_crashed_flag( $wpdb ) || ! self::claim_crashed_flag( $wpdb, $owner ) ) {
			return false;
		}

		return true === self::crashed_flag_state( $wpdb ) && self::fence( $owner );
	}

	/** 後台顯示用：前次設定 commit crash、憑證狀態未經驗證。 */
	public static function crashed_flag_present(): bool {
		global $wpdb;
		if ( ! self::wpdb_readable( $wpdb ) ) {
			return true;
		}
		$state = self::crashed_flag_state( $wpdb );

		return null === $state ? true : $state;
	}

	// ── readers ─────────────────────────────────────────────────────

	/**
	 * 開始一個讀憑證的操作。null＝現在不可以（writer 進行中／crash 未修復／
	 * wpdb 不可用）——呼叫端以「未送出任何請求」語意拒絕並允許稍後重試。
	 */
	public static function reader_begin(): ?string {
		global $wpdb;
		if ( ! self::wpdb_usable( $wpdb ) ) {
			return null;
		}

		if ( self::crashed_flag_present() ) {
			return null;
		}

		if ( ! self::writer_clear( $wpdb ) ) {
			return null;
		}

		$token    = bin2hex( random_bytes( 6 ) );
		$inserted = $wpdb->insert( $wpdb->options, [
			'option_name'  => self::READER_PREFIX . $token,
			'option_value' => self::value( $token ),
			'autoload'     => 'no',
		] );
		if ( 1 !== $inserted ) {
			return null;
		}

		// 🔴 bracket 再查：writer 在我們註冊的瞬間出現＝它的 reader 掃描可能
		// 沒看到我們——讓步（刪自己的 row、拒絕本操作）。兩邊各自「先登記、
		// 再看對方」，任何交錯下恰一方（或雙方）讓步，永不同時進行。
		if ( self::crashed_flag_present() || ! self::writer_clear( $wpdb, false ) ) {
			self::reader_end( $token );
			return null;
		}

		return $token;
	}

	/**
	 * 送出前的 own-row fence：被 writer 收割過（stalled 超過 READER_TTL）的
	 * reader 在此失敗——一個 byte 都不出網。
	 */
	public static function reader_fence( string $token ): bool {
		global $wpdb;
		if ( '' === $token || ! self::wpdb_usable( $wpdb ) ) {
			return false;
		}
		$renewed = $wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value LIKE %s",
			self::value( $token ),
			self::READER_PREFIX . $token,
			$token . '|%'
		) );

		return 1 === $renewed;
	}

	/**
	 * RAII 形式：離開作用域（任何 return／throw）自動 reader_end——多出口的
	 * 呼叫端不需要 try/finally 重排。null＝拒絕（同 reader_begin）。
	 */
	public static function reader_lease(): ?object {
		$token = self::reader_begin();
		if ( null === $token ) {
			return null;
		}

		return new class( $token ) {
			public string $token;
			public function __construct( string $token ) {
				$this->token = $token;
			}
			public function __destruct() {
				ProviderMaintenanceLock::reader_end( $this->token );
			}
		};
	}

	public static function reader_end( string $token ): void {
		global $wpdb;
		if ( '' === $token || ! self::wpdb_usable( $wpdb ) ) {
			return;
		}
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s",
			self::READER_PREFIX . $token,
			$token . '|%'
		) );
	}

	/**
	 * 診斷用（settings 頁顯示）：writer row 目前存在且未逾期。
	 * 🔴 出網互斥**不得**依賴本方法（check-then-act 不是互斥）——用 reader lease。
	 */
	public static function is_held(): bool {
		global $wpdb;
		if ( ! self::wpdb_readable( $wpdb ) ) {
			return true; // fail-closed。
		}
		$read_ok = false;
		$held    = self::option_value( $wpdb, self::OPTION, $read_ok );
		if ( ! $read_ok ) {
			return true;
		}
		if ( null === $held ) {
			return false;
		}
		$ts = self::value_ts( $held );
		if ( $ts <= 0 ) {
			return true; // corrupt＝unknown＝fail-closed。
		}

		return ( time() - $ts ) < self::TTL;
	}

	// ── internals ───────────────────────────────────────────────────

	/**
	 * writer row 必須缺席才回 true。逾期 writer：$reap 時收割＋立 crashed 旗標，
	 * 但**仍回 false**——crash 的 commit 可能寫到一半，憑證未經驗證前不出網；
	 * 修復途徑＝下一次成功的設定儲存（writer 端 acquire→commit→清旗標）。
	 */
	private static function writer_clear( $wpdb, bool $reap = true ): bool {
		$read_ok = false;
		$held    = self::option_value( $wpdb, self::OPTION, $read_ok );
		if ( ! $read_ok ) {
			return false;
		}
		if ( null === $held ) {
			return true;
		}
		$ts      = self::value_ts( $held );
		$expired = $ts > 0 && ( time() - $ts ) >= self::TTL;
		if ( $reap && ( $expired || $ts <= 0 ) ) {
			// 先持久化 crashed 旗標，再刪 writer。若旗標不能寫入，
			// 必須留下 stale writer 當 fail-closed sentinel，不可產生無列無旗標窗口。
			if ( ! self::ensure_crashed_flag( $wpdb ) ) {
				return false;
			}

			try {
				$wpdb->query( $wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
					self::OPTION,
					$held
				) );
			} catch ( \Throwable $e ) {
				return false;
			}
		}

		return false;
	}

	/** 無未逾期 reader 才回 true；逾期 reader 一律條件式收割。 */
	private static function readers_clear( $wpdb ): bool {
		if ( ! method_exists( $wpdb, 'get_results' ) || ! method_exists( $wpdb, 'esc_like' ) ) {
			return false; // 讀不到 reader 集合＝無法證明安全＝拒絕。
		}
		try {
			self::clear_last_error( $wpdb );
			$reader_like = (string) $wpdb->esc_like( self::READER_PREFIX ) . '%';
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
				$reader_like
			) );
		} catch ( \Throwable $e ) {
			return false;
		}
		if ( self::has_last_error( $wpdb ) ) {
			return false;
		}
		if ( ! is_array( $rows ) ) {
			return false;
		}
		$clear = true;
		foreach ( $rows as $row ) {
			$name  = (string) ( $row->option_name ?? '' );
			$value = (string) ( $row->option_value ?? '' );
			$ts    = self::value_ts( $value );
			if ( $ts > 0 && ( time() - $ts ) < self::READER_TTL ) {
				$clear = false; // 活著的 reader：writer 讓步。
				continue;
			}
			// 逾期／corrupt reader：條件式收割（甦醒後 reader_fence 必敗）。
			try {
				$reaped = $wpdb->query( $wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
					$name,
					$value
				) );
			} catch ( \Throwable $e ) {
				$reaped = false;
			}
			if ( 1 !== $reaped ) {
				$clear = false;
			}
		}

		return $clear;
	}

	private static function value( string $owner ): string {
		// nonce：conditional UPDATE 每次寫入必相異——同秒 renewal 不會因
		// 「值未變、affected rows=0」誤判 ownership 丟失。
		return $owner . '|' . time() . '|' . bin2hex( random_bytes( 4 ) );
	}

	/** @return ?bool true=present, false=absent, null=unknown/read error. */
	private static function crashed_flag_state( $wpdb ): ?bool {
		$read_ok = false;
		$value   = self::option_value( $wpdb, self::CRASHED_FLAG, $read_ok );
		if ( ! $read_ok ) {
			return null;
		}

		return null !== $value;
	}

	/** Persist and read back the fail-closed crash marker before removing/taking a stale writer. */
	private static function ensure_crashed_flag( $wpdb ): bool {
		$state = self::crashed_flag_state( $wpdb );
		if ( true === $state ) {
			return true;
		}
		if ( null === $state ) {
			return false;
		}
		try {
			$wpdb->insert( $wpdb->options, [
				'option_name'  => self::CRASHED_FLAG,
				'option_value' => (string) time(),
				'autoload'     => 'no',
			] );
		} catch ( \Throwable $e ) {
			return false;
		}

		return true === self::crashed_flag_state( $wpdb );
	}

	/** Bind an existing crash marker to this writer so only this owner may clear it. */
	private static function claim_crashed_flag( $wpdb, string $owner ): bool {
		$read_ok = false;
		$current = self::option_value( $wpdb, self::CRASHED_FLAG, $read_ok );
		if ( ! $read_ok ) {
			return false;
		}
		if ( null === $current ) {
			return true;
		}
		$claimed = 'repair|' . $owner . '|' . time() . '|' . bin2hex( random_bytes( 4 ) );
		try {
			$updated = $wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$claimed,
				self::CRASHED_FLAG,
				$current
			) );
		} catch ( \Throwable $e ) {
			return false;
		}
		if ( 1 !== $updated ) {
			return false;
		}

		$verify_ok = false;
		$verify    = self::option_value( $wpdb, self::CRASHED_FLAG, $verify_ok );

		return $verify_ok && $claimed === $verify;
	}

	/** @return ?string null means the option row is absent; $ok distinguishes DB failure. */
	private static function option_value( $wpdb, string $name, bool &$ok ): ?string {
		$ok = false;
		try {
			self::clear_last_error( $wpdb );
			$value = $wpdb->get_var( $wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				$name
			) );
		} catch ( \Throwable $e ) {
			return null;
		}
		if ( self::has_last_error( $wpdb ) ) {
			return null;
		}
		$ok = true;

		return null === $value ? null : (string) $value;
	}

	private static function clear_last_error( $wpdb ): void {
		if ( property_exists( $wpdb, 'last_error' ) ) {
			$wpdb->last_error = '';
		}
	}

	private static function has_last_error( $wpdb ): bool {
		return property_exists( $wpdb, 'last_error' ) && '' !== (string) $wpdb->last_error;
	}

	private static function value_ts( string $value ): int {
		$parts = explode( '|', $value );

		return (int) ( $parts[1] ?? 0 );
	}

	private static function wpdb_usable( $wpdb ): bool {
		return self::wpdb_readable( $wpdb )
			&& method_exists( $wpdb, 'insert' )
			&& method_exists( $wpdb, 'query' );
	}

	private static function wpdb_readable( $wpdb ): bool {
		return is_object( $wpdb )
			&& method_exists( $wpdb, 'prepare' )
			&& method_exists( $wpdb, 'get_var' )
			&& ! empty( $wpdb->options );
	}
}
