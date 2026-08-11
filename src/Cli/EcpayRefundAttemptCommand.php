<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Cli;

defined( 'ABSPATH' ) || exit;

use YangSheep\Ecommerce\Models\YSOrder;
use YangSheep\Ecommerce\Utils\YSLogger;
use YangSheep\YSCartEcpay\Support\OrderPaymentDetail;

/**
 * 退款 attempt 人工核定 CLI（v0.3.0，CODEX 終審 F4）
 *
 * 這個命令**只讀不寫**。
 *
 *   wp ys-ecpay refund-attempts list    --order=<id>
 *   wp ys-ecpay refund-attempts resolve --order=<id> --request=<id> --mark=done|failed
 *
 * `resolve` 是導引，不是權威：它顯示 attempt 現況後，把操作者導向核心 CLI。
 * 人工復原只有一個入口——核心核定會 fire `ys_ec_refund_finalization_sync`，
 * 本外掛的 listener 在同一個 CAS 內完成 gateway／金額／指紋三道核對後同步
 * attempt。兩套 ledger 一次解除，核對不會被跳過，指紋也不會被人工改寫。
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
	 * 把人工核定導向**唯一的**復原入口：核心 CLI（v0.3.0）
	 *
	 * 🔴 這個子命令不再自行改動退款帳本。
	 *
	 * 先前它是第二套權威：直接把 attempt 從 pending 改成 done／failed，還接受
	 * `--trade-no` 覆寫——那個欄位是**交易指紋**的一部分，改掉它等於讓之後所有
	 * fingerprint 比對失去意義。更根本的問題是「兩套帳本、兩個入口」：核心的
	 * `_ys_refund_finalization` 與本外掛的 `_ys_ecpay_refunds` 各自凍結，只解其一
	 * 另一邊仍擋著；運維得記住要跑兩個命令、還要記得順序。
	 *
	 * 現在只有一條路：核心 CLI 核定 → fire `ys_ec_refund_finalization_sync` →
	 * 本外掛的 listener（`on_core_resolved`）在同一個 CAS 內完成 gateway／金額／
	 * 指紋三道核對後同步 attempt。一個命令解除兩套 ledger，而且核對不會被跳過。
	 *
	 * @subcommand resolve
	 * @synopsis --order=<id> --request=<request_id> --mark=<done|failed>
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
		if ( $order_id <= 0 ) {
			\WP_CLI::error( '--order 必須為正整數。' );
		}

		if ( array_key_exists( 'trade-no', $assoc ) ) {
			\WP_CLI::error(
				'--trade-no 已移除：交易編號是退款指紋的一部分，人工覆寫會讓之後所有指紋比對失去意義。'
					. '綠界端的實際交易編號請以綠界後台查詢結果為準。'
			);
		}

		// 唯讀確認：讓操作者在轉向核心命令之前先看到這筆 attempt 的現況。
		$detail = OrderPaymentDetail::read( $order_id );
		if ( null === $detail ) {
			\WP_CLI::error( "無法讀取訂單 {$order_id} 的付款紀錄（訂單不存在或欄位損壞）。" );
		}

		$entry = is_array( $detail['_ys_ecpay_refunds'][ $request_id ] ?? null )
			? $detail['_ys_ecpay_refunds'][ $request_id ]
			: null;

		if ( null === $entry ) {
			\WP_CLI::error( "attempt {$request_id} 不存在。" );
		}

		$status = (string) ( $entry['status'] ?? '?' );
		if ( 'pending' !== $status ) {
			\WP_CLI::error( sprintf(
				'attempt %s 目前狀態為 %s（非 pending）——可能已被核心核定同步過，請重新 list 確認。',
				$request_id,
				$status
			) );
		}

		\WP_CLI::log( sprintf(
			'attempt %s：金額 %s、執行 %s、指紋 trade_no=%s／gwsr=%s',
			$request_id,
			(string) ( $entry['amount'] ?? '?' ),
			(string) ( $entry['executed'] ?? $entry['plan'] ?? '-' ),
			(string) ( $entry['trade_no'] ?? '-' ),
			(string) ( $entry['gwsr'] ?? '-' )
		) );

		\WP_CLI::error(
			'本命令不再自行核定 —— 人工復原只有一個入口，請執行：' . PHP_EOL . PHP_EOL
				. '  wp ys-cart refund-finalization resolve'
				. ' --order=' . $order_id
				. ' --request=' . $request_id
				. ' --mark=' . ( 'done' === $mark ? 'paid' : 'aborted' ) . PHP_EOL . PHP_EOL
				. '它會續作核心帳務，並在同一次操作內同步解除本外掛的退款凍結'
				. '（gateway／金額／交易指紋三道核對會在同一個 CAS 內重跑）。'
		);
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
		// R9-F3：改用 **filter** 回報 typed 結果——CAS 失敗時 core 必須能把「本外掛
		// ledger 尚未解除」透傳給操作者；舊版單向 action 只在本地記 CRITICAL，CLI 仍
		// 顯示成功，運維會誤以為兩套 ledger 都已解除。
		add_filter( 'ys_ec_refund_finalization_sync', [ self::class, 'on_core_resolved' ], 10, 5 );

		// R10-F4：向 core 宣告「ECPay 信用卡退款有 provider ledger、核定必須同步」——
		// 若本外掛未載入（listener 缺席），core 依此宣告把「零回報」判為同步失敗，
		// 不得被當成功（否則本外掛的全單凍結會被遺忘）。
		add_filter( 'ys_ec_refund_finalization_requires_sync', [ self::class, 'declare_requires_sync' ], 10, 2 );

		// R13-F4：宣告同時寫入 core 的 durable 登記表（option、本外掛停用後仍在）——
		// legacy core entry（缺 pre-send 判定值）據此 fail-closed，runtime filter 缺席
		// 不再被誤判成「無需同步」。register_sync_provider 冪等（已登記即 return）。
		if ( class_exists( '\YangSheep\Ecommerce\Handlers\YSRefundHandler' )
			&& method_exists( '\YangSheep\Ecommerce\Handlers\YSRefundHandler', 'register_sync_provider' ) ) {
			\YangSheep\Ecommerce\Handlers\YSRefundHandler::register_sync_provider( self::GATEWAY_ID );
		}
	}

	/**
	 * @param bool   $requires   其他 filter 已宣告的結果
	 * @param string $gateway_id core ledger entry 記錄的 gateway
	 */
	public static function declare_requires_sync( bool $requires, string $gateway_id ): bool {
		return $requires || 'ys_ec_ecpay_credit' === $gateway_id;
	}

	/**
	 * @param array<int, array> $results    其他 provider 已回報的同步結果
	 * @param int               $order_id
	 * @param string            $request_id
	 * @param string            $mark       'paid'｜'aborted'
	 * @param array             $core_entry 核心 ledger entry（僅供除錯）
	 * @return array<int, array> 附加本外掛的 typed result（provider／success／message）
	 */
	/** 本外掛的 gateway id（core sync 結果匹配 owner 用——R12-F4）。 */
	private const GATEWAY_ID = 'ys_ec_ecpay_credit';

	public static function on_core_resolved( array $results, int $order_id, string $request_id, string $mark, array $core_entry = [] ): array {
		if ( $order_id <= 0 || '' === $request_id || ! in_array( $mark, [ 'paid', 'aborted' ], true ) ) {
			return $results;
		}

		$target = ( 'paid' === $mark ) ? 'done' : 'failed';

		// 🔴 v0.3.0：所有驗證與寫入都在**同一個 CAS closure** 內完成。
		//
		// 舊版是「先用一份快照跑完 gateway／金額／fingerprint 三道核對，再於稍後
		// 寫入」。那三道核對讀的是舊值：期間若有人改動 attempt 或訂單的授權資訊，
		// 我們仍會以通過核對的姿態寫下終態——核對因此只是形式。
		// 現在 CAS 落敗重讀後，整段核對會用最新值重跑一次。
		$core_gateway = (string) ( $core_entry['gateway_id'] ?? '' );
		$core_amount  = isset( $core_entry['amount'] ) ? (float) $core_entry['amount'] : null;

		$sync = OrderPaymentDetail::mutate(
			$order_id,
			static function ( array $fresh, int $attempt, &$decision ) use ( $request_id, $target, $core_gateway, $core_amount ): ?array {
				$ledger  = is_array( $fresh['_ys_ecpay_refunds'] ?? null ) ? $fresh['_ys_ecpay_refunds'] : [];
				$current = is_array( $ledger[ $request_id ] ?? null ) ? $ledger[ $request_id ] : null;

				if ( null === $current ) {
					$decision = [ 'action' => 'missing' ];
					return null; // 非本外掛的退款 → 無需同步、無需回報
				}

				// (1) gateway 歸屬：core entry 必須明確屬於本外掛。
				if ( self::GATEWAY_ID !== $core_gateway ) {
					$decision = [ 'action' => 'gateway_mismatch', 'gateway' => $core_gateway ];
					return null;
				}

				// (2) 金額：兩邊都必須有值且一致（±0.005）。任一邊缺＝無法核對＝不放行。
				$our_amount = isset( $current['amount'] ) ? (float) $current['amount'] : null;
				if ( null === $core_amount || null === $our_amount ) {
					$decision = [ 'action' => 'amount_unverifiable' ];
					return null;
				}
				if ( abs( $core_amount - $our_amount ) > 0.005 ) {
					$decision = [ 'action' => 'amount_mismatch', 'core' => $core_amount, 'ours' => $our_amount ];
					return null;
				}

				// (3) 原交易 fingerprint：attempt 保存的識別碼必須與**當下**訂單的授權
				// 資訊一致。legacy attempt（v0.3.0 之前建立）缺這些欄位——那不是「跳過
				// 檢查」的理由，而是「無法證明是同一筆」，一律不自動同步。
				$order_fp = [
					'trade_no'          => (string) ( $fresh['trade_no'] ?? '' ),
					'merchant_trade_no' => (string) ( $fresh['mer_trade_no'] ?? '' ),
					'gwsr'              => (string) ( $fresh['gwsr'] ?? $fresh['ecpay_gwsr'] ?? '' ),
				];
				foreach ( $order_fp as $field => $order_value ) {
					$attempt_value = (string) ( $current[ $field ] ?? '' );
					if ( '' === $attempt_value || '' === $order_value ) {
						$decision = [ 'action' => 'fingerprint_unverifiable', 'field' => $field ];
						return null;
					}
					if ( $attempt_value !== $order_value ) {
						$decision = [
							'action'  => 'fingerprint_mismatch',
							'field'   => $field,
							'attempt' => $attempt_value,
							'order'   => $order_value,
						];
						return null;
					}
				}

				// (4) 狀態仲裁
				$status = (string) ( $current['status'] ?? '' );
				if ( 'pending' !== $status ) {
					$decision = [ 'action' => $status === $target ? 'already' : 'conflict', 'status' => $status ];
					return null;
				}

				$current['status']      = $target;
				$current['resolved_by'] = 'core-finalization-sync';
				$current['resolved_at'] = current_time( 'mysql' );

				$ledger[ $request_id ]      = $current;
				$fresh['_ys_ecpay_refunds'] = $ledger;
				$decision                   = [ 'action' => 'synced' ];

				return $fresh;
			}
		);

		$sync_decision = $sync->get_decision();
		$sync_action   = is_array( $sync_decision ) ? (string) ( $sync_decision['action'] ?? '' ) : '';
		$manual        = '——請人工核對後重新執行 wp ys-cart refund-finalization resolve';

		if ( 'missing' === $sync_action ) {
			return $results; // 無此 attempt → 不回報
		}

		$refusals = [
			'gateway_mismatch'         => sprintf(
				'core entry 的 gateway（%s）非本外掛——request_id 撞號或 legacy entry 缺 gateway_id%s',
				'' !== $core_gateway ? $core_gateway : '缺',
				$manual
			),
			'amount_unverifiable'      => 'attempt 金額無法核對（core 或 ecpay entry 缺 amount）' . $manual,
			'amount_mismatch'          => 'attempt 金額不符——疑錯單' . $manual,
			'fingerprint_unverifiable' => 'attempt 交易 fingerprint 無法核對（legacy attempt 或訂單缺識別碼）' . $manual,
			'fingerprint_mismatch'     => 'attempt 交易 fingerprint 不符——疑錯單' . $manual,
			'conflict'                 => sprintf(
				'attempt 已為 %s，與核心核定（%s）衝突——請人工核對兩邊紀錄',
				is_array( $sync_decision ) ? (string) ( $sync_decision['status'] ?? '?' ) : '?',
				$target
			),
		];

		if ( isset( $refusals[ $sync_action ] ) ) {
			$results[] = [
				'provider'   => 'ecpay',
				'gateway_id' => self::GATEWAY_ID,
				'success'    => false,
				'message'    => $refusals[ $sync_action ],
			];
			return $results;
		}

		if ( 'already' === $sync_action ) {
			$results[] = [
				'provider'   => 'ecpay',
				'gateway_id' => self::GATEWAY_ID,
				'success'    => true,
				'message'    => '已同步為 ' . $target . '（冪等）',
			];
			return $results;
		}


		if ( ! $sync->is_persisted() || 'synced' !== $sync_action ) {
			YSLogger::error( 'ecpay', 'CRITICAL: 核心核定同步失敗（CAS 落敗，本外掛 attempt 仍 pending＝全單凍結）', [
				'order_id'   => $order_id,
				'request_id' => $request_id,
				'mark'       => $mark,
			] );
			// R9-F3：回報失敗給 core，讓 CLI 明確顯示「核心已核定，但本外掛未解除凍結」
			// 並附上手動補救指令。
			$results[] = [
				'provider'   => 'ecpay',
				'gateway_id' => self::GATEWAY_ID,
				'success'    => false,
				'message'    => 'CAS 落敗（payment_detail 於期間被改寫），退款 attempt 仍為 pending；請重新執行'
					. ' wp ys-cart refund-finalization resolve --order=' . $order_id
					. ' --request=' . $request_id
					. ' --mark=' . $mark,
			];
			return $results;
		}

		YSLogger::warning( 'ecpay', '已依核心核定同步退款 attempt 狀態（解除本外掛全單凍結）', [
			'order_id'   => $order_id,
			'request_id' => $request_id,
			'status'     => $target,
		] );

		$results[] = [
			'provider'   => 'ecpay',
			'gateway_id' => self::GATEWAY_ID,
			'success'    => true,
			'message'    => '已同步為 ' . $target,
		];

		return $results;
	}
}
