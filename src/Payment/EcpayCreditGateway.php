<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Payment;

defined( 'ABSPATH' ) || exit;

use YangSheep\Ecommerce\Models\YSOrder;
use YangSheep\Ecommerce\Utils\YSLogger;

final class EcpayCreditGateway extends EcpayGatewayBase {
	public function get_id(): string {
		return 'ys_ec_ecpay_credit';
	}

	public function get_title(): string {
		return '綠界信用卡';
	}

	protected function gateway_key(): string {
		return 'credit';
	}

	protected function choose_payment(): string {
		return 'Credit';
	}

	/**
	 * 宣告具自動金流退款能力（core v2.56.4 `YSGatewayRegistry::supports_auto_refund`
	 * 的可選方法協定）——後台退款 UI 據此不顯示「訂單退款≠金流退款」警示。
	 */
	public function supports_gateway_refund(): bool {
		return true;
	}

	/**
	 * 信用卡退款（query-first 狀態機，CreditDetail/QueryTrade → DoAction）
	 *
	 * 僅信用卡 gateway 支援；ATM／超商依產品決策走人工退款（base 維持不支援）。
	 * 設計要點（CODEX 終審 F2/F4 修正——依綠界官方流程）：
	 *   1. **query-first**：先取 gwsr（付款紀錄，缺則 QueryTradeInfo 補查）→
	 *      CreditDetail/QueryTrade 查關帳狀態 → 依官方表選動作：
	 *        已授權（authorized）→ N（僅全額；部分明確拒絕）
	 *        要關帳（to_close）＋全額 → E 之後接 N
	 *        要關帳（to_close）＋部分 → R
	 *        已關帳（closed）→ R
	 *        unknown → 拒絕操作（fail-closed，不猜）
	 *   2. **crash-safe 冪等**：以 core `context['refund_request_id']` 為 key；
	 *      done→冪等重放、pending→拒絕盲重送、failed→允許重試。
	 *   3. **傳輸不確定 ≠ 失敗**：DoAction 回 indeterminate（timeout／非 2xx／無 RtnCode）
	 *      → attempt **維持 pending**（綠界端可能已生效，重試可能重複退款）；
	 *      只有 provider 明確拒絕（RtnCode≠1）才標 failed 開放重試。
	 *   4. 識別碼一律取自訂單付款紀錄；金額上限＝total－已退。
	 *
	 * @deferred-live-verification 驗證一律走受控正式商店小額實測（stage DoAction
	 * 官方明載不可用）；gate 清單見 docs/credit-refund-sandbox-gate.md。
	 */
	public function process_refund( int $order_id, float $amount, string $reason = '', array $context = [] ): array {
		unset( $reason );

		// R7-F1：pre-DoAction 業務拒絕（金流確定未動）→ outcome=rejected_terminal
		//         （可安全重試）；字面值＝與 core YSRefundHandler::REFUND_OUTCOME_* 一致。
		$order = YSOrder::find( $order_id );
		if ( ! $order ) {
			return [ 'success' => false, 'outcome' => 'rejected_terminal', 'message' => '訂單不存在。' ];
		}

		// 金額驗證：正數且不得超過可退餘額（fail-closed）。
		$total      = (float) ( $order->total ?? 0 );
		$refunded   = (float) ( $order->refunded_amount ?? 0 );
		$refundable = $total - $refunded;
		if ( $amount <= 0 || round( $amount, 2 ) > round( $refundable, 2 ) ) {
			return [
				'success' => false,
				'outcome' => 'rejected_terminal',
				'message' => sprintf( '退刷金額不正確（可退餘額 %s）。', number_format( max( 0, $refundable ), 2 ) ),
			];
		}

		// 交易識別碼取自訂單付款紀錄（付款回調由 reconciler 寫入），不可信外部輸入。
		$payment_detail    = json_decode( (string) ( $order->payment_detail ?? '{}' ), true ) ?: [];
		$trade_no          = (string) ( $payment_detail['trade_no'] ?? $order->gateway_trade_no ?? '' );
		$merchant_trade_no = (string) ( $payment_detail['mer_trade_no'] ?? '' );
		if ( '' === $trade_no || '' === $merchant_trade_no ) {
			return [ 'success' => false, 'outcome' => 'rejected_terminal', 'message' => '找不到綠界交易識別碼，無法退刷（訂單可能非綠界信用卡付款）。' ];
		}

		// ── crash-safe 冪等（refund_request_id）──
		// v0.3.0（CODEX 終審 F4）：request_id 為冪等防線的 key，**不得為空**——
		// core YSRefundHandler 各路徑皆會提供；外部直呼缺 key 一律拒絕（fail-closed）。
		$request_id = (string) ( $context['refund_request_id'] ?? '' );
		if ( '' === $request_id ) {
			return [ 'success' => false, 'outcome' => 'rejected_terminal', 'message' => '缺少 refund_request_id（冪等鍵），已拒絕退款操作。' ];
		}
		$history = is_array( $payment_detail['_ys_ecpay_refunds'] ?? null ) ? $payment_detail['_ys_ecpay_refunds'] : [];

		if ( '' !== $request_id && isset( $history[ $request_id ] ) ) {
			$entry = $history[ $request_id ];
			if ( 'done' === ( $entry['status'] ?? '' ) ) {
				// 同 request 已成功 → 冪等重放，不重送金流。
				return [
					'success'        => true,
					'transaction_id' => (string) ( $entry['trade_no'] ?? $trade_no ),
					'message'        => '（冪等重放：此退刷請求先前已成功）',
				];
			}
			// pending 由下方「全單凍結」統一擋（不分 request_id）。
			// failed → 允許重試（往下走）。
		}

		// ── 全單凍結（CODEX 終審 F3）：本訂單只要存在**任何**結果未明（pending）的
		// 退款 attempt——不論 request_id 是否相同（涵蓋 UI 逾時後換新 UUID、core pending
		// TTL 過期換號等所有路徑）——一律拒絕任何新的款項操作，直到人工核定把該
		// attempt 標為 done/failed。這是金流層的最後防線，不依賴上游 key 穩定性。
		foreach ( $history as $frozen_id => $frozen_entry ) {
			if ( 'pending' === ( $frozen_entry['status'] ?? '' ) ) {
				// 結果未明的既有 attempt → indeterminate（core 亦凍結，不重送）。
				return [
					'success' => false,
					'outcome' => 'indeterminate',
					'message' => '此訂單有一筆結果未明的退款請求（' . sanitize_text_field( (string) $frozen_id ) . '）凍結中：'
						. '為避免重複退款，已拒絕所有新的退款操作；請先於綠界後台確認該筆實際狀態並人工核定後再試。',
				];
			}
		}

		// v0.3.0（CODEX 終審 F4）：persist 回傳寫入結果——call site 必檢查；
		// 寫失敗＝冪等紀錄與實況脫鉤，一律 CRITICAL log（凍結安全網＝送出前的
		// pending 寫入已成功，後續寫失敗不會解除凍結）。
		$persist = function ( array $entry ) use ( $order_id, $request_id ): bool {
			$fresh  = YSOrder::find( $order_id );
			$detail = json_decode( (string) ( $fresh->payment_detail ?? '{}' ), true ) ?: [];
			$hist   = is_array( $detail['_ys_ecpay_refunds'] ?? null ) ? $detail['_ys_ecpay_refunds'] : [];
			$hist[ $request_id ]          = array_merge( is_array( $hist[ $request_id ] ?? null ) ? $hist[ $request_id ] : [], $entry );
			$detail['_ys_ecpay_refunds']  = $hist;
			return (bool) YSOrder::update( $order_id, [ 'payment_detail' => wp_json_encode( $detail ) ] );
		};

		$client  = new EcpayPaymentClient();
		$is_full = round( $amount, 2 ) >= round( $total, 2 );

		// ── 步驟 1：取得授權單號 gwsr（查詢關帳狀態的 key）──
		// 付款紀錄優先；缺（歷史訂單）→ QueryTradeInfo 補查（回應含 gwsr）並回寫。
		$gwsr = (string) ( $payment_detail['gwsr'] ?? $payment_detail['ecpay_gwsr'] ?? '' );
		if ( '' === $gwsr ) {
			$query = $client->query_trade( $merchant_trade_no );
			$gwsr  = (string) ( $query['data']['gwsr'] ?? '' );
			if ( '' !== $gwsr ) {
				$payment_detail['gwsr'] = $gwsr;
				YSOrder::update( $order_id, [ 'payment_detail' => wp_json_encode( $payment_detail ) ] );
			}
		}
		if ( '' === $gwsr ) {
			// 未送 DoAction＝金流未動 → rejected_terminal（可安全重試/人工）。
			return [ 'success' => false, 'outcome' => 'rejected_terminal', 'message' => '無法取得綠界授權單號（gwsr），無法判定關帳狀態；請於綠界後台人工處理退款。' ];
		}

		// ── 步驟 2：查關帳狀態（query-first；查詢失敗＝未動錢，可安全重試）──
		$close = $client->query_credit_close_status( $gwsr, (int) round( $total ) );
		if ( 'unknown' === ( $close['state'] ?? 'unknown' ) ) {
			// query-first：關帳狀態查詢失敗＝未送 DoAction、金流未動 → rejected_terminal。
			return [
				'success' => false,
				'outcome' => 'rejected_terminal',
				'message' => '無法確認交易關帳狀態（' . (string) ( $close['message'] ?? '' ) . '），已中止退款操作；請稍後重試或於綠界後台人工處理。',
			];
		}

		// ── 步驟 3：依官方狀態機決定動作序列 ──
		switch ( $close['state'] ) {
			case 'authorized':
			case 'cancelled': // 操作取消 → 依官方流程執行 N（僅全額）
				if ( ! $is_full ) {
					return [ 'success' => false, 'outcome' => 'rejected_terminal', 'message' => '此交易尚未請款（已授權／前次操作已取消），僅支援全額取消授權；請以全額退款處理。' ];
				}
				$plan = [ 'N' ];
				break;
			case 'to_close':
				$plan = $is_full ? [ 'E', 'N' ] : [ 'R' ];
				break;
			case 'closed':
			default:
				$plan = [ 'R' ];
				break;
		}

		// ── 步驟 4：送出前持久化 pending（此後 crash／不確定都拒絕盲重送）──
		// 🔴 寫入失敗＝冪等防線不存在 → 一律中止、不得送金流（CODEX 終審 F3）。
		if ( '' !== $request_id ) {
			$history[ $request_id ] = [
				'status' => 'pending',
				'amount' => $amount,
				'state'  => (string) $close['state'],
				'plan'   => implode( ',', $plan ),
				'time'   => current_time( 'mysql' ),
			];
			$payment_detail['_ys_ecpay_refunds'] = $history;
			$persisted = YSOrder::update( $order_id, [ 'payment_detail' => wp_json_encode( $payment_detail ) ] );
			if ( ! $persisted ) {
				return [
					'success' => false,
					'outcome' => 'rejected_terminal',
					'message' => '退款請求無法持久化（冪等防線寫入失敗），已中止；未執行任何金流操作，請重試。',
				];
			}
		}

		// ── 步驟 5：依序執行動作；傳輸不確定＝維持 pending（禁重送），明確拒絕才標 failed ──
		$executed = [];
		$result   = [ 'success' => false, 'indeterminate' => false, 'data' => null, 'message' => '' ];
		foreach ( $plan as $action ) {
			$result     = $client->do_action_refund( $merchant_trade_no, $trade_no, $amount, $action );
			$executed[] = $action;

			if ( ! empty( $result['indeterminate'] ) ) {
				// 傳輸不確定：綠界端可能已生效——attempt 維持 pending，等人工核定。
				if ( ! $persist( [ 'executed' => implode( ',', $executed ), 'note' => '結果未明（' . (string) ( $result['message'] ?? '' ) . '）' ] ) ) {
					YSLogger::error( 'ecpay', 'CRITICAL: indeterminate note persist failed（凍結仍由送出前 pending 保證）', [
						'order_id'   => $order_id,
						'request_id' => $request_id,
					] );
				}
				// R7-F1：DoAction 傳輸不確定 → indeterminate（core 維持凍結、禁重送）。
				return [
					'success' => false,
					'outcome' => 'indeterminate',
					'message' => '退款請求結果未明（傳輸中斷），為避免重複退款已凍結此請求；請先於綠界後台確認實際狀態，再人工核定。',
				];
			}

			if ( empty( $result['success'] ) ) {
				// provider 明確拒絕：可安全重試。
				if ( ! $persist( [
					'status'   => 'failed',
					'executed' => implode( ',', $executed ),
					'rtn_code' => (string) ( $result['data']['RtnCode'] ?? '' ),
					'rtn_msg'  => (string) ( $result['message'] ?? '' ),
				] ) ) {
					YSLogger::error( 'ecpay', 'CRITICAL: failed-status persist failed（attempt 停留 pending＝凍結，需人工核定）', [
						'order_id'   => $order_id,
						'request_id' => $request_id,
					] );
				}
				// R7-F1：DoAction 明確拒絕（RtnCode≠1，金流未動）→ rejected_terminal（可重試）。
				return [
					'success' => false,
					'outcome' => 'rejected_terminal',
					'message' => '綠界退款失敗（動作 ' . $action . '）：' . (string) ( $result['message'] ?? '未知錯誤' ),
				];
			}
		}

		$done_trade_no = (string) ( $result['data']['TradeNo'] ?? $trade_no );
		$done_note     = '';
		if ( ! $persist( [
			'status'   => 'done',
			'executed' => implode( ',', $executed ),
			'trade_no' => $done_trade_no,
		] ) ) {
			// 金流已退成功但 done 標記寫失敗：attempt 停留 pending＝本單凍結——
			// CRITICAL log＋訊息導引 CLI 核定（wp ys-ecpay refund-attempts resolve）。
			YSLogger::error( 'ecpay', 'CRITICAL: done-status persist failed（本單退款凍結，需 CLI 核定）', [
				'order_id'   => $order_id,
				'request_id' => $request_id,
				'trade_no'   => $done_trade_no,
			] );
			$done_note = '（⚠ 冪等紀錄寫入失敗：本單後續退款已凍結，請以 wp ys-ecpay refund-attempts resolve 核定）';
		}

		return [
			'success'        => true,
			'transaction_id' => $done_trade_no,
			'message'        => ( 'authorized' === $close['state']
				? '（未請款交易，以取消授權方式全額退款）'
				: ( [ 'E', 'N' ] === $plan ? '（要關帳交易，已取消關帳並取消授權全額退款）' : '' ) ) . $done_note,
		];
	}
}
