<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Cli;

defined( 'ABSPATH' ) || exit;

use YangSheep\Ecommerce\Models\YSOrder;
use YangSheep\Ecommerce\Utils\YSLogger;

/**
 * 退款 attempt 人工核定 CLI（v0.3.0，CODEX 終審 F4）
 *
 * 「全單凍結」的唯一合法解除入口：管理員於綠界後台確認實際狀態後，
 * 以本命令把結果未明（pending）的 attempt 核定為 done／failed。
 *
 *   wp ys-ecpay refund-attempts list --order=<id>
 *   wp ys-ecpay refund-attempts resolve --order=<id> --request=<request_id> --mark=done|failed [--trade-no=<no>]
 *
 * CAS 保證：resolve 於寫入前重讀最新 payment_detail，僅當該 entry 仍為
 * pending 才改寫（比對舊值＝application-level compare-and-set）；已被其他
 * 程序改寫則中止並要求重查。權限＝WP-CLI（伺服器 shell 等級，等同 admin）。
 */
final class EcpayRefundAttemptCommand {

	public static function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( '\WP_CLI' ) ) {
			return;
		}
		\WP_CLI::add_command( 'ys-ecpay refund-attempts', self::class );
	}

	/**
	 * 列出訂單的退款 attempt 歷程。
	 *
	 * @subcommand list
	 * @synopsis --order=<id>
	 */
	public function list_attempts( array $args, array $assoc ): void {
		$order_id = (int) ( $assoc['order'] ?? 0 );
		$order    = $order_id > 0 ? YSOrder::find( $order_id ) : null;
		if ( ! $order ) {
			\WP_CLI::error( "訂單 {$order_id} 不存在。" );
		}

		$detail  = json_decode( (string) ( $order->payment_detail ?? '{}' ), true ) ?: [];
		$history = is_array( $detail['_ys_ecpay_refunds'] ?? null ) ? $detail['_ys_ecpay_refunds'] : [];
		if ( empty( $history ) ) {
			\WP_CLI::log( '（無退款 attempt 紀錄）' );
			return;
		}

		foreach ( $history as $request_id => $entry ) {
			\WP_CLI::log( sprintf(
				'%s  status=%s  amount=%s  executed=%s  time=%s',
				(string) $request_id,
				(string) ( $entry['status'] ?? '?' ),
				(string) ( $entry['amount'] ?? '?' ),
				(string) ( $entry['executed'] ?? $entry['plan'] ?? '-' ),
				(string) ( $entry['time'] ?? '-' )
			) );
		}
	}

	/**
	 * 核定一筆結果未明的 attempt（CAS：僅 pending 可核定）。
	 *
	 * @subcommand resolve
	 * @synopsis --order=<id> --request=<request_id> --mark=<done|failed> [--trade-no=<no>]
	 */
	public function resolve( array $args, array $assoc ): void {
		$order_id   = (int) ( $assoc['order'] ?? 0 );
		$request_id = (string) ( $assoc['request'] ?? '' );
		$mark       = (string) ( $assoc['mark'] ?? '' );

		if ( ! in_array( $mark, [ 'done', 'failed' ], true ) ) {
			\WP_CLI::error( '--mark 僅接受 done 或 failed。' );
		}
		if ( '' === $request_id ) {
			\WP_CLI::error( '--request 不得為空。' );
		}

		// 真 CAS（CODEX 終審 R6-F6）：直接讀 orders 表 payment_detail 原始字串
		//（繞開任何模型層快取），改寫後以「WHERE id AND payment_detail=舊 raw」
		// 條件寫入——affected rows==1 才算成功；0＝期間被其他程序（gateway 回寫、
		// 後台操作、另一 CLI）改寫，中止並要求重查。先前的 read-modify-write
		//（YSOrder::update 無條件覆寫）存在 lost-update 窗口，並非 CAS。
		global $wpdb;
		$table   = YSOrder::table();
		$old_raw = $wpdb->get_var( $wpdb->prepare(
			"SELECT payment_detail FROM {$table} WHERE id = %d",
			$order_id
		) );
		if ( null === $old_raw ) {
			\WP_CLI::error( "訂單 {$order_id} 不存在。" );
		}

		$detail  = json_decode( (string) $old_raw, true ) ?: [];
		$history = is_array( $detail['_ys_ecpay_refunds'] ?? null ) ? $detail['_ys_ecpay_refunds'] : [];
		$entry   = $history[ $request_id ] ?? null;

		if ( ! is_array( $entry ) ) {
			\WP_CLI::error( "attempt {$request_id} 不存在。" );
		}
		if ( 'pending' !== ( $entry['status'] ?? '' ) ) {
			\WP_CLI::error( sprintf(
				'attempt %s 目前狀態為 %s（非 pending）——可能已被其他程序核定，請重新 list 確認後再操作。',
				$request_id,
				(string) ( $entry['status'] ?? '?' )
			) );
		}

		$entry['status']      = $mark;
		$entry['resolved_by'] = 'wp-cli';
		$entry['resolved_at'] = current_time( 'mysql' );
		if ( 'done' === $mark && '' !== (string) ( $assoc['trade-no'] ?? '' ) ) {
			$entry['trade_no'] = sanitize_text_field( (string) $assoc['trade-no'] );
		}

		$history[ $request_id ]      = $entry;
		$detail['_ys_ecpay_refunds'] = $history;

		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET payment_detail = %s WHERE id = %d AND payment_detail = %s",
			wp_json_encode( $detail ),
			$order_id,
			(string) $old_raw
		) );
		if ( 1 !== (int) $updated ) {
			\WP_CLI::error( 'CAS 失敗：payment_detail 於核定期間已被其他程序改寫，未寫入任何變更；請重新 list 確認最新狀態後再操作。' );
		}

		\WP_CLI::success( sprintf(
			'attempt %s 已核定為 %s。%s',
			$request_id,
			$mark,
			'done' === $mark
				// R8-F3：核心 ledger 不會被本命令解除——核心的 submitting 凍結會擋在
				// gateway 呼叫之前，「以相同退款操作補齊核心帳務」根本進不到本外掛。
				// 正確入口＝核心 CLI（它會續作帳務並回頭同步本外掛紀錄）。
				? ' 提醒：本命令只核定「綠界端」紀錄；核心帳務請執行'
					. ' wp ys-cart refund-finalization resolve --order=' . $order_id
					. ' --request=' . $request_id . ' --mark=paid'
					. '（會續作核心帳務，並自動同步本外掛的退款紀錄）。'
				: ''
		) );
	}

	/**
	 * 監聽核心 finalization 人工核定，同步核定本外掛的退款 attempt（CODEX 終審 R8-F3）
	 *
	 * 雙 ledger 死結：核心 `_ys_refund_finalization` 與本外掛 `_ys_ecpay_refunds` 各自
	 * 凍結——只解其一，另一邊仍擋住所有新退款（核心 submitting 擋在 gateway 呼叫前；
	 * 本外掛 pending 走全單凍結）。核心 CLI 核定後 fire
	 * `ys_ec_refund_finalization_resolved`，這裡把同 request_id 的 pending attempt 一併
	 * 標為 done（paid）／failed（aborted），達成「一個命令解除兩套 ledger」。
	 */
	public static function register_core_sync(): void {
		add_action( 'ys_ec_refund_finalization_resolved', [ self::class, 'on_core_resolved' ], 10, 4 );
	}

	/**
	 * @param int    $order_id
	 * @param string $request_id
	 * @param string $mark       'paid'｜'aborted'
	 * @param array  $core_entry 核心 ledger entry（僅供除錯）
	 */
	public static function on_core_resolved( int $order_id, string $request_id, string $mark, array $core_entry = [] ): void {
		unset( $core_entry );
		if ( $order_id <= 0 || '' === $request_id || ! in_array( $mark, [ 'paid', 'aborted' ], true ) ) {
			return;
		}

		global $wpdb;
		$table   = YSOrder::table();
		$old_raw = $wpdb->get_var( $wpdb->prepare(
			"SELECT payment_detail FROM {$table} WHERE id = %d",
			$order_id
		) );
		if ( null === $old_raw ) {
			return;
		}

		$detail  = json_decode( (string) $old_raw, true ) ?: [];
		$history = is_array( $detail['_ys_ecpay_refunds'] ?? null ) ? $detail['_ys_ecpay_refunds'] : [];
		$entry   = $history[ $request_id ] ?? null;
		if ( ! is_array( $entry ) || 'pending' !== ( $entry['status'] ?? '' ) ) {
			return; // 無此 attempt 或早已非 pending → 無需同步
		}

		$entry['status']      = ( 'paid' === $mark ) ? 'done' : 'failed';
		$entry['resolved_by'] = 'core-finalization-sync';
		$entry['resolved_at'] = current_time( 'mysql' );

		$history[ $request_id ]      = $entry;
		$detail['_ys_ecpay_refunds'] = $history;

		// 真 CAS（同 resolve）：payment_detail 於期間被改寫則不寫入，避免覆蓋他人變更。
		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET payment_detail = %s WHERE id = %d AND payment_detail = %s",
			wp_json_encode( $detail ),
			$order_id,
			(string) $old_raw
		) );

		if ( 1 !== (int) $updated ) {
			YSLogger::error( 'ecpay', 'CRITICAL: 核心核定同步失敗（CAS 落敗，本外掛 attempt 仍 pending＝全單凍結）', [
				'order_id'   => $order_id,
				'request_id' => $request_id,
				'mark'       => $mark,
			] );
			return;
		}

		YSLogger::warning( 'ecpay', '已依核心核定同步退款 attempt 狀態（解除本外掛全單凍結）', [
			'order_id'   => $order_id,
			'request_id' => $request_id,
			'status'     => $entry['status'],
		] );
	}
}
